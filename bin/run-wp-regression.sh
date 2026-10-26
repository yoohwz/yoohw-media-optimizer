#!/usr/bin/env bash
# CI only: provision a fresh WordPress site in a new runner-temp directory.
set -euo pipefail

: "${RUNNER_TEMP:?Run on a disposable GitHub Actions runner}"
: "${WP_VERSION:?Select an explicit WordPress version or nightly}"
plugin_source="$(pwd)"
test_root="$(mktemp -d "$RUNNER_TEMP/media-optimizer-wp.XXXXXX")"
site_root="$test_root/public"
mkdir "$site_root"

php -r 'foreach (["mysqli", "gd"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } } if (!function_exists("imagepng") || !function_exists("imagecreatetruecolor")) { exit(1); }'
wp core download --path="$site_root" --version="$WP_VERSION" --locale=en_US
wp config create --path="$site_root" --dbname=media_optimizer_test --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
wp db create --path="$site_root"
wp core install --path="$site_root" --url=http://media-optimizer.test --title='Media Optimizer CI' --admin_user=ci --admin_password=ci-test-password --admin_email=ci@example.org --skip-email
ln -s "$plugin_source" "$site_root/wp-content/plugins/yoohw-media-optimizer"
wp plugin activate yoohw-media-optimizer --path="$site_root"
wp core version --path="$site_root"
php --version
wp eval-file "$plugin_source/tests/regression-tests.php" --path="$site_root"
