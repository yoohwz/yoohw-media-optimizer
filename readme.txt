=== Media Optimizer ===
Contributors: yoohw
Tags: webp, avif, image optimization, media library, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free WebP and AVIF sidecars, delivery tests, and safe local WordPress image optimization with original-file backups.

== Description ==

[Product page](https://yoohw.com/product/media-optimizer/) | [Documentation](https://docs.yoohw.com/category/media-optimizer/) | [Support](https://workspace.yoohw.com/)

Media Optimizer generates WebP and AVIF sidecars locally, tests modern image delivery, and can optimize original JPEG and PNG uploads with mandatory backups. It uses the active WordPress image editor and optional server binaries instead of sending media to a paid optimization API.

The default workflow is conservative: sidecars are generated without replacing original files or changing frontend image URLs. Original-file optimization and HTML delivery remain opt-in.

= Modern image workflow =

* Generate WebP sidecars for original uploads and WordPress image sizes.
* Generate AVIF sidecars when the active image editor or `avifenc` supports AVIF.
* Optionally deliver AVIF first, WebP second, and retain JPEG/PNG as the fallback.
* Use Delivery Assistant to test direct sidecar access and server or CDN rewrite behavior.
* Review coverage, storage impact, estimated savings, and failed processing from the dashboard and Media Library.
* Process larger libraries through queues and WP-CLI commands.

= Optional original optimization =

* Resize oversized uploads with configurable maximum dimensions.
* Choose lossless, balanced, aggressive, or custom JPEG quality settings.
* Remove or preserve supported image metadata.
* Require a local backup before replacing an original JPEG or PNG file.
* Restore individual or multiple backed-up originals.
* Clean generated sidecars without deleting original uploads.

= Local processing and server tools =

The plugin uses WordPress, GD, or Imagick where supported. It can also detect optional server binaries including `cwebp`, `avifenc`, `jpegoptim`, `jpegtran`, `pngquant`, `oxipng`, and `optipng`. These tools are not bundled or required.

Media Optimizer does not automatically edit `.htaccess`, CDN settings, or server configuration. Use Delivery Assistant before enabling frontend delivery on a production site.

== Installation ==

1. Install the plugin through the WordPress Plugins screen, or upload it to `/wp-content/plugins/yoohw-media-optimizer/`.
2. Activate Media Optimizer.
3. Go to **Media > Media Optimizer**.
4. Review available image engines and generate sidecars for test media.
5. Run Delivery Assistant before enabling HTML delivery.
6. Keep original-file optimization disabled unless you have reviewed its backup and restore workflow.

== Frequently Asked Questions ==

= Does the plugin replace original images? =

Not by default. It generates WebP or AVIF sidecars while leaving JPEG and PNG originals unchanged.

= Can it optimize original JPEG and PNG files? =

Yes, as an opt-in feature. A backup is required before an original physical file is replaced.

= Does it serve AVIF and WebP automatically? =

Only after HTML delivery is enabled. The plugin then prefers an available AVIF, falls back to WebP, and finally uses the original image.

= Does it send images to an external optimization service? =

No. Processing is local and does not require paid credits, an API key, or a subscription.

= Are cwebp, avifenc, and the other binaries required? =

No. They are optional. The plugin falls back to supported WordPress image engines where possible.

= Does it change server or CDN rules? =

No. Delivery Assistant provides tests and guidance, but the plugin does not automatically edit server configuration.

= What happens to sidecars and backups on uninstall? =

They remain in place to avoid breaking an existing delivery setup. Uninstall removes plugin options and tracking metadata.

== Privacy ==

Media Optimizer does not collect personal data, create tracking cookies, or send media files to external optimization services.

Delivery Assistant makes HTTP HEAD requests to URLs on the same WordPress site to check whether generated AVIF or WebP files are reachable.

== Changelog ==

= 1.0.1 =

* Prevent repeated lossy original-file optimization with source and option fingerprints.
* Use cache-safe picture markup with original JPEG/PNG fallbacks for HTML delivery.
* Store new backups outside the document root and migrate tracked legacy backups.
* Add queue locking, safer cleanup paths, bounded cached reports, corrected restore batching, AVIF CLI totals, localized admin progress text, and regression checks.

= 1.0.0 =

* Initial WordPress.org release with local WebP and AVIF sidecar generation.
* Added optional modern image delivery, Delivery Assistant, optimization backups, restore and cleanup tools.
* Added dashboard and Media Library savings data, queue processing, and WP-CLI helpers.
