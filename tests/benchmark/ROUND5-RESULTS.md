# Round 5: isolated AVIF chroma and alpha PoC

Measured on2026-10-27, after merged PR14 `086a24e`; shipping runtime remains byte-identical to `0c2b0f6`. On these controls, the **Imagick chroma setting explains most of the large graphics difference observed in round4**. Alpha precision is a separate, demonstrably isolated tradeoff. This is a bounded development PoC, not a runtime/default change.

## Questions and evidence

| Question | Answer on this exact stack |
|---|---|
| Does an Imagick chroma option affect the actual file? |Yes: decoder-inspected YUV420/444, not merely successful setOption calls|
| Does the instrumented baseline change other behavior? |The production Imagick anchor and explicit420/speed6 output are byte-identical on all45inputs|
| Does changing chroma alter alpha? |No decoded alpha change on all45paired Imagick inputs; inspect per-input hashes for the other controlled contrasts|
| Can alpha be isolated from reconstructed color? |Yes: each of four native/libheif alpha contrasts preserves decoded RGB45/45; the28opaque output files are byte-identical within each contrast|
| Can this IM6 writer independently set alpha lossless? |Not exposed in this pinned coder. The libheif CLI bridge proves its own control, not an Imagick feature|
| Are defaults, integration or release changed? |No; retain the existing optional native lossless-alpha path and evaluate Imagick444 as a separate Controlled runtime candidate|

## Frozen experiment

45existing PNG inputs /21independent masters, selected from round4:28opaque and17transparent, maximum1600px. Synthetic graphics/alpha each have16correlated encodings of four designs; photographic controls include four natural, four product/detail proxies and three public1600px derivatives. Two private artwork/alpha sources supplement them. This is not a representative catalog, blind holdout, original JPEG or full-resolution camera test.

Preparation serializes neutral8-bit RGB/RGBA while preserving every decoded source RGBA byte and exact ICC. Opaque images remain RGB to avoid adding artificial alpha items; genuine transparency remains RGBA. EXIF/XMP/text are removed before every backend sees the same prepared input. An initial bridge pilot exposed the all-RGBA opaque-alpha confound; those pilot artifacts are retained separately and excluded from the final540outputs.

| Profile family | Cells ×inputs | Settings |
|---|---:|---|
| Actual plugin native/Imagick anchors |2×45|Shipping save wrapper, quality82, default backend routing/settings|
| Native avifenc |4×45|YUV420/444 ×alpha quality82/100; color82/speed6/jobs1/depth8|
| libheif CLI bridge |4×45|Chroma420/444 ×lossless-alpha false/true; color82/alpha-quality82/speed6/threads1 fixed|
| Test-only Imagick controls |2×45|Only chroma420/444 changes; color82/speed6, existing thread policy fixed|

**540/540 outputs succeeded.** Actual decoder inspection confirms8-bit YUV420/444, full range, dimensions, alpha mode and ICC/privacy. The450controlled outputs use CICP1/13/6/full; production Imagick also uses it. The native production anchor uses2/2/6 on35tagged sources and1/13/6 on10untagged sources. Source ICC remains authoritative for the ICC-aware measurement; comparing an anchor to a controlled native profile is not a one-factor contrast.

Imagick3.8.1 /ImageMagick6.9.13-59 links libheif1.23.6; native avifenc1.4.2 and the libheif AOM bridge use AOM3.15.1. Local WordPress7.1.3 /PHP8.4.27 /AppleM1/8GiB supplement declared CI boundaries. No system/runtime installation was changed. [Environment, hashes and receipts](results/2026-10-27-round5/environment.json), [inputs](results/2026-10-27-round5/inputs.csv), [540measurements](results/2026-10-27-round5/measurements.csv), [paired summaries](results/2026-10-27-round5/paired-summary.csv), [integrity controls](results/2026-10-27-round5/integrity.json).

## Chroma contrast within Imagick

Each delta and bytes ratio is computed on the same input, then summarized. Scores are common-decoder ICC→SDR-sRGB SSIMULACRA2, worst black/white composite. They are not universal perceptual acceptance thresholds.

| Content | Inputs | Median joint-score gain420→444 | Lowest individual gain | Median bytes444/420 |
|---|---:|---:|---:|---:|
| Synthetic graphics |16|26.795|16.189|0.942|
| Synthetic alpha |16|11.082|3.544|0.979|
| Private artwork |1|1.050|1.050|1.054|
| Private alpha illustration |1|1.595|1.595|1.180|
| Natural photographs |4|0.842|−0.069|1.066|
| Product/detail proxies |4|0.001|−0.010|1.001|
| Public product1600px |1|0.957|0.957|1.461|
| Public camera-photo1600px |2|3.209|2.476|1.240|

For context, median graphics score is65.38 for the Imagick production anchor,93.96 for its444control and93.12 for the native production anchor. On synthetic alpha,81.24/93.70/93.12 respectively. These level medians must not be subtracted as a substitute for paired deltas. The within-Imagick intervention keeps source, quality setting, alpha reconstruction and signal fixed; it supports chroma mode as the main explanation on these graphics, without claiming all wrappers become equivalent.

For16alpha controls, the fixed-source-alpha color-only score gain is11.21, close to the joint11.08. In contrast, exact alpha alone changes joint scores by only a small amount below. Chroma444 is not universally smaller or better: two photographic cases regress slightly, and the single public product costs46.1% more bytes. No automatic content rule or global default is justified by this small dataset.

## Alpha contrast independent of chroma

All17genuinely transparent inputs are included below. These are paired median ratios; there are four independent contrasts, not four independent datasets.

| Backend /chroma | Maximum alpha error82→lossless /255 | Median bytes lossless/82 | Median joint-score change | Minimum joint-score change |
|---|---:|---:|---:|---:|
| avifenc420 |14→0|2.072|0.0684|−0.0348|
| avifenc444 |14→0|2.206|0.1219|−0.0155|
| libheif420 |14→0|2.345|0.0743|−0.0892|
| libheif444 |14→0|2.509|0.1347|0.0195|

Each contrast has identical decoded RGB45/45 and identical fixed-source-alpha scores; all28opaque files are byte-identical. All180lossless-alpha outputs have exact source alpha, including68transparent outputs and112opaque negative controls. This isolates alpha precision from color reconstruction in the common decoder. Joint scores need not increase monotonically: exact alpha can remove accidental compensation for color error during compositing. Exact alpha is the relevant contract, not a guaranteed joint-score gain.

IM6's [`heic.c`](https://github.com/ImageMagick/ImageMagick6/blob/6.9.13-59/coders/heic.c) forwards general quality, chroma and speed, but no independent alpha control. Setting an unknown option successfully on a wand would not prove consumption. The [`libheif AOM implementation`](https://github.com/strukturag/libheif/blob/v1.23.6/libheif/plugins/encoder_aom.cc) exposes alpha-quality and lossless-alpha separately; the bridge changes only the lossless-alpha flag while keeping alpha-quality82 fixed. [`avifenc`](https://github.com/AOMediaCodec/libavif/blob/v1.4.2/apps/avifenc.c) supports explicit alpha quality100. Requested82 is not a recovered AV1 bitstream quantizer, and equal settings across wrappers are not matched visual quality.

## Measurement boundaries and cost

One pinned avifdec decodes every candidate with jobs1/depth8/bilinear chroma upsampling. Joint metrics use actual candidate alpha; a second comparison replaces candidate alpha with source alpha on both black/white composites. Weighted visible-RGB MAE, source-opaque RGB MAE and alpha errors/hashes are also recorded. Each metric has its own retained directory. This separates measured color and transparency effects without claiming a calibrated physical display or recovering encoded quantizers.

The common decoder differs from round4's Pillow decode path, so do not directly assign score differences between rounds to one encoder change. Source variants are correlated; ICC-to-sRGB metrics do not certify retained out-of-sRGB gamut, HDR/16-bit or browser appearance. Downsampling/tune/quality mapping and Imagick's default thread policy can still differ between backends. Cross-backend rows are descriptive observations; the within-backend contrasts carry the causal evidence.

Encode phases ran sequentially before scoring. Native/libheif CLI peak RSS reaches80.5/88.0MiB on these at-most1600px inputs. Those values include CLI input/conversion work and exclude PHP/WordPress. Per-image PHP/Imagick RSS is unavailable, not zero. Timing is one final encode per input/profile, without repeated confidence intervals or controlled machine load. These figures do not establish full-resolution throughput, queue budgets or safe worker concurrency.

## Validation and next implementation candidate

| Check | Result |
|---|---|
| Prepared source identity |PASS —45original/canonical RGBA and ICC identities;28RGB/17RGBA; source hashes retained|
| Matrix/output/receipt bindings |PASS —540complete outputs and6scored reports; shared source/decoder/metric/script hashes|
| Actual metadata/privacy |PASS —540display dimensions and exact source ICC state,8-bit expected YUV, no EXIF/XMP;420tagged profiles retained and120outputs remain untagged|
| Negative/alpha controls |PASS —45Imagick anchor matches; four45RGB/28opaque alpha contrasts;180exact alpha outputs;336opaque joint/fixed-score identities|
| Focused tooling tests |PASS —55tests across existing harness/importer,11PHP probe cases,5factor tests and9measurement tests|
| Syntax/contracts/diff/distribution |PASS —PHP syntax, nine contracts, LF CSV and unchanged shipping payload|
| Fresh independent review /exact-head full CI |Recorded in PR15's final handoff|
| Independent Imagick lossless-alpha cell |NOT RUN —not exposed by this pinned coder; bridge results do not substitute for it|
| Calibrated Human/browser review, JPEG/full-resolution inputs, repeated timing, HDR16-bit, original optimization/queue/backup/delivery |NOT RUN —outside this PoC|

A reasonable next Controlled task is a narrowly scoped, opt-in Imagick AVIF444 sidecar option, with full-size/JPEG performance and failure-path evidence before considering a default change. Preserve the current native-only exact-alpha contract; do not add a nonfunctional Imagick alpha setting or silently fall back to lossy alpha. This round authorizes no runtime integration or default change. Human still owns merge, product decisions and release/deployment.

Reproduce with the [Round5 commands](README.md#round-5-isolated-avif-chroma-and-alpha-poc). Reuse the [round4 source credits and licenses](ROUND4-RESULTS.md#source-credits-and-reproduction); only derived measurements/hashes are committed. Private source paths, images, raw receipts and decoded media stay local. No staging media, version/support metadata, production deployment, tag or publication changed.
