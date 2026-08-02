# Regression checks

Run the integration regression checks against a disposable or local WordPress site:

```bash
wp eval-file wp-content/plugins/yoohw-media-optimizer/tests/regression-tests.php
```

The script creates uniquely named temporary files, verifies picture fallbacks,
sidecar path containment, idempotent original optimization, and private backup
placement, then restores plugin options and removes its temporary files.
