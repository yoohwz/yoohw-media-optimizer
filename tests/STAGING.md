# Staging quality acceptance for rounds 1–3

Use an explicitly selected non-production site and copied media. This guide reuses
the benchmark, regression probes and existing PR evidence; it adds no release or
approval stage. Keep site-specific reports, media, credentials and database exports
outside Git and outside the public document root.

## Prepare the candidate and site

Record the reviewed source commit and package SHA256. Version `1.0.1` alone cannot
identify these changes: PRs #8–#10 intentionally kept the version unchanged.
Build the installable payload using `.distignore`; `/tests` and `/bin` stay out of
the plugin ZIP. Keep the source checkout separately for developer probes.

Before changing the staging installation, preserve its existing plugin files and
take the normal site/database/uploads snapshot. Preserve existing private backups
separately; an uploads-only snapshot does not cover them. Record the current
optimizer options. Use the site's established staging update/rollback process;
never replace unrelated local plugin changes or copy staging data into production.

Run the following **from the reviewed source checkout**, using the site's actual
PHP binary/configuration and an absolute source-script path. `STAGING_ROOT` is the
chosen site's WordPress root; `EVIDENCE_DIR` is an existing private directory.

```sh
SOURCE_ROOT="$PWD"
wp --path="$STAGING_ROOT" --skip-themes \
  --exec='if (!defined("DISABLE_WP_CRON")) { define("DISABLE_WP_CRON", true); }' \
  eval-file "$SOURCE_ROOT/tests/staging-preflight.php" \
  > "$EVIDENCE_DIR/preflight.json"
```

The report does not run encoders or modify plugin options, attachments or media.
WordPress and other plugins still bootstrap. It reports CLI capabilities, enabled
binary discovery and SHA256 of the relevant runtime files against this checkout.
It is not an acceptance PASS. A mismatched candidate means the intended build is
not installed; missing engines are not silently certified. Binary discovery does
not prove flags/codecs work. CLI PHP, PATH, memory and Imagick can differ from
PHP-FPM, so confirm the engine and result through the browser too. Do not repair
extension version reporting by overriding WordPress capability checks.

## Select copied inputs

Start with a small representative set of real staging images: colorful product
photos, fine detail, text/logo/edges and soft transparency. Include actual source
dimensions and larger images where relevant. Add the existing deterministic
fixtures for valid sRGB/P3/Adobe RGB ICC, palette+tRNS, EXIF rotation/reflection,
all alpha values, malformed profiles and PNG16. They fill coverage gaps; they are
not independent real catalog photographs or proof of every ICC profile.

Keep immutable source copies and hashes. Use separate fixture attachment IDs for
each profile comparison; importing or reusing the same original while changing
settings can confuse fresh-sidecar and repeat-optimization results. Disable
automatic upload optimization before importing the copies, keep HTML delivery and
original optimization off initially, and restore the prior options afterward.
Do not run whole-library, cleanup, restore or force commands on existing media.

## Exercise the existing flows

| Area | Exercise on copied fixtures | Evidence |
|---|---|---|
| Round 1 measurement | Compare ordinary profiles against the selected alternatives on the same source; reuse [benchmark methodology](benchmark/README.md) | Source/output hashes, bytes, engine/version, wall time, peak memory if measured; scores only when the metric actually runs |
| Round 2 color/privacy | sRGB/P3/Adobe RGB with remove/preserve-all policies; rotate/resize separately | Exact ICC, correct pixel/profile association and orientation, private metadata behavior; unsupported inputs retain source and explain failure |
| Round 3 qualities | Save different WebP/AVIF values, reload and generate new sidecars | Each codec uses its own value; legacy shared setting still inherits; JPEG-original setting stays separate |
| Round 3 PNG profiles | Lossless WebP and lossless AVIF alpha, then disabled/failing native encoder and PNG16 | Meaningful WebP RGB+alpha exact, AVIF alpha exact at 8-bit precision; AVIF color still lossy; requested modes never silently degrade |
| Existing media protection | Fresh sidecars after setting changes, then explicit force on fixture IDs; oversized candidates | Fresh hash unchanged; force regenerates only fixtures; skip-larger retains old sidecars; truthful tracking |
| Original opt-in/recovery | On dedicated copies only, optimize once, retry unchanged, then restore | Backup-before-write, original fingerprint prevents repeat loss, backup unchanged, restored source byte-exact |
| Browser/display | Side-by-side source/output at 1:1 and normal display size; alpha over black/white | Check hue, saturated edges, text, halos and rotation; record browser/OS/display and Human observations, not an invented calibrated-display PASS |

For the existing automated safety suite, prefer a separate disposable clone of
the staging environment. It creates/deletes fixture attachments, updates/restores
global optimizer options, injects faults and manipulates the shared queue/lock.
Do not run it concurrently with uploads, browser batches or other workers on a
populated staging site. See [actual coverage and limitations](README.md).

```sh
# Disposable clone only, with the candidate installed and other workers stopped.
YOOHW_MO_REQUIRE_NATIVE_QUALITY=1 wp --path="$DISPOSABLE_ROOT" \
  eval-file "$SOURCE_ROOT/tests/regression-tests.php" > "$EVIDENCE_DIR/regression.log" 2>&1
```

The required native profile needs real Imagick/LCMS and cwebp, avifenc and jpegtran.
If the target lacks them, document the gap and exercise its real safe-failure
behavior; do not disable the requirement and call that full native certification.
For offline copies, the existing benchmark save seam can measure the default
candidate against a valid manifest without attachment/options mutations. Its
normalizer transforms to sRGB for scoring only; it does not authorize converting
the source-gamut policy in the plugin. The round-1 baseline seam does not exercise
the new PNG profile settings or public attachment lifecycle.

## Record the outcome and stop

Use the existing task PR for source/package identity, environment, input provenance,
settings, per-scenario PASS/FAIL/NOT RUN, browser observations and limitations.
Keep raw images/logs privately with SHA256 receipts. No source-media paths, private
customer images or database dumps belong in the PR. Mark missing color/content
categories and unmeasured costs honestly; CI and synthetic samples do not replace
real staging acceptance. Compare measured size/CPU tradeoffs before choosing any
site setting. Restore temporary options using the recorded values; retain test
copies/evidence for inspection or remove only those confirmed fixture IDs through
the existing WordPress flow. Do not use generic directory deletion commands.

If candidate installation or an environment capability is missing, preparation
can be complete while staging acceptance remains NOT RUN. Fix or decide that
specific prerequisite before proceeding. Release tags, WordPress.org publication,
production deployment and rollback remain separate Human actions.
