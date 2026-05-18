# Easy OIDC — minimal Joomla SSO plugin

A small Joomla system plugin that authenticates users against an OpenID Connect
identity provider (designed for Keycloak, but standards-compliant so it works
with anything that publishes `.well-known/openid-configuration`).

Originally built for [mälarscouterna.se](https://malarscouterna.se) as a cheap,
low-maintenance alternative to the miniOrange "Joomla SSO OAuth OIDC" plugin.
Designed to run on **Joomla 5** today and stay compatible with **Joomla 6**
(namespaced plugin, event-subscriber pattern, no deprecated APIs).

## What it does

- OIDC authorization-code flow with **PKCE**.
- Matches the IdP user to a Joomla user by **username** (claim
  `preferred_username`) or **email** — configurable. Defaults to username.
- Two provisioning modes — **match-only** (reject unknown users, the safe
  default) or **auto-create** (provision new Joomla users from claims).
- **RP-initiated single logout** — logging out of Joomla also signs the user out
  of the IdP.
- All configuration via standard Joomla plugin parameters.

## What it deliberately does *not* do

- No login button module. You build your own button as a plain HTML link
  pointing at `/index.php?option=easyoidc&task=login`.
- No group/role mapping from Keycloak claims (yet). Auto-created users get the
  configured default group.
- No multi-IdP support. One plugin, one IdP.
- No back-channel logout, no token refresh, no introspection. The plugin is for
  authentication only; we don't keep an OIDC session after login.

## Installing

1. Download the latest release `plg_system_easyoidc-x.y.z.zip` from
   [GitHub Releases](https://github.com/magnushasselquist/joomla-easy-oidc/releases)
   (or run `./build.sh` from the repo).
2. In Joomla admin → **System → Install → Extensions** → upload the zip.
3. Enable **System - Easy OIDC** under **System → Plugins**.

Once installed, Joomla's built-in extension updater will pick up new releases
automatically (the plugin manifest declares an update server).

## Configuring

In Joomla admin → **System → Plugins → System - Easy OIDC**:

| Setting | What to put |
| --- | --- |
| Issuer URL | e.g. `https://dev.id.scouterna.se/realms/scouterna` |
| Client ID | From your Keycloak client |
| Client Secret | From your Keycloak client (confidential clients only) |
| Match field | `Username` (matches `preferred_username`) or `Email` |
| Provisioning | `Match only` (reject unknown) or `Auto-create` |
| Single Logout | Yes (recommended) |

**Keycloak client setup:**

- *Client authentication*: On (confidential) or Off (public + PKCE).
- *Valid redirect URIs*: `https://your-joomla-site.example/index.php?option=easyoidc&task=callback`
- *Valid post-logout redirect URIs*: `https://your-joomla-site.example/` (or whatever you
  set in "Post-logout URL")
- Scopes: `openid`, `profile`, `email`.

## Usage — building your login button

Anywhere in your Joomla content, module, or template, add a link:

```html
<a class="btn btn-primary" href="/index.php?option=easyoidc&task=login">
    Sign in with Scouterna ID
</a>
```

Optionally pass a `return` query parameter to redirect the user back to a
specific page after login:

```html
<a href="/index.php?option=easyoidc&task=login&return=/medlemmar">
    Sign in
</a>
```

Logout link:

```html
<a href="/index.php?option=easyoidc&task=logout">Sign out</a>
```

This triggers RP-initiated single logout when enabled; otherwise just a normal
Joomla logout.

## URLs the plugin handles

| URL | Purpose |
| --- | --- |
| `/index.php?option=easyoidc&task=login` | Start auth code + PKCE flow, redirect to IdP. Honors `?return=<safe-relative-url>`. |
| `/index.php?option=easyoidc&task=callback` | OIDC redirect URI. Validates the ID token, matches/creates user, signs them into Joomla. |
| `/index.php?option=easyoidc&task=logout` | Joomla logout, then RP-initiated logout at the IdP if enabled. |

## Building the package zip

Requirements: PHP 8.1+, `php composer.phar` (one comes bundled at the repo root).

```sh
./build.sh
# → ./dist/plg_system_easyoidc-1.0.0.zip
```

The script regenerates `vendor/` and zips up the installable plugin.

## Troubleshooting

- Enable **Debug log** in plugin params; failures go to
  `administrator/logs/easyoidc.log`.
- "No matching Joomla account was found" — provisioning is `match_only` and no
  Joomla user matches the IdP claim. Either create the Joomla user with the
  matching username/email, or flip provisioning to `auto-create`.
- "Unable to determine state" from the OIDC library typically means the Joomla
  session got reset between `task=login` and `task=callback`. Check that
  cookies are not being blocked and that the site is served over HTTPS in
  production.

## Forward compatibility

Joomla 6 requires the namespaced plugin pattern; we already use it. Manifest
declares `<minimumPhp>8.1</minimumPhp>` and only uses public, non-deprecated
APIs (`CMSApplicationInterface`, `Joomla\Event\SubscriberInterface`,
`Joomla\Database\DatabaseInterface`, `Joomla\CMS\User\UserHelper`,
`Joomla\CMS\User\User`).

## License

GPL-2.0-or-later (matching Joomla's license).
