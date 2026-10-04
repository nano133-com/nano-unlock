#!/bin/sh
# Copies the plugin (and the e2e helper mu-plugin) into the wp-env WordPress folders.
#
# wp-env normally bind-mounts a plugin folder inside the WordPress bind mount. On some
# Docker Desktop setups, those nested mounts come and go when a container restarts (the
# folder shows up empty), so the plugin is copied instead. Run it after each change:
#   npm run sync
set -e
cd "$(dirname "$0")/.."
P=$(ls -d "${WP_ENV_HOME:-$HOME/.wp-env}"/*"$(basename "$PWD")"-* 2>/dev/null | head -1)
[ -d "$P/WordPress" ] || { echo "wp-env is not installed here; run npx wp-env start first" >&2; exit 1; }
for site in WordPress tests-WordPress; do
  mkdir -p "$P/$site/wp-content/plugins/nano-unlock"
  rsync -a --delete nano-unlock/ "$P/$site/wp-content/plugins/nano-unlock/"
done
mkdir -p "$P/WordPress/wp-content/mu-plugins"
rm -f "$P/WordPress/wp-content/mu-plugins/nano-unlock-e2e.php"
cp e2e/mu-plugins/nano-unlock-e2e.php "$P/WordPress/wp-content/mu-plugins/"
echo "synced to $P"
# The integration tests that run inside WordPress (npm run test:wp).
for site in WordPress tests-WordPress; do
  rm -rf "$P/$site/wp-content/nano-unlock-tests"
  cp -R e2e/wp-tests "$P/$site/wp-content/nano-unlock-tests"
done
