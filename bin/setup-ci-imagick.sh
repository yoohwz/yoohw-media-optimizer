#!/usr/bin/env bash
# Disposable CI only: distro IM 6.9.12-98 ignores lossy WebP quality.
set -euo pipefail
[[ "${GITHUB_ACTIONS:-}" == true && "$(uname -s)" == Linux ]] || { echo 'GitHub Linux runner required' >&2; exit 1; }
: "${RUNNER_TEMP:?Disposable runner required}"
: "${GITHUB_ENV:?GitHub environment file required}"
build_root="$(mktemp -d "$RUNNER_TEMP/media-optimizer-imagick.XXXXXX")"
prefix="$build_root/runtime"
sudo apt-get update
sudo apt-get install --no-install-recommends -y build-essential pkg-config p7zip-full liblcms2-dev libheif-dev libjpeg-dev libpng-dev libwebp-dev

curl --fail --location --retry 3 'https://github.com/ImageMagick/ImageMagick6/releases/download/6.9.13-59/ImageMagick-6.9.13-59.7z' -o "$build_root/imagemagick.7z"
curl --fail --location --retry 3 'https://pecl.php.net/get/imagick-3.8.1.tgz' -o "$build_root/imagick.tgz"
echo "25f456444a7b8fa6622a87a901791acc8617f91ef7fb815fa383732e8a271366  $build_root/imagemagick.7z" | sha256sum --check --strict
echo "3a3587c0a524c17d0dad9673a160b90cd776e836838474e173b549ed864352ee  $build_root/imagick.tgz" | sha256sum --check --strict
7z x -y "-o$build_root" "$build_root/imagemagick.7z" >/dev/null
tar -xzf "$build_root/imagick.tgz" -C "$build_root"
cd "$build_root/ImageMagick-6.9.13-59"
./configure --prefix="$prefix" --with-quantum-depth=16 --disable-hdri --disable-static --disable-openmp --without-perl --without-x --without-magick-plus-plus --without-gslib --with-lcms=yes --with-heic=yes --with-jpeg=yes --with-png=yes --with-webp=yes > "$build_root/imagemagick-configure.log" 2>&1 || { cat "$build_root/imagemagick-configure.log"; exit 1; }
make -j2 > "$build_root/imagemagick-build.log" 2>&1 || { tail -n 100 "$build_root/imagemagick-build.log"; exit 1; }
make install > "$build_root/imagemagick-install.log" 2>&1 || { tail -n 100 "$build_root/imagemagick-install.log"; exit 1; }

export PKG_CONFIG_PATH="$prefix/lib/pkgconfig${PKG_CONFIG_PATH:+:$PKG_CONFIG_PATH}"
export LD_LIBRARY_PATH="$prefix/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
export MAGICK_CODER_MODULE_PATH="$prefix/lib/ImageMagick-6.9.13/modules-Q16/coders"
cd "$build_root/imagick-3.8.1"
phpize
./configure --with-php-config="$(command -v php-config)" --with-imagick="$prefix" > "$build_root/imagick-configure.log" 2>&1 || { cat "$build_root/imagick-configure.log"; exit 1; }
make -j2 > "$build_root/imagick-build.log" 2>&1 || { tail -n 100 "$build_root/imagick-build.log"; exit 1; }
# setup-php enabled imagick already; replace only this disposable runner's module.
sudo install -m 755 modules/imagick.so "$(php-config --extension-dir)/imagick.so"
echo "LD_LIBRARY_PATH=$LD_LIBRARY_PATH" >> "$GITHUB_ENV"
echo "MAGICK_CODER_MODULE_PATH=$MAGICK_CODER_MODULE_PATH" >> "$GITHUB_ENV"
"$prefix/bin/convert" -version
php <<'PHP'
<?php
if ( '3.8.1' !== phpversion( 'imagick' ) || false === strpos( Imagick::getVersion()['versionString'], 'ImageMagick 6.9.13-59 ' ) ) {
    fwrite( STDERR, "Unexpected loaded Imagick/ImageMagick stack.\n" ); exit( 1 );
}
foreach ( array( 'JPEG', 'PNG', 'WEBP', 'AVIF' ) as $format ) {
    if ( ! Imagick::queryFormats( $format ) ) { fwrite( STDERR, "Missing format: $format\n" ); exit( 1 ); }
}
echo PHP_VERSION . ' / Imagick ' . phpversion( 'imagick' ) . ' / ' . Imagick::getVersion()['versionString'] . "\n";
PHP
