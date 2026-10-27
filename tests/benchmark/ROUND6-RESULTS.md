# Round 6: opt-in Imagick AVIF444 runtime validation

The plugin now offers **Imagick AVIF color detail → Request4:4:4**. It is off by default and only applies when the plugin's Imagick adapter actually writes an AVIF sidecar. It does not force editor selection, change native arguments/GD/independent custom editors, make alpha lossless or modify original/thumbnail saves.

The implementation is one enum, one narrow adapter setter and one guarded call after color preparation. Missing/invalid stored values normalize todefault; a missing settings POST field retains the current selection. Strict allowlisting rejects non-scalar values without removing the existing capability/nonce guards. The preference sends `heic:chroma=444` to the writer; it is not a universal guarantee for builds that ignore the option. Tested actual outputs declareYUV444, not merely a successful option echo.

## Runtime and resource evidence

Measured on2026-10-27 with WordPress7.1.3 /PHP8.4.27 /Imagick3.8.1 /ImageMagick6.9.13-59 on AppleM1/8GiB. This local stack supplements declared CI boundaries. Twelve sequential fresh PHP processes use the production save seam, actual `YoOhw_Media_Optimizer_Imagick`, AVIF quality82, no external encoder and no test-only chroma/speed subclass. Each input/mode gets its own process and new output directory. Source/private metadata and output hashes are independently checked.

| Input | Display dimensions | Default /444 YUV | Default /444 output RGB MAE | Default /444 whole-process seconds | Default /444 peak RSS MiB |
|---|---|---|---|---|---|
| Public product JPEG |6000×4000|420 /444|1.02 /0.92|4.28 /2.63|483.6 /961.0|
| Public waterfall JPEG |3072×2048|420 /444|1.67 /1.32|1.75 /1.97|324.4 /337.5|
| Public gull JPEG |2272×1704|420 /444|1.39 /1.20|1.15 /1.18|237.7 /247.1|
| Gull EXIF6 derivative |1704×2272|420 /444|1.40 /1.20|1.13 /1.18|249.9 /285.8|
| Gull EXIF2 derivative |2272×1704|420 /444|1.39 /1.20|1.11 /1.24|244.4 /276.7|
| Synthetic P3 RGBA PNG |3840×2160|420 /444|11.35 /0.72|5.86 /6.28|1014.3 /1002.9|

[Per-process measurements](results/2026-10-27-round6/large-measurements.csv), [input identities](results/2026-10-27-round6/inputs.csv), [environment and evidence hashes](results/2026-10-27-round6/environment.json).

All12 output sources remain unchanged; display dimensions, exact ICC state and absence of EXIF/XMP match expectations. The six enabled saves emit actual8-bit YUV444, while these defaults emitYUV420. All three unmodified public JPEG defaults are byte-identical to their round4 Imagick outputs. EXIF-only derivatives retain the original compressed JPEG pixels; correct orientation output MAE is about1.2–1.4 versus28–50 for the independently reconstructed wrong-orientation controls. The large PNG alpha max error remains1/255 in both modes: this setting does not request lossless alpha.

The lab budgets were120seconds and1024MiB per process; all observed runs stayed within them. **These are one-pass observations, not a safe hosting capacity claim.** The24MP444 case nearly doubles observed RSS, and the PNG cases approach the lab ceiling. Process RSS includes WordPress bootstrap, Imagick, encoder and output verification. CLI memory_limit was1024M; it is not itself a hard bound on all native allocations. Timing has no confidence interval or controlled machine load; the faster24MP444 observation is not evidence of a speed improvement. Defaults, worker budgets and concurrency remain unchanged.

Large traces use encoder code at41f58e0 and the production-option probe added atb3bc739. Subsequent runtime delta only adds memory wording to admin help; the entire measured main PHP file matches after that exact string normalization, and the Imagick adapter is byte-identical. The final full regression exercises the unchanged encoder bodies plus the updated form. Version/support metadata and the staging runtime were not changed.

## Integration and failure evidence

The full existing disposable WordPress regression ran with `YOOHW_MO_REQUIRE_NATIVE_QUALITY=1`: **632top-level assertions and every child suite passed**, with no NOT RUN/FAIL/warnings in that local run. This includes:

- New real chroma child:459assertions using public attachment processing, actual non-monochrome `av1C` subsampling bits, default post-MIME byte oracle, quality23/89, untagged/P3 PNG, JPEG EXIF6/2, source/ICC/privacy/display dimensions, P3 raw samples, freshness/force and original fingerprints.
- New fault child:47assertions for actual wandfalse/Exception/Error, no save after setter failure, partial-save WP_Error, scope isolation and independent custom editors. Seeded recovery data/source/old sidecars survive, failed counters are truthful and owned stages are cleaned.
- Six settings children,4assertions each: enable, disable, old form/missing field, invalid array, invalid nonce and denied capability. They call the real WordPress handler and render the settings form; they are not browser authentication/transport certification.
- Larger valid444 candidates preserve old sidecars under skip-larger. Ordinary native failure can fall back to real Imagick444; requested PNG lossless alpha still fails when its native encoder cannot complete.
- Existing native/GD/custom/quality/backup/restore/queue/delivery tests remain active, including real native profile5989 assertions and editor-quality295 assertions. No original fingerprint, force/replay, destructive authority or stored queue schema was changed.

The new test-only bounded BMFF reader is excluded from the runtime; no required decoder or container parser was added to production. Missing optional WordPress AVIF editor support, including WordPress6.4, is explicitly reported as NOT RUN by that optional branch; the representative required native profile must execute real Imagick AVIF. Full final support-boundary CI and independent exact-head review are recorded in PR16.

## Reproduction and limits

Use the [production-option probe](README.md) with `--engine=imagick --codec=avif --avif-imagick-chroma=default|444` on a disposable site. Verify the reported shipping adapter and empty `test_instrumentation.applied_options`; do not substitute round5's test-only `--avif-chroma` control. The save seam itself does not exercise attachment publication/tracking; those are covered by the separate public integration regression above. Private process logs and actual source/output files remain local.

Public JPEG originals and their licenses/credits are pinned in [ROUND4-SOURCES.json](ROUND4-SOURCES.json). The EXIF derivatives replace only EXIF APP1, retain compressed scan bytes/ICC, and set Orientation6/2. The synthetic PNG uses committed `tests/fixtures/display_p3.icc`, dimensions3840×2160, raw channels `R=x*255//3839`, `G=y*255//2159`, `B=35 or220` according to `(x//7+y//11)%2`, and `A=x*255//3839`. It includes a private text fixture that output must remove. It is a resource/privacy test, not an independent photograph or catalog sample. Media terms remain separate from software licensing.

The offline probe's existing disposable config emits a duplicate DISABLE_WP_CRON bootstrap warning; output/error rows were validated independently rather than treating process exit0 as encoding success. Encoder failures, arbitrary delegate behavior, process death/OOM recovery, HDR/16-bit, calibrated Human/browser color review, multihost work and library-wide throughput are not certified here. The product remains an optional writer preference with explicit cost/support caveats. No historical repair, automatic regeneration, version bump, release tag, WordPress.org publication or staging/production deployment is part of this PR.
