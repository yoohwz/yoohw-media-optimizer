# Regression checks

Run the integration regression checks against a disposable or local WordPress site:

```bash
wp eval-file wp-content/plugins/yoohw-media-optimizer/tests/regression-tests.php
```

The script creates temporary media files and an attachment, verifies picture
fallbacks, sidecar path containment, idempotent original optimization, and private
backup placement, then restores plugin options and removes its test attachment,
files, and backups.

`delivery-tests.php` runs before the GD-dependent checks. It isolates uploads and
attachment queries, then checks configured-origin/default-port matching,
root/protocol-relative references, path segment decoding, query/fragment
preservation, foreign origins, malformed/traversal/separator/control escapes,
backup exclusions, descendant symlinks, regular files and freshness. Picture
checks retain original HTML, AVIF/WebP ordering and complete srcsets, and compare
markup across request Accept values for shared-cache safety. Sample attachment
paths include spaces, Unicode, a literal percent and hash to verify segment
encoding.

Delivery HTTP checks intercept `pre_http_request` rather than using external
network access. They assert the target, HEAD method, safe URL rejection, timeout,
zero redirects and supplied Accept header, plus exact expected MIME/status
classification, original fallback, dynamic WebP, redirects and transport errors.
Fixtures restore options, filters and request Accept, and remove temporary posts
and paths. These checks do not certify actual HTTP transports, server/CDN rewrite
configuration, browser rendering, nonce/capability AJAX handling or MIME bytes;
those affected boundaries require separate targeted evidence.

With GD available, attachment lifecycle checks force the GD editor and exercise
persisted tracking through `optimize_attachment()`: optimize originals, disable
originals and process sidecars, then re-enable originals. They verify original
and backup hashes, retained fingerprints and savings, current-run counters,
changed settings, changed source content with identical size/mtime, explicit
force, legacy identity (including JPEG with a non-default stored quality),
incomplete modern identity, new image sizes, missing sources, restore, and
independent original/sidecar failures. These checks do not certify concurrency, crash-safe
replacement, filesystem fault recovery, or the full image-engine matrix.

`lossless-jpeg-tests.php` checks an EXIF Orientation=6 JPEG remains byte-identical
when a lossless encoder is unavailable, including force and metadata policies.
Its controlled encoder success/no-gain/partial-failure cases use Orientation=1:
exact raw RGB equality is useful for lossless encoding but cannot by itself prove
EXIF display identity. These checks also cover repeat fingerprints, temporary
cleanup, backup immutability/savings and permitted resizing. A real `jpegtran`
check runs when installed in a standard search directory; otherwise it records
`NOT RUN`. Controlled encoders do not certify real encoder behavior.

`image-quality-tests.php` launches `image-quality-probe.php` in fresh PHP child
processes bootstrapping the same disposable WordPress installation. This keeps
real native/PATH/fault checks independent of the optimizer's process-local binary
lookup cache and the preceding controlled lossless encoders. The children restore
options/filters/PATH and remove their own attachments, media and recovery backups.
Run this suite only where these temporary mutations are appropriate.

The quality fixtures are generated in PHP from four asymmetric flat-color
quadrants, with a small valid generated sRGB ICC profile (see
[fixture provenance](fixtures/README.md)). GD checks all EXIF orientations 1–8;
Imagick checks rotation 6 and reflection 2 when available. Fixed expected raw
output dimensions and quadrant ordering establish displayed identity separately
from EXIF metadata, including rejection of non-neutral output orientation tags.
Sidecars retain source bytes. Original width-only, height-only, bounding-box and
no-upscale cases use displayed dimensions, selected editor identities and exact
backup/restore bytes including EXIF/ICC. Non-neutral lossless metadata removal
without resizing remains byte-identical and explains the skip with force on/off.
A WordPress normalization-refusal filter must retain the old sidecar.

Palette+tRNS and RGBA PNG originals compare every meaningful lossless RGBA pixel
and composites over black and white (hidden RGB at alpha=127 is irrelevant).
Lossy WebP/AVIF use flat-region samples: RGB/composite tolerance 25/255 and GD
alpha tolerance 2/127. These bounded oracles are not broad perceptual or
wide-gamut/color-management certification. Valid ICC decoding is checked with
Imagick; exact source-profile bytes are recovered from backups/restores. No new
output metadata-preservation policy is asserted.

Neutral JPEG/palette/RGBA native checks require the actual reported `cwebp` or
`avifenc` engine, decode its real output, and verify MIME, dimensions,
colors/alpha and unchanged source hashes. A neutral real `jpegtran` path asserts
selected engine, exact decoded RGB and recovery hash. A preserve-all real jpegtran
case keeps non-neutral Orientation=6 and exact ICC bytes with unchanged raw
pixels, then checks the public lossless path retains that display metadata. Native encoders enabled on
oriented sources still require the capable normalizing WordPress editor. Forcing
`wp_image_editors` alone does not select Imagick for neutral WebP: direct GD is
explicitly asserted there. AVIF uses GD decoding when available, otherwise real
Imagick decoding converted to PNG for the same pixel oracle. Native codec support and
WordPress editor support are checked separately: WordPress 6.4 may lack the
AVIF editor API even when Imagick advertises the codec, so that optional editor
case reports `NOT RUN` while external AVIF remains independently testable.
The required native profile still fails when its editor capability is missing.

Failure probes include invalid headers, a PNG with valid IHDR/dimensions but an
undecodable IDAT body, and native-decoder rejection (GD and available Imagick).
Forced public attachment processing must retain the failed source, recovery
backup and old sidecars with truthful current-run failure counters. A failed
cwebp process and a WordPress editor partial-save error verify cleanup of only
caller-owned temporary paths; an unrelated path supplied in error data survives.
The disabled-EXIF child (`-d disable_functions=exif_read_data`) requires safe JPEG
failure/source-and-sidecar retention while PNG generation remains available.
These probes do not certify filesystem crashes, codec fuzzing, arbitrary ICC
profiles, external sidecar repair, or every encoder/version/server combination.

`YOOHW_MO_REQUIRE_NATIVE_QUALITY=1` makes missing Imagick JPEG/PNG/WebP/AVIF or real
`cwebp`, `avifenc`, `jpegtran` support a failure; an unexpected engine fallback
also fails. The representative PHP 8.4 CI profile sets this variable. Optional
local/support-boundary environments explicitly print `NOT RUN` with the missing
engine/format reason, rather than claiming that coverage passed.

`backup-restore-tests.php` checks source/target containment, trusted uploads-root
aliases versus descendant symlinks, private-root/site aliases and canonical
overlap with external uploads (including root aliases), read-only backup
lookup, source-bound references, verified legacy migration/conflicts, existing
backup immutability, safe missing targets/parents, permission bits, unreadable or
empty backups, mixed restore results, and failed sidecar cleanup. Per-blog path
namespaces are checked without claiming a complete multisite test suite.

`backup-io-probe.php` runs only in child CLI processes. It invokes the actual
plugin publishers with a small WordPress/filesystem-copy stub boundary to inject
partial copies, checksum corruption and rename permission failure. Two child
processes contend at an explicit publication barrier and verify exactly one
backup publisher wins. A killed child leaves a partial stage before commit;
the test verifies current original and backup hashes remain intact, then removes
the fixture. These probes are not an atomic filesystem/database transaction,
power-loss/fsync, hostile symlink-race or full WordPress crash-recovery test.
Temporary cleanup is best-effort when permissions deny removal; killed workers
may leave an unpublished stage. No production fault-injection hooks are added.

`queue-tests.php` checks the shared persistent lifecycle through real WordPress
calls and `queue-process-probe.php` child PHP processes bootstrapping the same
disposable WordPress site. A live owner with an expired legacy heartbeat remains
exclusive before and after a real database reconnect. Wrong tokens cannot refresh
or release the mutex; direct optimize/restore/cleanup and queue building report
busy. Directory/symlink lockfiles and a replaced lock inode fail closed; release
retains the inert lockfile.

Queue fixtures cover empty run identity, bounded snapshot batches, two clients
sharing an active run, deleted IDs becoming skipped, new IDs waiting for the next
run, lost-response retries using absolute progress, stale run rejection, force
binding/inheritance/conflict, legacy terminal-state adoption without invented file
operation totals, authoritative reads despite an old process option cache, and
refused option writes without false progress. A child is killed after it persists
`running` and before image work; the next native owner immediately reclaims it
without replaying force. Fixtures restore queue/token/options and remove their
media and backups. These are process and persistence checks, not an atomic
filesystem/database transaction, exactly-once, power-loss, hostile lockfile-race,
multihost filesystem or browser certification. Browser pause/reload/nonce flows
require separate evidence. The native mutex currently requires POSIX `flock`.

## GitHub Actions

`Media Optimizer CI` runs on pull requests, pushes to `main`, and manual dispatch.
Draft PRs run one representative regression environment (declared Tested up to /
PHP 8.4) and emit `Iteration Gate`. Ready PRs, main pushes, and dispatches run
the four declared support boundaries: minimum WordPress / PHP 7.4 and 8.2,
Tested up to / PHP 7.4 and 8.4. The current WordPress boundaries are 6.4 and 7.0.
All runs check PHP syntax, JavaScript syntax, metadata and CI contracts, and diff
whitespace. Final runs also execute WordPress Plugin Check and emit `CI Gate`.
Use `CI Gate` as the required branch-protection signal; configuring protection
remains a repository-owner action.

Each regression environment uses a fresh WordPress install, MySQL 8.0, and
WP-CLI 2.12.0, with GD required before the existing script runs. Thus its image
optimization/backup assertions cannot silently skip because GD is absent.
The representative PHP 8.4 profile additionally requires Imagick and real
`cwebp`, `avifenc`, `jpegtran` quality checks. Other declared boundaries preserve
the GD regressions and report unavailable optional quality branches explicitly.
This suite is not PHPUnit, PHPCS, PHPCompatibility, a browser suite, or a full
image-engine matrix; its selected native paths and quality limits are described
above.

Plugin Check scans a staged payload built with `.distignore`. Errors fail CI;
warnings remain visible and non-blocking (`strict: false`). Only the
`outdated_tested_upto_header` finding is ignored, so new upstream WordPress
releases do not force an untested metadata bump. No warning baseline is claimed.
No workflow publishes the plugin, creates a tag, or deploys a release.

`WordPress Compatibility Canary` runs weekly and can be dispatched manually
after the workflow is on the default branch. It uses the same regression runner
against actual WordPress nightly / PHP 8.4; it is separate from merge approval.
PR changes to the canary or shared runner also trigger it, testing the canary
before those workflow changes are merged.
The reusable `regression.yml` is only invoked by CI/canary, not a separate trigger.

Local CI control-plane checks (Python standard library only):

```bash
python3 tests/ci-contract-tests.py
bash -n bin/run-wp-regression.sh bin/validate-ci-gate.sh
node --check assets/admin.js
```

`bin/run-wp-regression.sh` provisions a new site and database on a disposable CI
runner. It is not a command for an existing Local Sites or production database.
