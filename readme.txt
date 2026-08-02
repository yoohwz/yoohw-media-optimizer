=== Media Optimizer ===
Contributors: yoohw
Tags: webp, avif, image optimization, media library, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WebP and AVIF sidecars, modern image delivery tests, and safe WordPress media optimization with backups.

== Description ==

Media Optimizer by YoOhw is a WordPress image optimization and modern image delivery plugin for WebP and AVIF sidecars. It helps you generate modern image files locally, verify delivery before switching frontend URLs, and keep original JPEG/PNG uploads safe by default.

Unlike image optimization services that send media to a remote API, Media Optimizer is local-first. It uses the active WordPress image editor, GD/Imagick when available, and optional server binaries such as `cwebp`, `avifenc`, `jpegoptim`, `jpegtran`, `pngquant`, `oxipng`, and `optipng`.

The default mode is conservative: the plugin creates sidecar files but does not replace original media files or frontend image URLs until you enable those options. All current plugin features are free and run without a paid image optimization API.

= Key Features =

* Generate `.webp` sidecars for original images and WordPress-generated image sizes.
* Generate `.avif` sidecars when `avifenc` or the active WordPress image editor supports AVIF.
* Cache-safe HTML delivery with AVIF/WebP `<picture>` sources and an original JPEG/PNG `<img>` fallback.
* Delivery Assistant to test direct AVIF/WebP file access and server/CDN rewrite behavior.
* Optional JPEG/PNG original-file optimization with mandatory backups.
* Resize oversized uploads with max width and max height settings.
* Compression modes for lossless, balanced, aggressive, and custom JPEG quality workflows.
* Metadata policy controls for removing or preserving image metadata where supported.
* Media Library Savings column with per-attachment status and restore links.
* Overview dashboard with bandwidth savings, storage impact, coverage, and failure status.
* Queue-based processing for larger media libraries.
* Cleanup tools for generated sidecars and restore tools for backed-up originals.
* WP-CLI helpers for analyze, optimize, restore, and queue workflows.

= Safe Modern Image Delivery =

Media Optimizer is designed for sites that want a clear modern image workflow without immediately changing originals or relying on a third-party image CDN.

When HTML delivery is enabled, the plugin emits browser-native `<picture>` markup from available sidecars:

* A fresh `.avif` sidecar becomes the first `<source type="image/avif">` candidate.
* A fresh `.webp` sidecar becomes the next `<source type="image/webp">` candidate.
* The unchanged original JPEG/PNG remains in `<img>` as the fallback.

The browser chooses the first supported source, so page caches do not need to vary HTML by the request `Accept` header.

= Optional Original Optimization =

Original-file optimization is disabled by default. When enabled, Media Optimizer can resize and recompress JPEG/PNG files after creating backups outside the WordPress document root under `../yoohw-media-backups/site-{blog-id}/`. The location can be changed with the `yoohw_media_optimizer_backup_dir` filter.

Backups are required before replacing originals, and restore controls are available per image and in bulk.

= External Binaries =

The plugin can detect and use supported server binaries when available:

* JPEG: `jpegoptim`, `cjpeg`/`djpeg`, `jpegtran`
* PNG: `pngquant`, `oxipng`, `optipng`
* WebP: `cwebp`
* AVIF: `avifenc`

If a binary is not available, the plugin falls back to WordPress/GD/Imagick where possible. External binaries are optional and are not bundled with the plugin.

== Installation ==

1. Upload the `yoohw-media-optimizer` folder to `/wp-content/plugins/`, or install the plugin ZIP through the WordPress admin.
2. Activate the plugin through the Plugins screen.
3. Go to Media > Media Optimizer.
4. Run optimization for existing media, or leave automatic optimization enabled for new uploads.
5. Use the Delivery Assistant before enabling HTML delivery on a live site.

== Frequently Asked Questions ==

= Does Media Optimizer replace my original images? =

Not by default. The default workflow generates WebP/AVIF sidecar files and keeps original JPEG/PNG files unchanged.

= Can it optimize original JPEG/PNG files? =

Yes, but original-file optimization is opt-in. When enabled, the plugin creates a backup before replacing a physical JPEG/PNG file.

= Does it serve AVIF and WebP automatically? =

Only when HTML delivery mode is enabled. In that mode, AVIF is served first when supported and available, then WebP, then the original JPEG/PNG fallback.

= Does it send images to an external service? =

No. Images are processed locally by WordPress/GD/Imagick or optional server binaries. No media files are sent to an external optimization API.

= Is Media Optimizer free? =

Yes. The plugin is free and does not require paid credits, a paid API key, or a subscription to optimize images locally.

= Do I need cwebp, avifenc, or other binaries? =

No. They are optional. If available, Media Optimizer can use them for stronger local optimization. If not, it falls back to supported WordPress image engines.

= Does it edit .htaccess or server/CDN rules? =

No. The plugin provides delivery tests and an Apache rewrite example, but it does not automatically edit server configuration.

= Does uninstall delete generated images or backups? =

No. Uninstall removes plugin options and tracking metadata only. Generated sidecars and backups are left in place to avoid breaking existing delivery setups.

== Privacy ==

Media Optimizer does not collect personal data, does not create tracking cookies, and does not send media files to external optimization services.

The Delivery Assistant performs HTTP HEAD checks against URLs on the same WordPress site to verify whether generated AVIF/WebP files can be reached.

== Changelog ==

= 1.0.1 =
* Prevent repeated lossy original-file optimization with source and option fingerprints.
* Use cache-safe picture markup with original JPEG/PNG fallbacks for HTML delivery.
* Store new backups outside the document root and migrate tracked legacy backups.
* Add queue locking, safer cleanup paths, bounded cached reports, corrected restore batching, AVIF CLI totals, localized admin progress text, and regression checks.

= 1.0.0 =
* Initial public release on WordPress.org.
* Add local WebP and AVIF sidecar generation for WordPress media uploads.
* Add optional HTML delivery with AVIF first, WebP second, and JPEG/PNG fallback.
* Add Delivery Assistant, Overview dashboard, Media Library Savings column, original-file optimization with backups, restore tools, cleanup tools, queue processing, and WP-CLI helpers.
