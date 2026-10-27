# Round 3 native PNG opt-in profile probe — 2026-10-27

Native commands only; no WordPress access, settings, uploads or repository mutations. Apple M1 macOS; cwebp 1.6.0, libavif 1.4.2/libaom3.15.1. Source ICC and encoded source pixels were retained without sRGB conversion. Commands are round2 equivalent baseline plus exact proposed PR10 flags. Each combination ran once; timing includes subprocess startup. Memory is individual encoder maximum RSS from macOS /usr/bin/time, excluding Python decode and pixel comparison.

41 PNG inputs x4 commands =164 successful decodable outputs. Existing corpus PNG subset: 16 graphics,16 alpha,4 natural,4 product_detail. One generated2048px RGBA checker/gradient/text stress example was added locally. No new independent photographic holdout. All source SHA256 remained unchanged; every output retained byte-exact source ICC. WebP lossless meaningful RGB (source alpha>0) and alpha were exact for all41 inputs. AVIF explicit alpha was exact for all41; color remains lossy. Existing AVIF baseline alpha max error was14/255 in the alpha corpus and3/255 in the2048px example. No perceptual score or visual preference was measured.

| Group | Profile | Median bytes | Median encode ms | Max peak RSS MiB |
|---|---|---:|---:|---:|
| graphics (16) | WebP default82 |30490|31.042|4.98|
| graphics (16) | WebP lossless75/m4 |5368|18.545|40.23|
| alpha (16) | WebP default82 |37042|88.307|17.00|
| alpha (16) | WebP lossless75/m4 |18708|139.086|26.94|
| alpha (16) | AVIF default82 |12326|87.485|44.47|
| alpha (16) | AVIF lossless alpha/jobs1 |31432.5|182.269|38.38|
| synthetic2048 (1) | WebP default82 |114218|574.104|89.11|
| synthetic2048 (1) | WebP lossless75/m4 |20916|1084.545|124.14|
| synthetic2048 (1) | AVIF default82 |396571|426.449|144.88|
| synthetic2048 (1) | AVIF lossless alpha/jobs1 |398233|1739.374|135.22|

Paired median candidate/baseline byte ratios: graphics WebP0.3750; alpha WebP0.5056; alpha AVIF2.2065. Four alpha AVIF outputs (alpha-0-2, alpha-1-2, alpha-2-2, alpha-3-2) and the synthetic2048 AVIF output were larger than their original PNG, so production skip_larger_files would discard these candidates. WebP lossless on the8 photographic PNGs was5.81x (natural) /7.20x (product_detail) the current lossy sidecar median paired size, although every candidate remained smaller than its source PNG. These facts support explicit opt-in PNG semantics, not automatic graphics classification or a universal saving claim.

The native-only probe excludes WordPress engine selection, unsupported-engine behavior, temporary cleanup, source/sidecar lifecycle, settings UI, PNG16 rejection, privacy metadata other than specified CLI flags, and browser display. Lead integration tests must cover those surfaces.

Commit candidates: native-profile-measurements.csv and native-profile-provenance.json. Raw encoded outputs, native stdout/stderr, runner and full receipt remain here outside Git.
