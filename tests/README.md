# Regression checks

Run the integration regression checks against a disposable or local WordPress site:

```bash
wp eval-file wp-content/plugins/yoohw-media-optimizer/tests/regression-tests.php
```

The script creates uniquely named temporary files, verifies picture fallbacks,
sidecar path containment, idempotent original optimization, and private backup
placement, then restores plugin options and removes its temporary files.

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
image-engine matrix. It does not cover Imagick or external encoder binaries.

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
