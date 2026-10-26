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

`lossless-jpeg-tests.php` is invoked by the same entry point. It checks a JPEG
with EXIF orientation and a large APP segment, comparing file and decoded RGB
hashes when lossless fallback is unavailable. It covers metadata policies,
force/repeat, controlled binary success/no gain/partial failure, temporary-file
cleanup, existing backups/savings, and the permitted resize path. Temporary
encoder fixtures use an isolated PATH within this CLI process and are removed
afterward. A real `jpegtran` check runs when installed in a standard search
directory; otherwise the output explicitly records `NOT RUN`. Controlled encoder
fixtures do not certify the real encoders. Decoded RGB equality after metadata
stripping does not certify unchanged EXIF display orientation.

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
This suite is not PHPUnit, PHPCS, PHPCompatibility, a browser suite, or a full
image-engine matrix. It does not certify Imagick or the full external encoder
matrix; the optional real `jpegtran` check is limited to this lossless fixture.

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
