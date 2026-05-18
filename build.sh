#!/usr/bin/env bash
# Build the installable Joomla zip for plg_system_easyoidc.
#
# Usage: ./build.sh
# Output: ./dist/plg_system_easyoidc-<version>.zip

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="${ROOT}/plg_system_easyoidc"
DIST_DIR="${ROOT}/dist"
COMPOSER="${ROOT}/composer.phar"

if [[ ! -f "${COMPOSER}" ]]; then
    echo "composer.phar not found at ${COMPOSER}" >&2
    echo "Fetch it with: php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php --install-dir=. && rm composer-setup.php" >&2
    exit 1
fi

# Read version from manifest.
VERSION="$(grep -oE '<version>[^<]+</version>' "${PLUGIN_DIR}/easyoidc.xml" | head -1 | sed -E 's:</?version>::g')"
if [[ -z "${VERSION}" ]]; then
    echo "Could not read version from easyoidc.xml" >&2
    exit 1
fi

echo "==> Building plg_system_easyoidc ${VERSION}"

echo "==> Reinstalling vendor/ (no-dev, optimized autoloader)"
( cd "${PLUGIN_DIR}" && rm -rf vendor composer.lock && php "${COMPOSER}" install --no-dev --optimize-autoloader --quiet )

mkdir -p "${DIST_DIR}"
ZIP="${DIST_DIR}/plg_system_easyoidc-${VERSION}.zip"
rm -f "${ZIP}"

echo "==> Packaging ${ZIP}"
( cd "${PLUGIN_DIR}" && zip -rq "${ZIP}" . \
    -x '*.DS_Store' \
    -x 'composer.phar' \
    -x 'vendor/*/tests/*' \
    -x 'vendor/*/test/*' \
    -x 'vendor/*/docs/*' \
    -x 'vendor/*/.git/*' \
    -x 'vendor/*/.github/*' \
    -x 'vendor/*/phpunit.xml*' \
    -x 'vendor/*/.editorconfig' \
    -x 'vendor/*/.gitattributes' \
    -x 'vendor/*/.gitignore' )

echo "==> Done"
ls -lh "${ZIP}"
