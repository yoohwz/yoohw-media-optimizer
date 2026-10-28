=== Media Optimizer ===
Contributors: yoohw
Tags: webp, avif, image optimization, media library, performance
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
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
* Set WebP and AVIF quality independently, with optional engine-dependent PNG and Imagick AVIF profiles.
* Optionally deliver AVIF first, WebP second, and retain JPEG/PNG as the fallback.
* Use Delivery Assistant to test direct sidecar access and server or CDN rewrite behavior.
* Review coverage, storage impact, estimated savings, and failed processing from the dashboard and Media Library.
* Process larger libraries through resumable browser queues and WP-CLI commands.

= Optional original optimization =

* Resize oversized uploads with configurable maximum dimensions.
* Choose lossless, balanced, aggressive, or custom JPEG quality settings.
* Remove or preserve supported private metadata while retaining supported source color profiles; engine and format limitations apply.
* Require a local backup before replacing an original JPEG or PNG file.
* Restore individual or multiple backed-up originals, including the **Restore optimized originals** action in the Media Library list-view bulk menu.
* Clean generated sidecars without deleting original uploads.

= Optional Thumbnail Manager companion =

Use the **Thumbnail Manager** tab to review the free companion and its installation or compatibility state. Thumbnail Manager manages which thumbnail sizes WordPress generates, checks and repairs selected thumbnails, and offers review-first Prune cleanup. The introduction links to native WordPress install or plugin-management screens; it starts no processing.

With compatible versions active, open a completed Thumbnail Manager Repair or Force regeneration result to preview its exact successful generated files in Media Optimizer. Start the WebP/AVIF step explicitly. After Prune, a separate preview and approval can clean up eligible sidecars with recorded Optimizer ownership. Original files and unrelated sizes stay outside these handoffs. Both plugins retain their standalone workflows.

= Local processing and server tools =

The plugin uses WordPress, GD, or Imagick where supported. It can also detect optional server binaries including `cwebp`, `avifenc`, `jpegoptim`, `jpegtran`, `pngquant`, `oxipng`, and `optipng`. These tools are not bundled or required.

Media Optimizer does not automatically edit `.htaccess`, CDN settings, or server configuration. Use Delivery Assistant before enabling frontend delivery on a production site.

== Installation ==

1. Install the plugin through the WordPress Plugins screen, or upload it to `/wp-content/plugins/yoohw-media-optimizer/`.
2. Activate Media Optimizer.
3. Go to **Media > Media Optimizer**.
4. Review available image engines and generate sidecars for test media. Test optional codec profiles before processing a larger selection.
5. Run Delivery Assistant before enabling HTML delivery.
6. Keep original-file optimization disabled unless you have reviewed its backup and restore workflow.
7. Optionally open the **Thumbnail Manager** tab to add the companion and review the coordinated workflow.

== Frequently Asked Questions ==

= Does the plugin replace original images? =

Not by default. It generates WebP or AVIF sidecars while leaving JPEG and PNG originals unchanged.

= Can it optimize original JPEG and PNG files? =

Yes, as an opt-in feature. A backup is required before an original physical file is replaced.

= How does lossless JPEG optimization work? =

Without resizing, lossless JPEG optimization uses optional `jpegoptim` or `jpegtran`. If no smaller lossless output is available, the original is kept unchanged, including its metadata. Force retries do not enable lossy JPEG fallback. Resizing remains allowed in this mode and may re-encode the image, with a backup retained before replacement.

= How is JPEG display orientation preserved? =

Oriented JPEG sidecars are normalized by a capable WordPress image editor before encoding, and original resize limits apply to displayed dimensions. Safe JPEG encoding requires an available PHP EXIF reader and successful orientation normalization. If either is unavailable, the source and existing sidecars are retained with an explanatory failure; PNG processing remains available.

A lossless JPEG with nontrivial orientation is left unchanged when metadata removal is requested without resizing, including force runs. This keeps its appearance intact rather than stripping the orientation tag without a lossless display transform. Previously generated files are not automatically migrated or repaired; regenerate sidecars explicitly when needed. Backups and restore preserve the original bytes, including EXIF and ICC metadata. Other metadata preservation in re-encoded output remains engine-dependent; supported color profiles are retained separately from private metadata.

= How are image colors and ICC profiles handled? =

Supported RGB JPEG and PNG images retain their source ICC profile and color encoding, including supported sRGB, Display P3 and Adobe RGB compatible profiles. The plugin does not automatically convert wide-gamut images to sRGB. Removing private metadata keeps the color profile needed to interpret the pixels.

Profile-aware processing requires a capable engine. GD alone cannot retain tagged images in this workflow; unsupported or malformed profiles, CMYK/YCCK sources and unverified color declarations are left unchanged with an explanatory result. Tagged AVIF output requires an available Imagick verifier. RGB matrix/TRC ICC v2/v4 profiles are supported; this is not full ICC, HDR or CMYK conversion support.

Plugin-generated sidecars and optimized-original candidates are checked before publishing or replacing an original. Backup and restore retain the exact original bytes. Previously optimized originals and fresh sidecars are not automatically re-encoded to repair historical color loss. Regenerate sidecars explicitly when needed; existing original repeat-protection remains active.

WordPress PNG thumbnails and scaled images created by the standard Imagick editor also retain supported source ICC profiles, including when automatic sidecars are disabled. This path keeps the upload untouched and removes private thumbnail metadata so the writer can retain ICC safely. GD and custom image editors keep their existing behavior. This does not infer profiles for existing untagged thumbnails; regenerate from an original that still has its profile. Core publishes its generated sizes; arbitrary image-writer faults are not covered by the optimizer candidate verifier.

= Can WebP and AVIF use different quality settings? =

Yes. Each format has its own quality setting. Both start at 82; existing installations inherit their previous shared quality until separate values are saved. The same number is not an equivalent visual-quality target across codecs or image engines. Original JPEG quality remains separate.

For PNG sources, optional lossless WebP uses `cwebp` at moderate fixed effort, and optional lossless AVIF alpha uses `avifenc` with one worker. WebP preserves meaningful RGB and alpha values; hidden RGB in fully transparent pixels is not guaranteed. AVIF color remains lossy at the selected AVIF quality while its alpha channel is lossless. These modes are source-format rules, not automatic graphics or photograph detection, and do not affect JPEG sources.

The optional profiles require their native encoder and PNG sources of at most 8 bits per channel. If an encoder is disabled, unavailable or fails, the requested profile fails safely without silently using a lossy fallback. Existing sidecars remain intact. Supported ICC profiles and the private-metadata policy still apply. Processing can cost more CPU or produce larger files; the existing skip-larger setting still applies. No universal size saving or full perceptual-quality guarantee is implied.

The optional Imagick AVIF chroma setting requests 4:4:4 color detail only when the plugin's Imagick editor creates an AVIF sidecar. It does not switch engines or change native encoders, GD, independent custom editors, original optimization or core thumbnails. The default leaves encoder behavior unchanged. Supported writers can preserve finer colored edges, but output size, CPU and memory use may increase substantially on large images; test your images first. This is an encoder request, not a guarantee for every ImageMagick build, and does not make alpha lossless. If setting the request fails, generation fails without replacing an existing sidecar.

Changing settings does not re-encode fresh sidecars or repeat original-file optimization. Use explicit force processing to regenerate sidecars after reviewing test images. Default profiles continue to use the existing supported-engine fallbacks.

= What storage is required for backup and restore? =

New backups remain outside the document root in the current site's backup directory. Backup roots must not overlap the WordPress directory or uploads, including uploads configured outside WordPress. The local backup filesystem must support hard links, and original replacement requires safe rename within the upload directory. If safe publication is unavailable, the operation fails without overwriting the current original. Independent WordPress installations must use separate backup roots, including when configuring a custom backup directory.

= How are restore paths protected? =

Restore accepts only private or legacy backup references matching the source upload. The configured uploads root may be a symlink, but symlinks below uploads or a site's backup directory are rejected. Copies are verified before replacing an original; failed originals keep their sidecars and tracking. If an original is restored but sidecar cleanup fails, the result reports partial failure. This does not provide automatic recovery of database state after a process stops following file replacement.

= Can a batch resume after closing the browser? =

Yes. Browser batches and WP-CLI queue commands share a saved snapshot of attachment IDs and cumulative progress. Reload the optimizer page and choose Resume. Pause stops further batches in that tab after its active request; another tab or CLI worker can still continue the same run. Deleted attachments are skipped and later uploads wait for the next run. Force is fixed for a run; an interrupted running item retries without force. Legacy queues retain completed items, but historical file-operation counters are unavailable.

= How are simultaneous media operations handled? =

Plugin optimization, backup/restore, sidecar cleanup and queue mutations share a process-held filesystem lock. A busy request reports contention instead of modifying media or resetting progress. Automatic upload optimization leaves metadata unchanged when busy; a later manual run can process it. The uploads directory must remain stable and support native cross-process flock on a local POSIX filesystem. The inert .yoohw-media-optimizer.lock file must remain in place, including after uninstall. Stop workers before updating the plugin or changing uploads configuration. Shared multi-host storage, overlapping older plugin workers and external WordPress/manual file changes are not coordinated. A process interruption after publishing a file but before saving tracking is not an atomic file/database transaction.

= Can I clean sidecars left after a Thumbnail Manager Prune? =

With compatible active versions, open the completed Prune result and follow its
separate Media Optimizer cleanup link. Prepare a preview, approve its exact
candidate list, then explicitly delete each batch of up to eight sidecars. Each
page contains up to 100 deleted source items and has its own 15-minute approval.
The cleanup never runs automatically or changes the original Prune report.

Only sidecars actually published with verifiable Optimizer ownership evidence
are eligible. Legacy files, changed bytes or file identity, recreated sources,
symlinks, and WordPress source/generated references are preserved. If evidence
expires or either plugin cannot establish reference safety, no deletion is
authorized. Saved cleanup results and owner discard remain available from the
Optimizer dashboard even if Thumbnail Manager is inactive.

Original optimization and restore attempts also update conservative source
revision markers. Saved Thumbnail Manager checks can warn that their evidence
may be stale, including attempts that made no change or partially failed. Run
a new check for current evidence; sidecar-only processing does not change these
markers. There is no automatic regeneration.

= How can I add Thumbnail Manager? =

Open Media > Media Optimizer > Thumbnail Manager for an introduction to the free
companion, its current installation status, and the coordinated workflow. Follow
the button to review installation, activation or updates in WordPress, or open
Thumbnail Manager when a compatible version is active. Opening the tab never
installs a plugin or processes media. Both plugins remain usable independently.

= Can I optimize only files repaired or regenerated by Thumbnail Manager? =

With a compatible active Thumbnail Manager, open a completed Repair or Force
report and follow its Media Optimizer preview link. Previewing is read-only.
Choose Run to rebuild only WebP/AVIF sidecars for the exact successful generated
files recorded by that report. The handoff never optimizes original files,
expands to other sizes, changes delivery settings, or cleans sidecars after prune.

Current codec settings apply to each batch. The existing queue imports one
bounded report page per batch and keeps per-image results; refresh the preview
to inspect them or return to the saved source report. The same signed-in
administrator must resume, including WP-CLI with `--user`. Expired reports,
changed source files or metadata, missing evidence and an inactive counterpart
fail closed without falling back to a whole-library run. Original backup and
repeat-optimization history is retained when sidecar tracking is updated.

If the report expires or Thumbnail Manager becomes unavailable, the run's owner
can choose Discard scoped run from Media Optimizer or the handoff preview. This
explicit queue-only action preserves every file, backup and source report, and
allows a new standalone or scoped run. It remains available without the source
plugin; a late request for the discarded run cannot resume it.

Both plugins keep their standalone workflows. Their compatible versions share
the optimizer's native mutation mutex: active Thumbnail Manager maintenance
blocks optimizer writes between batches, and optimizer work blocks new
Thumbnail Manager mutations. Automatic optimizer metadata hooks stay inert
inside a Thumbnail Manager mutation scope. This coordinates these two plugins;
the existing local-filesystem and external-mutation limitations still apply.

= Does it serve AVIF and WebP automatically? =

Only after HTML delivery is enabled. The plugin then prefers an available AVIF, falls back to WebP, and finally uses the original image.

= Which URLs can use HTML delivery? =

Only URLs on the configured uploads origin and inside its uploads path can select local sidecars. Root-relative and protocol-relative URLs must resolve to that same origin. Other hosts, ambiguous paths, backups and symlinks below uploads keep their original image markup. The plugin uses fresh readable AVIF/WebP files and preserves the original JPEG/PNG fallback; it does not vary cached HTML by the request Accept header.

= How should I interpret Delivery Assistant? =

Direct AVIF/WebP and rewrite checks are reported separately. OK requires HTTP 200 with the expected modern image Content-Type. The AVIF-preferred rewrite check accepts AVIF or WebP; the WebP-only check excludes AVIF. An original JPEG/PNG is labeled Original fallback, and HTML or missing/wrong MIME cannot pass. These HEAD checks inspect headers, not image bytes or browser/CDN cache behavior. Redirects are reported without following them, and no GET fallback is attempted.

Diagnostic requests stay on the site's home origin and inside its uploads path, subject to WordPress safe HTTP checks. An external uploads/CDN origin may still work for frontend delivery, but Delivery Assistant will not probe it. The assistant does not change server/CDN rules or certify their configuration.

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

== Upgrade Notice ==

= 1.1.0 =

Improves image quality, color handling, backup/restore and resumable processing; adds optional Thumbnail Manager coordination. Stop active media workers before updating and review optional codec profiles on test images.

== Changelog ==

= 1.1.0 (Oct 28, 2026) =

* Preserved original-optimization fingerprints, backup references and savings when processing only sidecars, preventing unintended repeated lossy compression.
* Hardened backup isolation, verified restore copies and safe file publication; retained tracking and sidecars when restore cannot complete safely.
* Serialized media mutations across browser, automatic and WP-CLI workers with process-held filesystem locks, and added resumable browser queues with cumulative results.
* Tightened same-site delivery URL resolution, source fallbacks and Delivery Assistant diagnostics for unsafe paths, redirects and transport failures.
* Kept lossless JPEG sources unchanged when a safe smaller lossless output is unavailable, and preserved EXIF display orientation across supported encoding and resize paths.
* Preserved supported RGB ICC profiles and color encoding during verified optimization and sidecar generation; retained source profiles in standard WordPress Imagick PNG thumbnails and scaled images.
* Preserved independent codec quality settings through supported WordPress image-editor format conversion.
* Added separate WebP and AVIF quality settings, optional native lossless WebP and lossless AVIF alpha profiles for supported PNG sources, and an opt-in Imagick AVIF 4:4:4 chroma request.
* Added optional Thumbnail Manager coordination: preview exact successful Repair or Force-generated files and explicitly create only their WebP/AVIF sidecars, with separate results and a link back to the source report.
* Added separately previewed and approved cleanup after Thumbnail Manager Prune for unchanged sidecars with verifiable Optimizer ownership; preserved legacy, referenced and unverifiable files.
* Added conservative source-freshness markers for Thumbnail Manager reports after original optimization or restore attempts, without automatic thumbnail regeneration.
* Added the Thumbnail Manager introduction tab, improved phone and tablet navigation, and shortened the Media Library bulk action to “Restore optimized originals”.

= 1.0.1 =

* Prevent repeated lossy original-file optimization with source and option fingerprints.
* Use cache-safe picture markup with original JPEG/PNG fallbacks for HTML delivery.
* Store new backups outside the document root and migrate tracked legacy backups.
* Add queue locking, safer cleanup paths, bounded cached reports, corrected restore batching, AVIF CLI totals, localized admin progress text, and regression checks.

= 1.0.0 =

* Initial WordPress.org release with local WebP and AVIF sidecar generation.
* Added optional modern image delivery, Delivery Assistant, optimization backups, restore and cleanup tools.
* Added dashboard and Media Library savings data, queue processing, and WP-CLI helpers.
