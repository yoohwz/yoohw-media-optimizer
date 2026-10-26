#!/usr/bin/env bash
# CI only: provision a fresh WordPress site in a new runner-temp directory.
set -euo pipefail

: "${RUNNER_TEMP:?Run on a disposable GitHub Actions runner}"
: "${WP_VERSION:?Select an explicit WordPress version or nightly}"
export YOOHW_MO_REQUIRE_NATIVE_QUALITY="${YOOHW_MO_REQUIRE_NATIVE_QUALITY:-0}"
plugin_source="$(pwd)"
test_root="$(mktemp -d "$RUNNER_TEMP/media-optimizer-wp.XXXXXX")"
site_root="$test_root/public"
mkdir "$site_root"

# Fail before provisioning if a required quality branch would otherwise skip.
php <<'PHP'
<?php
foreach ( array( 'mysqli', 'gd', 'exif' ) as $extension ) {
    if ( ! extension_loaded( $extension ) ) {
        fwrite( STDERR, "Missing extension: $extension\n" );
        exit( 1 );
    }
}
foreach ( array( 'imagepng', 'imagecreatetruecolor', 'exif_read_data' ) as $function ) {
    if ( ! function_exists( $function ) ) {
        fwrite( STDERR, "Missing function: $function\n" );
        exit( 1 );
    }
}
if ( '1' === getenv( 'YOOHW_MO_REQUIRE_NATIVE_QUALITY' ) ) {
    if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
        fwrite( STDERR, "Missing required Imagick extension.\n" );
        exit( 1 );
    }
    foreach ( array( 'JPEG', 'PNG', 'WEBP', 'AVIF' ) as $format ) {
        if ( ! Imagick::queryFormats( $format ) ) {
            fwrite( STDERR, "Missing required Imagick format: $format\n" );
            exit( 1 );
        }
    }
    echo Imagick::getVersion()['versionString'] . "\n";
}
PHP
if [[ "$YOOHW_MO_REQUIRE_NATIVE_QUALITY" == 1 ]]; then
  for encoder in cwebp avifenc jpegtran; do
    command -v "$encoder" >/dev/null || { echo "Missing required encoder: $encoder" >&2; exit 1; }
  done
  cwebp -version
  avifenc --version
  jpegtran -version
  avif_help="$(avifenc --help)"
  [[ "$avif_help" =~ -q,[[:space:]]*--qcolor ]] || { echo 'Required avifenc -q quality interface unavailable' >&2; exit 1; }
fi
wp core download --path="$site_root" --version="$WP_VERSION" --locale=en_US
wp config create --path="$site_root" --dbname=media_optimizer_test --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
wp db create --path="$site_root"
wp core install --path="$site_root" --url=http://media-optimizer.test --title='Media Optimizer CI' --admin_user=ci --admin_password=ci-test-password --admin_email=ci@example.org --skip-email
ln -s "$plugin_source" "$site_root/wp-content/plugins/yoohw-media-optimizer"
wp plugin activate yoohw-media-optimizer --path="$site_root"
wp core version --path="$site_root"
php --version
wp eval-file "$plugin_source/tests/regression-tests.php" --path="$site_root"
