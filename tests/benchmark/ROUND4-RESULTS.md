# Round 4: expanded post-fix image-quality benchmark

Measured on 2026-10-27 against reviewed runtime `0c2b0f6` (PRs12/13), on Apple M1 /8GiB. This is development evidence: **keep existing defaults and optional modes**. The expanded real-content cases contradict a universal lossless-WebP size benefit, while confirming the explicit AVIF lossless-alpha tradeoff. No new encoder integration is justified by this round alone.

## Corpus and execution identity

- **74 inputs /23 independent masters**, not74 independent photographs: the original64 controls retain every source/reference byte;10 additions contain three public photographic masters at original resolution plus1600px derivatives, two private product photos, one private artwork and one private alpha illustration. There are13 photographic masters overall (five original masters are grayscale), eight procedural designs and two private artwork/illustration masters.
- The three public originals are6000×4000,3072×2048 and2272×1704. They are original downloaded JPEG dimensions, not RAW captures or proof of an unedited camera pipeline. Their1600px PNG variants deliberately apply EXIF/ICC→sRGB normalization and resizing. They share their master's identity.
- Private source images/paths, source-page archives, full raw reports, output media and galleries stay outside Git. Public tables use opaque private IDs. These additions were selected before this round's measurements, but the private content was already inspected in staging: this is supplemental content, **not a blinded or untouched holdout**, and only two private products plus one public product do not establish catalog representativeness.
- Actual plugin seam calls: **592 attempts =484 successful scored outputs +108 explicit GD refusals**. Current raw-command controls add234 successful scored outputs; all234 are byte-identical to their corresponding actual plugin outputs. Repeated routes/variants are not independent observations or unique content.
- Real stack: WordPress7.1.3 /PHP8.4.27 /Imagick3.8.1 /ImageMagick6.9.13-59; cwebp1.6.0, avifenc1.4.2 and SSIMULACRA2 from jpeg-xl0.12.0. Python/Pillow/LCMS, binary hashes, manifest/runner/runtime hashes and every run receipt are in [environment.json](results/2026-10-27-round4/environment.json). Probe CLI memory limit was1024MiB, a test setting rather than a hosting capacity guarantee. WordPress7.1.3 supplements declared compatibility boundaries; declarations remain unchanged.

[Per-call measurements](results/2026-10-27-round4/measurements.csv), [input identities](results/2026-10-27-round4/inputs.csv), [group summaries](results/2026-10-27-round4/summary.json), [raw integrity checks](results/2026-10-27-round4/quality-integrity.json).

## Findings on matched inputs

Scores are the worse black/white composite SSIMULACRA2 after ICC→SDR sRGB, with alpha measured separately. They do not certify out-of-sRGB gamut or calibrated display appearance. Quality82 is a codec-specific setting, not equal visual quality across encoders. Medians below come from paired individual rows; they are not ratios of group medians.

| PNG content | Cases | Existing optional WebP lossless: median bytes / default | Median score gain | Interpretation |
|---|---:|---:|---:|---|
| Synthetic graphics |16|0.375|+36.45|Smaller on these highly compressible designs; score100|
| Synthetic alpha |16|0.506|+21.26|Smaller on these procedural samples; score100|
| Private artwork |1|9.968|+22.85|Score100 costs almost10× the default bytes|
| Private alpha illustration |1|6.233|+21.52|Score100 costs over6× the default bytes|

The shipping lossless mode uses cwebp `-lossless -q75 -m4`, unlike round1's experimental effort6/quality100. Its45 PNG outputs preserve visible RGB and alpha exactly in raw source encoding, with exact ICC; RGB hidden under alpha zero is outside the contract. Original JPEG cases do not enter this PNG mode. This result supports keeping lossless **explicitly optional**. A generic “PNG/graphics means lossless is smaller” rule is disproven by the supplemental cases.

| Alpha content | Cases | Default AVIF max alpha error /255 | Lossless-alpha max error | Median bytes / default |
|---|---:|---:|---:|---:|
| Synthetic alpha |16|14|0|2.207|
| Private alpha illustration |1|12|0|1.080|

The existing alpha profile preserves all45 PNG alpha planes exactly; AVIF color remains lossy. It also pins one job, so changes in bytes/time reflect the whole shipping profile rather than an isolated alpha flag. Keep this option when exact transparency is needed; these sizes do not justify making it universal.

Actual native and Imagick routes differ even after the quality reset is fixed. On the same16 synthetic graphics, native AVIF quality82 has median score92.91 versus65.30 for the Imagick-preference run; on16 alpha cases,92.93 versus81.25. This is evidence of encoder-policy differences, **not evidence that PR12 still ignores quality**. Earlier exact post-MIME quality regressions remain separate. Chroma/speed/alpha policy needs an isolated follow-up experiment before assigning a cause or changing fallback defaults. WebP alpha in the Imagick-preference run has maximum error1 versus native0 on this synthetic corpus.

Editor preference is not engine identity: across74 inputs, native and advanced runs each succeed148/148; Imagick preference succeeds148/148 with126 actual Imagick and22 direct-GD outputs; GD preference succeeds40/148 and refuses108. Refusals consist of104 `yoohw_mo_color_editor` and four `yoohw_mo_color_unsupported` cases, with no invented scores. GD-only preservation of tagged inputs is not established by successful untagged cases.

## Full-resolution native costs

| Original | Codec | Output bytes | Score | Actual PHP seam seconds | Matched command seconds | Encoder peak RSS MiB |
|---|---|---:|---:|---:|---:|---:|
| Product6000×4000 |WebP|306554|74.33|1.247|1.242|123.1|
| Product6000×4000 |AVIF|930140|84.80|1.586|0.817|473.8|
| Waterfall3072×2048 |WebP|928600|73.58|0.627|0.547|49.4|
| Waterfall3072×2048 |AVIF|1295594|85.41|0.978|0.664|183.6|
| Gull2272×1704 |WebP|144974|69.79|0.314|0.252|31.7|
| Gull2272×1704 |AVIF|383254|83.36|0.411|0.227|100.6|

The table compares actual same-input outputs, **not codecs at matched perceptual quality**. Encoder RSS is per-child `wait4`, assigned only after raw-command and plugin output hashes match. It excludes WordPress/PHP memory, CMS preparation and end-to-end worker overhead. PHP/editor per-encode RSS is **NOT RUN**. Decode cost in the CSV includes Python startup, EXIF/ICC normalization and PNG serialization, not browser-only decoder cost.

Encoding is single-pass exploratory data, without repeated timing confidence intervals; do not treat these values as batch throughput, worker memory limits or capacity guarantees. Small tests/import preparation overlapped some early exploratory seam timings; matched-command costs ran sequentially after preparation. Warm caches and machine load are uncontrolled. The current evidence does not justify adding parallel workers or changing batch budgets.

## Comparison with round1

The128 old controls and new native outputs use the same saved inputs; [paired deltas](results/2026-10-27-round4/round1-paired-delta.json) preserve each association. ICC retention and corrected editor quality do not guarantee a higher SDR score for every input. The local Imagick/ImageMagick stack changed between rounds, so a delta is a **before/after environment-and-runtime observation**, not a causal attribution to one patch. Round1 evidence remains immutable.

This seam benchmark does not execute core thumbnail creation. Actual browser/FPM thumbnail ICC, post-MIME quality and CMS acceptance are documented separately in [PR13's staging evidence](https://github.com/yoohwz/yoohw-media-optimizer/pull/13#issuecomment-6433562932). It also excludes attachment tracking, backups, queue, delivery and the larger-output skip rule; its bytes ratios are not realized library savings.

## Validation and follow-up

| Check | Result |
|---|---|
| Focused harness/importer/probe tests |PASS —38 tests, including real metric identity/degradation and PHP temporary-bootstrap boundaries|
| Base corpus identity and source immutability |PASS —64 old inputs/references byte-identical;74 final source hashes unchanged|
| Successful output integrity |PASS —484/484 exact source ICC, display dimensions and no EXIF/XMP;45 PNG lossless-WebP visible RGB/alpha and45 AVIF PNG alpha exact|
| Actual route scoring |PASS —484 scores;108 explicit refusals; missing/stale/unlisted failures remain failures|
| Matched raw-command cost attribution |PASS —234/234 successful command outputs exact to actual plugin output hashes; four raw EXIF cases excluded and58 PNG-only-on-JPEG cases not applicable|
| Public/private evidence boundary |PASS —no private source path, media or raw receipt committed; public sources hash-pinned with separate licenses|
| PHP syntax / repository contracts / distribution equality |PASS —nine contracts; shipping payload unchanged from0c2b0f6|
| Independent review / exact-head final CI |Recorded in PR14's exact-head handoff|
| Human calibrated visual review, repeated timing, larger catalog, HDR/16-bit, original-JPEG encoder benchmark |NOT RUN —outside this bounded round|

One initial GD scoring report failed because a known safe refusal code was not listed; it is retained privately. A fresh final scoring run lists only the two observed exact refusal codes. The disposable site's existing duplicate `DISABLE_WP_CRON` definition emits a nonfatal bootstrap warning; configuration was not changed. Neither diagnostic is presented as a clean first-pass run.

Recommended next experiment: isolate native versus editor AVIF chroma/alpha/cost on the observed worst graphics and supplemental content, retaining color/gamut policy and existing options. Keep defaults unchanged, and do not introduce automatic content selection from this small corpus. A new JPEG encoder needs its own original-JPEG evidence; this sidecar benchmark cannot select one. Any runtime implementation remains a separate Controlled PR with required invariants, tests, review and Human merge decision.

## Source credits and reproduction

| Public master | Author | License and transformations |
|---|---|---|
| [Product photography35](https://commons.wikimedia.org/wiki/File:Product_photography_35.jpg) |Robertgombos|[CC BY-SA4.0](https://creativecommons.org/licenses/by-sa/4.0/); original copied; resized sRGB PNG and codec derivatives for benchmark|
| [Hopetoun Falls](https://commons.wikimedia.org/wiki/File:Hopetoun_falls.jpg) |DAVID ILIFF|[CC BY-SA3.0](https://creativecommons.org/licenses/by-sa/3.0/); original copied; resized sRGB PNG and codec derivatives for benchmark|
| [Gull portrait](https://commons.wikimedia.org/wiki/File:Gull_portrait_ca_usa.jpg) |Dschwen|[CC BY2.5](https://creativecommons.org/licenses/by/2.5/); original copied; resized sRGB PNG and codec derivatives for benchmark|

Use the [import/probe instructions](README.md#expanded-corpus-import), [public source pins](ROUND4-SOURCES.json) and original corpus builder. Give each public original and its1600px derivative the same `independent_master_id`; use the public IDs in inputs.csv and the recorded `max_edge`. Run the probe four times in fresh directories: defaults; `--engine=imagick`; `--engine=gd`; and `--png-webp-mode=lossless --avif-alpha-mode=lossless`. Score both baseline82 profiles with the actual receipt; for GD alone explicitly allow the two refusal codes above. Run the four optional current native command profiles separately for cost/hash comparison. Retain the exact environment and all raw receipts. Private images cannot be independently reproduced without owner-provided access and must remain local.

Creative Commons attribution/license/changes accompany local galleries in `ATTRIBUTION.md`; derivative media remain under their respective source terms, separate from software licensing. No source media, private product names, full-resolution outputs or galleries are part of the plugin package. No version, default, runtime, staging/production media, tag or publication changed.
