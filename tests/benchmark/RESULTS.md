# Round 1: offline image-quality PoC

Measured on 2026-10-26 on Apple M1 / 8 GiB RAM. Native tools: cwebp 1.6.0, libavif 1.4.2 / libaom 3.15.1, SSIMULACRA2 from jpeg-xl 0.12.0. The local actual-plugin seam used WordPress 7.1.3 / PHP 8.4.21 / Imagick 7.1.2-23; declared support boundaries remain unchanged and are checked separately in CI. This report supports follow-up choices; it does not change or certify production defaults. [Reproduction and methodology](README.md).

## Evidence

- 64 inputs: 32 derivatives from eight photographic masters, 16 synthetic graphics and 16 synthetic alpha cases. Product/detail cases are proxies, not a catalog dataset.
- Frozen external run: 512 rows = 476 scored outputs, 32 lossless-not-applicable cases and four raw-command EXIF exclusions. Actual plugin run: 128/128 scored outputs. ICC-only control: 62 scored outputs and two EXIF exclusions. Policy probes: two expected preflight rejections.
- Source hashes remained unchanged in all four runs. All 124 neutral actual-plugin outputs are byte-identical to the corresponding raw-command baselines. Four oriented plugin outputs used Imagick and are measured separately.
- [Per-case measurements](results/2026-10-26/measurements.csv), [input hashes](results/2026-10-26/inputs.csv), [environment and evidence hashes](results/2026-10-26/environment.json), [actual plugin output receipt](results/2026-10-26/plugin-baseline.json).

## Matched-input group results

Each row has 16 cases. Baselines below are actual plugin outputs; candidates are external experiments. Bytes and scores are separate medians, not a quality-matched codec comparison. SSIMULACRA2 is the worse of black/white sRGB composites. Alpha error is the maximum 8-bit channel error in that group.

| Group | Profile | Median bytes | Median score | Lowest score | Max alpha error /255 |
|---|---|---:|---:|---:|---:|
| natural | `webp_baseline82` | 32,725 | 79.30 | 64.30 | 0 |
| natural | `webp_sharp82_srgb` | 32,259 | 79.50 | 78.63 | 0 |
| natural | `avif_baseline82` | 43,996 | 88.69 | 86.61 | 0 |
| natural | `avif_444_70_srgb` | 32,920 | 83.91 | 83.04 | 0 |
| product_detail | `webp_baseline82` | 15,419 | 83.19 | 73.40 | 0 |
| product_detail | `webp_sharp82_srgb` | 15,138 | 84.00 | 77.39 | 0 |
| product_detail | `avif_baseline82` | 22,644 | 90.57 | 82.73 | 0 |
| product_detail | `avif_444_70_srgb` | 15,802 | 88.14 | 80.96 | 0 |
| graphics | `webp_baseline82` | 30,183 | 64.85 | 45.40 | 0 |
| graphics | `webp_sharp82_srgb` | 40,114 | 82.41 | 70.88 | 0 |
| graphics | `webp_lossless_srgb` | 5,587 | 100.00 | 100.00 | 0 |
| graphics | `avif_baseline82` | 10,208 | 92.91 | 89.24 | 0 |
| graphics | `avif_420_82_srgb` | 10,810 | 66.49 | 49.48 | 0 |
| graphics | `avif_444_70_srgb` | 9,000 | 91.81 | 87.94 | 0 |
| alpha | `webp_baseline82` | 36,744 | 75.69 | 59.91 | 0 |
| alpha | `webp_sharp82_srgb` | 40,231 | 84.75 | 75.90 | 0 |
| alpha | `webp_lossless_srgb` | 18,172 | 100.00 | 100.00 | 0 |
| alpha | `avif_baseline82` | 12,326 | 92.93 | 90.06 | 14 |
| alpha | `avif_444_70_srgb` | 10,602 | 92.00 | 89.50 | 15 |

## Paired findings and follow-up choices

1. **Color handling merits the next implementation round.** The isolated `webp_icc82` control changes only metadata copying; it does not change effort or sharp-YUV settings. It adds about 0.6 KB for tagged sources. Scores can improve or regress depending on image and compression error; preserving ICC is not a universal score improvement. The declared profile must match its pixel values, and a deliberate preserve-gamut versus sRGB policy is still required. Neither policy is authorized as a production default by this report.

2. **Do not enable method 6 globally.** Sharp-YUV/method-6/sRGB is a combined experiment. On graphics its median paired score gain is +18.36 with median bytes ratio 1.143; alpha gains +10.74 with bytes ratio 1.010. Photo/detail benefits are small or mixed, and some cases regress. Alpha encoder cost rises sharply; see the timing table below. These results do not isolate the contribution of each flag.

3. **Trial content-specific WebP lossless in round 3.** Against actual WebP baseline, median paired bytes ratios are 0.432 for graphics and 0.488 for alpha, with score 100 in the normalized sRGB comparison and alpha error zero. These are highly compressible procedural fixtures. They do not establish production savings or preservation of original wide-gamut pixels after conversion. Lossless effort 6 also costs substantially more CPU.

4. **Keep AVIF chroma choices content-aware.** The 4:2:0 quality-82 candidate has median paired score deltas -25.34 on graphics and -10.32 on alpha, with no median size advantage in these groups. Do not promote it as a universal optimization. AVIF 4:4:4 quality 70 has median paired bytes ratio 0.746 on both photographic groups, but median score deltas -4.09 and -2.82 respectively. It needs a broader corpus and human evaluation before adoption.

5. **Treat alpha quality separately.** Actual AVIF quality 82 reaches maximum alpha error 14/255; the quality-70 candidate reaches 15/255. These are measured differences, not proof of a visible defect in every image. WebP alpha was exact in the scored runs. Round 3 should evaluate an explicit alpha-quality policy rather than assuming color quality implies lossless transparency.

## Encoder cost examples

These rows are from the external run, avoiding comparison of PHP seam overhead with subprocess timing. All have 16 cases. Preprocessing and metric time are excluded. Peak RSS is the per-process p95, not whole-system or end-to-end memory. AVIF baseline retains tool defaults; candidates pin one job. Single-pass timing is exploratory, not a throughput guarantee.

| Group | Profile | Median encode ms | p95 encode ms | p95 peak RSS MiB |
|---|---|---:|---:|---:|
| graphics | `webp_baseline82` | 31.7 | 39.9 | 5.0 |
| graphics | `webp_sharp82_srgb` | 70.4 | 89.4 | 7.1 |
| graphics | `webp_lossless_srgb` | 1181.5 | 4146.1 | 59.6 |
| alpha | `webp_baseline82` | 89.1 | 108.0 | 16.9 |
| alpha | `webp_sharp82_srgb` | 2491.9 | 3324.8 | 85.1 |
| alpha | `webp_lossless_srgb` | 1270.5 | 5872.5 | 62.2 |
| alpha | `avif_baseline82` | 89.2 | 134.6 | 39.5 |
| alpha | `avif_444_70_srgb` | 139.3 | 250.0 | 39.9 |

## Visual and validation evidence

Unblinded 1:1 agent inspection covered the four crop cases recorded in environment.json: an astronaut face/detail, a P3 gradient with text, saturated graphics edges, and a soft alpha edge over white. The baseline WebP crops showed color/edge differences; lossless matched the normalized synthetic reference, and AVIF retained sharper text/color edges in these samples. This is not a blinded human preference panel, a calibrated monitor check, or a browser certification. The generated HTML galleries permit further inspection.

| Check | Result |
|---|---|
| Focused benchmark tests, including stale evidence rejection | PASS — 19 tests |
| Corpus rebuild | PASS — all 64 input and 64 reference hashes match |
| Actual plugin seam and source/output hashes | PASS — 128 outputs, zero errors |
| Native raw-command equivalence | PASS — 124 neutral outputs byte-identical |
| Final external, actual, ICC control, policy probes | PASS — source hashes unchanged; no unexpected errors |
| Probe rejects protected/existing output and escaped input | PASS — rejected before WordPress bootstrap |
| Repository contracts | PASS — 8 tests |
| Python/PHP/JavaScript syntax and diff hygiene | PASS — PHP 8.4; bundled Node 24 used because system Node has a missing dylib |
| Staged distributable excludes benchmark/developer tooling | PASS |
| Full support-boundary regression and Plugin Check | NOT RUN locally — remote exact-head CI result is recorded in the PR handoff |
| Valid CMYK ICC conversion, browser/rendering, full-size camera images, HDR/16-bit, blind human panel, independent holdout | NOT RUN — outside this bounded corpus/evidence |

## Boundaries and recommendation

Proceed to round 2 (explicit color management) after this tooling PR is reviewed and merged. Preserve correct ICC/pixel associations, distinguish privacy metadata from color interpretation, and obtain the Human choice if preserve-gamut versus sRGB changes product semantics. Round 3 can then tune content-specific WebP/AVIF and explicit alpha quality against a larger independent product/photo sample. No production quality, metadata, fingerprint, backup, delivery, version or release behavior changed in this PR.

Raw reports and gallery images are retained locally outside the repository; their SHA256 receipts are recorded above. Committed CSV rows provide durable measurements without committing the large image corpus. Canonical source credits are in SOURCES.json; the initial run manifest predates a conservative collection-author credit correction, which did not change any input/reference pixels or hashes.
