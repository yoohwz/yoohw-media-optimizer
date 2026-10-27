# Offline image benchmark corpus

This is test tooling only. It does not change plugin defaults, invoke WordPress,
modify uploads, or publish a release. Generated media and reports belong outside
Git and outside a site's upload directory. The runner's measurements describe a
small PoC, not a universal quality or encoder ranking.

See [the measured round-1 report](RESULTS.md) for findings, per-case data and limitations.
The [round-3 native PNG probe](results/2026-10-27/RESULTS.md) measures the opt-in
moderate-effort WebP and lossless AVIF alpha flags while retaining source ICC.
It includes per-case measurements and receipts, and makes no perceptual-score or
production savings claim.

## Build the 64-case corpus

Requires Python 3.12, Pillow 12.3.0, NumPy 2.3.5 and LittleCMS (Pillow's bundled
macOS library is detected; Linux can supply `liblcms2`). Encoder/runtime versions
are recorded in the manifest. Use the repository's benchmark requirements when
installing the shared harness environment.

```sh
python3 tests/benchmark/corpus.py \
  --sources /tmp/yoohw-benchmark-sources \
  --output /tmp/yoohw-benchmark-round1 --fetch
```

`--fetch` explicitly permits downloading missing masters from immutable URLs in
`SOURCES.json`. Every download and every cached master must match its SHA256.
Subsequent runs omit `--fetch` and use a **new** `--output` directory. Output
creation is exclusive; the builder never recursively cleans, replaces existing
output, or writes to a master. A failed run leaves its partial output for
inspection. The builder does not fetch licenses dynamically or accept unreviewed
source additions.

`manifest.json` contains `images`, an array of 64 objects. Paths are relative to
that manifest. Each object records `id`, `path`, `sha256`, `group`, provenance,
`independent_master_id`, dimensions, mode, profile and EXIF orientation. Its
`reference_path` is a lossless sRGB PNG prepared by decoding the **saved input**,
applying EXIF display orientation, then performing the ICC transform. This avoids
comparing a compressed JPEG against the pixels before its input compression.
References have their own SHA256. Untagged RGB declares `color_policy=assume_srgb`.

| Group | Cases | Independent content / purpose |
| --- | ---: | --- |
| `natural` | 16 | 4 masters: astronaut, cat, cameraman, eagle |
| `product_detail` | 16 | 4 masters: coffee, motion-blurred clock, brick, gravel |
| `graphics` | 16 | 4 procedural designs × 4 encodings |
| `alpha` | 16 | 4 procedural designs × RGBA/palette/hidden-RGB variants |

**There are eight independent photographic masters, not 32 independent photos or
64 photographs.** Each master yields a JPEG q95, cropped/smaller JPEG q55,
CMS-transformed P3 PNG, and CMS-transformed Adobe RGB compatible JPEG q95.
Five masters are grayscale; converting those pixels to RGB does not add color
photographic diversity. `product_detail` is an object/detail/texture proxy; there
is no independent catalog-product dataset in this round. It cannot support
claims about a production library's content distribution. Long edges are at most
768px; original camera-size, HDR and 16-bit workflows remain untested.

## Provenance and ICC semantics

`SOURCES.json` records each master's immutable download URL, hash, author or
credited collection, individual license, source page and pinned license-evidence
source. The source is the scikit-image curated collection; its declarations are
preserved separately from the plugin's GPL license. No photographs or downloaded
ICC profiles are committed. Author names and CC0/public-domain declarations can
be checked in the [pinned collection source](https://github.com/scikit-image/scikit-image/blob/e8a42ba85aaf5fd9322ef9ca51bc21063b22fcae/skimage/data/_fetchers.py).
NASA media also has [NASA's usage guidelines](https://www.nasa.gov/nasa-brand-center/images-and-media/).
The SpaceX rocket image was excluded because its current Flickr license conflicted
with the collection's public-domain statement. Brick/gravel credit identifies the
CC0Textures collection; the collection does not identify an individual photographer.

The builder generates its own matrix/TRC ICC profiles with LittleCMS. sRGB uses
LittleCMS's built-in profile. Display P3 uses D65, the [ICC/Apple P3 primaries and
sRGB transfer function](https://registry.color.org/rgb-registry/displayp3).
Adobe RGB compatible encoding uses D65, primaries from [Adobe's encoding
specification](https://www.adobe.com/digitalimag/pdfs/AdobeRGB1998.pdf) and gamma
563/256; it is generated locally, not Adobe's distributed profile. ICC header
creation time and optional profile ID are fixed for deterministic builds.
Generated profiles are recognized and transformed by Pillow/LittleCMS; this does
not certify every ICC implementation or a calibrated physical display.

Photographic masters are assumed sRGB because their camera colorimetry is not
certified. The builder **transforms their pixel values** before attaching P3 or
Adobe RGB profiles. Procedural designs instead define RGB coordinates directly
in their declared encoding, including saturated P3 patches. Their P3 and sRGB
coordinate twins intentionally differ in displayed color. `graphics-N-1` (P3)
and `graphics-N-3` (untagged) have identical decoded RGB samples. The gradient
and textured pairs (N=0,1) have different sRGB references that expose profile
stripping; pure fully saturated patches may clip to the same sRGB values. Do not treat identical
encoded samples as equivalent displayed color.

`camera-0` has EXIF orientation 6; `coffee-3` has orientation 2. Alpha fixtures
include soft transparent edges, fully opaque/transparent areas, palette+tRNS,
and magenta RGB behind alpha zero. Graphics include small text, ramps, saturated
edges and checkerboards. These are synthetic fixtures, not stock photos.

`policy-probes.json` contains two additional cases outside the 64-case aggregate:
malformed ICC (`expected_failure="invalid ICC"`) and unprofiled CMYK
(`expected_failure="unprofiled CMYK"`). These must be rejected by a color-correct
normalizer; arbitrary sRGB assignment would conceal missing information.
**CMYK ICC conversion: NOT RUN** — no reliably redistributable CMYK profile was
acquired for this round. Browser comparison, display calibration, image-engine
parity and larger independent product/photo corpora need separate evidence.

Rebuild hashes are reproducible in the recorded environment. Different Pillow,
LittleCMS, JPEG/PNG or font versions may produce different byte hashes; record
that environment instead of changing pinned master hashes to hide drift.

## Run the experiments

Create a development virtual environment with Python 3.12 and install
`python3 -m pip install -r tests/benchmark/requirements.txt`. Supply `cwebp`,
`avifenc` (with the `-q` interface), and `ssimulacra2` on PATH. No binary is bundled
with the plugin. Native executable hashes, versions, actual commands, Pillow and
LittleCMS versions are captured in `results.json`.

```sh
python3 tests/benchmark/test_run.py
python3 tests/benchmark/run.py /tmp/yoohw-benchmark-round1/manifest.json \
  /tmp/yoohw-benchmark-external
python3 tests/benchmark/run.py /tmp/yoohw-benchmark-round1/policy-probes.json \
  /tmp/yoohw-benchmark-policy --profiles webp_baseline82
python3 tests/benchmark/run.py /tmp/yoohw-benchmark-round1/manifest.json \
  /tmp/yoohw-benchmark-icc-control --profiles webp_icc82
```

Every output directory must be new. The runner verifies source hashes before
and after processing, never removes an existing directory, and reports encoding,
decoding and metric failures. Expected invalid-profile/CMYK preflight rejection
is recorded separately from successful images. Reports include JSON, CSV and a
local HTML gallery with reference/candidate PNGs for full-size inspection.

The default eight profiles compare the existing external commands at quality 82
with combined candidate configurations: WebP sharp YUV/method 6 with either ICC
preservation or sRGB conversion, WebP lossless for graphics/alpha, and AVIF
speed 6/jobs 1 with 4:2:0 or 4:4:4 at quality 82 plus 4:4:4 at quality 70.
They are experiments, not production defaults. A combined profile comparison
cannot attribute an improvement to one flag. Equal quality numbers do not imply
equal fidelity across codecs. Lossless describes encoded pixel preservation,
not reversal of a preceding gamut conversion.

The optional `webp_icc82` control adds only `-metadata icc` to the baseline
command and skips non-neutral EXIF sources. It isolates profile copying from
method/sharp-YUV changes; profile overhead and perceptual score can be compared
to the corresponding neutral baseline rows.

Each reference is decoded from the saved source, oriented, and transformed to
sRGB with Pillow/LittleCMS. Untagged RGB requires the manifest's explicit sRGB
assumption; unprofiled CMYK and malformed ICC are rejected. Candidate output is
also interpreted through its ICC, or explicitly assumed sRGB if untagged. The
runner then scores opaque black and white composites separately and records the
lower SSIMULACRA2 score plus alpha-channel error. This SDR sRGB comparison does
not measure retained out-of-sRGB gamut, HDR, or calibrated display appearance.
The generated corpus references are a reproducibility cross-check; the runner
computes its own reference from the input. Both use LittleCMS, so this is not
independent color-engine certification.

Raw command baselines do not implement the plugin's EXIF editor routing.
Non-neutral EXIF inputs are excluded from command-baseline quality comparisons.
Use the separate actual-plugin probe below to check that boundary.

## Actual plugin encoder-path probe

Run only on an appropriate local/disposable WordPress installation with this
plugin active and its runtime class byte-identical to the checkout. This invokes
the private sidecar-save seam at quality 82; it does **not** exercise attachment
transactions, backups, tracking, queue, delivery, or the larger-output skip rule.
It never calls attachment optimization or persists option changes. External
encoders are enabled only through a request-local option filter. WordPress
bootstrap still executes the test site's active plugins, so use a test site.

```sh
php tests/benchmark/plugin-baseline.php /path/to/disposable-wordpress \
  /tmp/yoohw-benchmark-round1/manifest.json /tmp/yoohw-benchmark-plugin
python3 tests/benchmark/run.py /tmp/yoohw-benchmark-round1/manifest.json \
  /tmp/yoohw-benchmark-plugin-scored \
  --profiles webp_baseline82 avif_baseline82 \
  --plugin-baseline /tmp/yoohw-benchmark-plugin/plugin-baseline.json
```

Probe output must be outside both the corpus and WordPress trees and must not
exist. Engine identity, output/source hashes, errors and seam wall time are
recorded. The scoring runner checks the manifest hash and each source/output
hash; stale, missing or failed baseline rows are failures. PHP seam peak RSS is
unavailable and remains null.

## Interpreting costs and results

This is one encode per image/profile, with correlated derivatives and no timing
confidence interval or independent holdout. Encode time and peak RSS describe
the encoder subprocess; shared Python source decoding, ICC normalization and
intermediate PNG writes are **not** included in those encoder costs. Decoder
time includes a fresh Python process, Pillow decode, ICC/EXIF normalization and
PNG serialization, rather than a browser's pure decoder cost. Peak RSS uses
per-child `wait4` with platform-specific unit conversion. AVIF candidates pin
one job while the baseline retains encoder defaults; time ratios reflect those
different configurations, not an isolated algorithm comparison.

Choose follow-up experiments from paired rows and worst cases, then inspect
text, edges, gradients and alpha at display size and 1:1. The gallery is labeled;
agent inspection is not a blinded human panel. Do not infer a universal score
threshold, production savings percentage or production-ready color policy.

## Round 4: post-fix expanded baseline

Round 4 reuses this harness on reviewed code after the Imagick quality and core
PNG ICC fixes. Keep the original round-1 evidence immutable. Separate real
product/camera holdout inputs from correlated generated controls, and retain
private media outside Git. Compare actual native, Imagick and GD routes only
where supported; report refusals rather than inventing scores. No production
default, staging media or library-wide processing changes are authorized by
this development benchmark. The final report records exact input, runtime,
probe and runner identities, cost boundaries and remaining Human visual review.

### Expanded corpus import

`expand_corpus.py` imports locally acquired, hash-pinned sources into a new
private directory. The source spec remains private when it contains owner media
paths. It has an `images` array with `id`, `path` (relative to the spec, or
absolute), `sha256`, `group`, `independent_master_id`, `provenance` and explicit
`color_policy=assume_srgb` for untagged RGB. Set `max_edge=0` to copy source bytes
unchanged, or `1600` for an explicitly labeled EXIF/ICC-to-sRGB resized PNG.
Variants of a master share its independent identity; resizing is not extra content.

```sh
python3 tests/benchmark/test_expand_corpus.py
python3 tests/benchmark/expand_corpus.py --base-manifest OLD/manifest.json \
  --sources PRIVATE/import-spec.json --output NEW_CORPUS
```

The importer copies old inputs/references byte-for-byte, prepares references from
the saved new inputs, checks source/spec/reference hashes before and after, and
never fetches, bootstraps WordPress, overwrites sources or removes partial output.
The public additions are pinned in `ROUND4-SOURCES.json`; download explicitly to
a private cache and verify each SHA256 before using it. Their Creative Commons
licenses/attribution apply separately to the benchmark's software license.
Retain author, source link, license link and the resize/encoding transformation
notice with any gallery or redistributed derivative. Private site images have
no redistribution grant and must not be committed or uploaded as CI artifacts.

### Actual routes and existing optional modes

`plugin-baseline.php` accepts `--engine=native|imagick|gd`,
`--webp-quality=1..100`, `--avif-quality=1..100`,
`--png-webp-mode=lossy|lossless` and `--avif-alpha-mode=default|lossless`.
Defaults retain quality82, native routing and ordinary PNG modes. The two
advanced modes require `--engine=native`. Editor preferences disable external
binaries and constrain WordPress editor selection; direct GD WebP routing can
still win. Read each row's actual `engine`, never infer it from a run's name.
Every call still uses the shipping `save_webp_file`/`save_avif_file` wrapper.
The probe verifies all runtime PHP hashes, captures loaded PHP/WordPress/editor
identities, sanitized request-local options and actual codec quality. It does
not mutate attachment records, queue, backups or stored options.

The scorer's baseline82 import rejects recorded qualities other than82. Score
an explicit failure code only as a refusal using repeated
`--expected-refusal=EXACT_CODE`; it never becomes a successful encode or numeric
score. Missing rows, stale hashes and all unlisted failures still fail the run.
No flag is needed for the normal successful native/Imagick runs. Native-only
optional controls `webp_current82`, `avif_current82`,
`webp_png_lossless_current` and `avif_alpha_lossless82_current` reproduce current
shipping command arguments; unlike round1 defaults they retain ICC/privacy.
The raw controls still do not implement plugin EXIF routing. Compare their byte
hashes with actual neutral plugin outputs before attributing their per-child
RSS to a production encoder path. PHP/editor per-encode peak RSS remains
unavailable; process memory and end-to-end costs are not implied by these values.

## Round 5: isolated AVIF chroma and alpha PoC

Round 5 holds source pixels/profile and color quality fixed while varying actual
AVIF chroma420/444 and alpha quality within each available encoder path. It
separates existing plugin anchors from controlled experiments, verifies encoded
chroma through a decoder, and treats unsupported Imagick alpha controls as
unavailable. This development-only PoC does not change shipping defaults or
install/modify the staging runtime. The final report must distinguish within-
backend factor contrasts from cross-backend differences.

Round 5 uses `avif_factors.py prepare` on the expanded round4 manifest, selecting
45 neutral8-bit PNGs (21masters). It serializes opaque inputs as RGB and true
transparency as RGBA, without changing decoded source RGBA or ICC bytes; this
avoids inventing alpha items on opaque controls. Private metadata is stripped.
JPEG inputs/full-resolution throughput are outside this focused experiment.

```sh
python3 tests/benchmark/test_avif_factors.py
python3 tests/benchmark/test_avif_measure.py
python3 tests/benchmark/avif_factors.py prepare ROUND4/manifest.json NEW_CORPUS
python3 tests/benchmark/avif_factors.py encode NEW_CORPUS/manifest.json \
  NEW_AVIFENC --backend avifenc
python3 tests/benchmark/avif_factors.py encode NEW_CORPUS/manifest.json \
  NEW_HEIFENC --backend heifenc
```

Both command backends use color quality82, speed6, one thread and CICP1/13/6
full-range. `avifenc` varies YUV420/444 and alpha quality82/100; `heif-enc` varies
chroma420/444 and only `lossless-alpha=false/true`, with alpha-quality82 fixed.
Quality is a requested encoder setting, not a recovered bitstream quantizer.
The bridge exposes a control absent from IM6; it does not make the equivalent
Imagick setting available. Encoding is sequential; child RSS includes CLI
input/conversion work and is not end-to-end WordPress memory.

The existing PHP probe also accepts `--codec=both|avif` (defaultboth),
`--avif-chroma=default|420|444` and `--avif-speed=default|6`. Custom AVIF controls
require `--engine=imagick`; they run through a test-only subclass at save time,
retaining the full shipping save wrapper, ICC/privacy preparation and quality
filter. Defaults do not load that subclass. `applied_options` means options
stored on the wand, not proof of encoder consumption; inspect emitted chroma.
Use a disposable WordPress root and fresh output for every probe:

```sh
php tests/benchmark/plugin-baseline.php DISPOSABLE_WP NEW_CORPUS/manifest.json \
  NEW_IMAGICK420 --codec=avif --engine=imagick --avif-chroma=420 --avif-speed=6
python3 tests/benchmark/avif_measure.py NEW_CORPUS/manifest.json NEW_SCORE \
  --receipt NEW_IMAGICK420/plugin-baseline.json --profile-label imagick_c420_s6 \
  --avifdec /path/to/avifdec --ssimulacra2 /path/to/ssimulacra2
```

Repeat with444, plus native/default-Imagick anchors with no custom knobs. Score
command `receipt.json` files without `--profile-label`; their explicit expected
matrix must be complete. Missing, duplicate, failed, stale or escaped outputs
fail validation. Scoring hashes source/receipt/output/decoder/metric, verifies
ICC/privacy/dimensions and actual decoded YUV/depth/alpha/CICP, and rejects a
requested chroma that the bitstream does not implement. Common decoding pins
avifdec depth8/jobs1/bilinear; do not directly attribute differences from the
round4 Pillow decoder to one encoder patch.

Joint black/white SSIMULACRA2 includes actual alpha. A second metric replaces
candidate alpha with the source alpha before identical compositing, separating
color reconstruction from alpha quantization in that common decoder. Both are
ICC-managed SDR metrics; neither certifies HDR/wide-gamut displays. Visible-RGB
weighted MAE, source-opaque-region MAE and exact alpha pixel differences provide
separate evidence. Preserve the two metric directories and raw decoder images
when retaining receipts. Imagick PHP per-encode RSS remains unavailable.
