<?php
/**
 * @package     plg_system_hqoidc
 * @copyright   (C) 2026 Magnus Hasselquist
 * @license     GPL-2.0-or-later
 */

namespace Joomla\Plugin\System\HqOidc\Extension;

\defined('_JEXEC') or die;

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\SubscriberInterface;

/**
 * HQ OIDC system plugin.
 *
 * Handles OIDC authentication against an external IdP (designed for Keycloak)
 * via three custom URLs:
 *   index.php?option=hqoidc&task=login
 *   index.php?option=hqoidc&task=callback
 *   index.php?option=hqoidc&task=logout
 */
final class HqOidc extends CMSPlugin implements SubscriberInterface
{
    private const SESSION_RETURN   = 'hqoidc.return';
    private const SESSION_ID_TOKEN = 'hqoidc.id_token';

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute' => 'onAfterRoute',
            'onUserLogout' => 'onUserLogout',
        ];
    }

    public function onAfterRoute($event = null): void
    {
        $app = $this->getApplication();

        if (!$app instanceof CMSApplicationInterface) {
            return;
        }

        if ($app->getInput()->getCmd('option') !== 'hqoidc') {
            return;
        }

        $this->ensureVendorAutoload();

        $task = $app->getInput()->getCmd('task');

        try {
            switch ($task) {
                case 'login':
                    $this->startLogin($app);
                    break;
                case 'callback':
                    $this->handleCallback($app);
                    break;
                case 'logout':
                    $this->handleLogout($app);
                    break;
                default:
                    $app->enqueueMessage(Text::_('PLG_SYSTEM_HQOIDC_ERR_UNKNOWN_TASK'), 'error');
                    $app->redirect(Uri::root());
            }
        } catch (\Throwable $e) {
            $this->log('OIDC failure: ' . $e->getMessage(), Log::ERROR);
            $app->enqueueMessage(Text::_('PLG_SYSTEM_HQOIDC_ERR_SIGN_IN_FAILED'), 'error');
            $app->redirect(Uri::root());
        }
    }

    /**
     * Joomla-side logout hook. When single_logout is on and we have a stored
     * id_token, destroy the Joomla session and redirect to Keycloak's end_session.
     *
     * This fires for both our task=logout flow and the normal Joomla logout button,
     * so single sign-out works regardless of where the user clicks logout.
     */
    public function onUserLogout($event = null): bool
    {
        $app = $this->getApplication();

        if (!$app instanceof CMSApplicationInterface) {
            return true;
        }

        if ((int) $this->params->get('single_logout', 1) !== 1) {
            return true;
        }

        $session = $app->getSession();
        $idToken = $session->get(self::SESSION_ID_TOKEN);

        if (!$idToken) {
            return true;
        }

        // Destroy the local Joomla session before bouncing to Keycloak so the
        // user is also fully logged out locally. (signOut() below will
        // header() + exit and skip Joomla's normal session teardown.)
        try {
            $session->destroy();
        } catch (\Throwable $e) {
            // Best-effort; continue with the redirect anyway.
        }

        try {
            $this->ensureVendorAutoload();
            $client = $this->buildClient($app);
            $client->signOut(
                (string) $idToken,
                $this->absoluteUrl($this->params->get('post_logout_url', '/') ?: '/')
            );
            // signOut() does header() + exit. Unreachable below.
        } catch (\Throwable $e) {
            $this->log('Single-logout failure: ' . $e->getMessage(), Log::WARNING);
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Flow handlers
    // -----------------------------------------------------------------------

    private function startLogin(CMSApplicationInterface $app): void
    {
        $return = $app->getInput()->getString('return');

        if ($return !== null && $return !== '') {
            $decoded = base64_decode($return, true);
            $candidate = $decoded !== false ? $decoded : $return;

            if ($this->isSafeReturnUrl($candidate)) {
                $app->getSession()->set(self::SESSION_RETURN, $candidate);
            }
        }

        $client = $this->buildClient($app);
        $client->authenticate();
        // unreachable: authenticate() either redirects (exit) or throws.
    }

    private function handleCallback(CMSApplicationInterface $app): void
    {
        $client = $this->buildClient($app);

        if (!$client->authenticate()) {
            throw new OpenIDConnectClientException('Authentication did not complete');
        }

        $claims = $client->getVerifiedClaims();

        $matchField   = $this->params->get('match_field', 'username');
        $usernameAttr = $this->params->get('claim_username', 'preferred_username');
        $emailAttr    = $this->params->get('claim_email', 'email');
        $nameAttr     = $this->params->get('claim_name', 'name');

        $username = $this->claim($claims, $usernameAttr);
        $email    = $this->claim($claims, $emailAttr);
        $name     = $this->claim($claims, $nameAttr) ?: $username ?: $email;

        if ($matchField === 'email') {
            if (!$email) {
                throw new OpenIDConnectClientException('IdP did not return an email claim');
            }
            $userId = $this->findUserIdByEmail($email);
        } else {
            if (!$username) {
                throw new OpenIDConnectClientException('IdP did not return a username claim');
            }
            $userId = UserHelper::getUserId($username);
        }

        if (!$userId) {
            $userId = $this->maybeAutoCreate($username, $email, $name);
        }

        if (!$userId) {
            $this->log(sprintf(
                'Match-only: no Joomla user found for match_field=%s claim_username=%s username=%s email=%s. Claim keys seen: %s',
                $matchField,
                $usernameAttr,
                $username ?? '(null)',
                $email ?? '(null)',
                implode(',', $this->claimKeys($claims))
            ), Log::WARNING);

            $app->enqueueMessage(Text::_('PLG_SYSTEM_HQOIDC_ERR_USER_NOT_PROVISIONED'), 'warning');
            $app->redirect(Uri::root());

            return;
        }

        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);

        if ($user->block) {
            $this->log('Blocked user attempted OIDC login: id=' . (int) $user->id . ' username=' . $user->username, Log::WARNING);
            $app->enqueueMessage(Text::_('PLG_SYSTEM_HQOIDC_ERR_USER_BLOCKED'), 'error');
            $app->redirect(Uri::root());

            return;
        }

        // Stash the id_token so onUserLogout can do RP-initiated logout later.
        $app->getSession()->set(self::SESSION_ID_TOKEN, $client->getIdToken());

        $options = [
            'action'       => 'core.login.site',
            'remember'     => true,
            'silent'       => true,
            'responseType' => 'hqoidc',
            'autoregister' => false,
        ];

        // We bypass $app->login() (which runs the authentication plugin chain and
        // would reject us for not supplying a password). Instead we replicate the
        // post-authentication portion: import user plugins and trigger onUserLogin,
        // which plg_user_joomla handles by establishing the Joomla session.
        $response                 = new \stdClass();
        $response->status         = Authentication::STATUS_SUCCESS;
        $response->type           = 'hqoidc';
        $response->username       = $user->username;
        $response->email          = $user->email;
        $response->fullname       = $user->name;
        $response->password_clear = '';

        PluginHelper::importPlugin('user');

        $results = $app->triggerEvent('onUserLogin', [(array) $response, $options]);

        if (in_array(false, $results, true)) {
            throw new \RuntimeException('A user plugin denied the OIDC login');
        }

        $app->triggerEvent('onUserAfterLogin', [$options]);

        $session = $app->getSession();
        $returnUrl = $session->get(self::SESSION_RETURN);
        $session->set(self::SESSION_RETURN, null);

        if (!$returnUrl || !$this->isSafeReturnUrl($returnUrl)) {
            $returnUrl = $this->params->get('post_login_url', '/') ?: '/';
        }

        $app->redirect($this->absoluteUrl($returnUrl));
    }

    private function handleLogout(CMSApplicationInterface $app): void
    {
        $user = $app->getIdentity();

        if ($user && !$user->guest) {
            // Triggers onUserLogout which, if single_logout is on, redirects to Keycloak.
            $app->logout();
        }

        // Fallthrough: no identity, or single_logout disabled, or no id_token stored.
        $app->redirect($this->absoluteUrl($this->params->get('post_logout_url', '/') ?: '/'));
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function buildClient(CMSApplicationInterface $app): OpenIDConnectClient
    {
        $issuer       = rtrim((string) $this->params->get('issuer_url', ''), '/');
        $clientId     = (string) $this->params->get('client_id', '');
        $clientSecret = (string) $this->params->get('client_secret', '');
        $scopes       = (string) $this->params->get('scopes', 'openid profile email');

        if ($issuer === '' || $clientId === '') {
            throw new OpenIDConnectClientException('HQ OIDC is not configured (issuer_url and client_id required)');
        }

        $client = new OpenIDConnectClient($issuer, $clientId, $clientSecret ?: null);
        $client->setRedirectURL($this->callbackUrl());

        $scopeList = array_values(array_filter(preg_split('/\s+/', $scopes) ?: []));
        if ($scopeList) {
            $client->addScope($scopeList);
        }

        // Enable PKCE (S256). Confidential clients still benefit from PKCE.
        $client->setCodeChallengeMethod('S256');

        return $client;
    }

    private function callbackUrl(): string
    {
        return rtrim(Uri::root(), '/') . '/index.php?option=hqoidc&task=callback';
    }

    private function claim(array|object|null $claims, string $key): ?string
    {
        if ($claims === null || $key === '') {
            return null;
        }

        $value = is_array($claims) ? ($claims[$key] ?? null) : ($claims->{$key} ?? null);

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Return the top-level claim keys we received, for diagnostics. Never logs values.
     */
    private function claimKeys(array|object|null $claims): array
    {
        if ($claims === null) {
            return [];
        }

        return is_array($claims) ? array_keys($claims) : array_keys((array) $claims);
    }

    private function findUserIdByEmail(string $email): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' = :email')
            ->bind(':email', $email)
            ->setLimit(1);

        return (int) $db->setQuery($q)->loadResult();
    }

    private function maybeAutoCreate(?string $username, ?string $email, ?string $name): int
    {
        if ($this->params->get('provisioning', 'match_only') !== 'auto_create') {
            return 0;
        }

        if (!$username && !$email) {
            return 0;
        }

        // Fall back to email-as-username if no username claim available.
        $finalUsername = $username ?: $email;
        if (!$email) {
            return 0;
        }

        $defaultGroup = (int) $this->params->get('default_user_group', 2);
        if ($defaultGroup <= 0) {
            $defaultGroup = 2;
        }

        $data = [
            'name'      => $name ?: $finalUsername,
            'username'  => $finalUsername,
            'email'     => $email,
            'password'  => UserHelper::genRandomPassword(32),
            'password2' => null,
            'block'     => 0,
            'sendEmail' => 0,
            'groups'    => [$defaultGroup],
            'registerDate' => Factory::getDate()->toSql(),
        ];
        $data['password2'] = $data['password'];

        $user = new User();
        if (!$user->bind($data)) {
            throw new \RuntimeException('User bind failed: ' . $user->getError());
        }
        if (!$user->save()) {
            throw new \RuntimeException('User save failed: ' . $user->getError());
        }

        return (int) $user->id;
    }

    private function isSafeReturnUrl(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        // Same-origin or relative paths only.
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $host    = parse_url($url, PHP_URL_HOST);
        $rootHost = parse_url(Uri::root(), PHP_URL_HOST);

        return $host !== null && $rootHost !== null && strcasecmp($host, $rootHost) === 0;
    }

    private function absoluteUrl(string $url): string
    {
        if ($url === '' || $url === '/') {
            return Uri::root();
        }

        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim(Uri::root(), '/') . '/' . ltrim($url, '/');
    }

    private function ensureVendorAutoload(): void
    {
        if (class_exists(OpenIDConnectClient::class, false)) {
            return;
        }

        $autoload = __DIR__ . '/../../vendor/autoload.php';

        if (!is_file($autoload)) {
            throw new \RuntimeException('HQ OIDC vendor/ is missing — reinstall the plugin package');
        }

        require_once $autoload;
    }

    private function log(string $message, int $priority = Log::INFO): void
    {
        if ((int) $this->params->get('debug_log', 0) !== 1 && $priority < Log::WARNING) {
            return;
        }

        static $registered = false;
        if (!$registered) {
            Log::addLogger(
                ['text_file' => 'hqoidc.log'],
                Log::ALL,
                ['plg_system_hqoidc']
            );
            $registered = true;
        }

        Log::add($message, $priority, 'plg_system_hqoidc');
    }
}
