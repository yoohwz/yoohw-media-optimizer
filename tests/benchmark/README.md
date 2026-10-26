# Offline image benchmark corpus

This is test tooling only. It does not change plugin defaults, invoke WordPress,
modify uploads, or publish a release. Generated media and reports belong outside
Git and outside a site's upload directory. The runner's measurements describe a
small PoC, not a universal quality or encoder ranking.

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
