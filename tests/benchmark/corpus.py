#!/usr/bin/env python3
"""Build a bounded offline PoC corpus. Fetch is explicit; output must be new."""
import argparse
import ctypes
import ctypes.util
import hashlib
import io
import json
import platform
import struct
import urllib.request
from pathlib import Path

import numpy as np
from PIL import Image, ImageCms, ImageDraw, ImageFont, ImageOps, features

HERE = Path(__file__).resolve().parent


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def fixed_header(data):
    # ICC timestamps/profile IDs do not affect transforms. Freeze both for rebuilds.
    data = bytearray(data)
    data[24:36] = struct.pack('>6H', 2026, 1, 1, 0, 0, 0)
    data[84:100] = bytes(16)
    return bytes(data)


def rgb_profile(primaries, transfer):
    """Generate matrix/TRC ICC via LittleCMS; no third-party profile artwork."""
    import PIL._imagingcms as imagingcms
    candidates = [ctypes.util.find_library('lcms2'),
                  str(Path(imagingcms.__file__).parent / '.dylibs/liblcms2.2.dylib'),
                  imagingcms.__file__]
    lib = None
    for candidate in candidates:
        if not candidate:
            continue
        try:
            loaded = ctypes.CDLL(candidate)
            getattr(loaded, 'cmsCreateRGBProfile')
            lib = loaded
            break
        except (OSError, AttributeError):
            pass
    if lib is None:
        raise RuntimeError('LittleCMS shared library unavailable; install liblcms2')

    class xyY(ctypes.Structure):
        _fields_ = [('x', ctypes.c_double), ('y', ctypes.c_double), ('Y', ctypes.c_double)]

    class Triple(ctypes.Structure):
        _fields_ = [('Red', xyY), ('Green', xyY), ('Blue', xyY)]

    lib.cmsBuildGamma.argtypes = [ctypes.c_void_p, ctypes.c_double]
    lib.cmsBuildGamma.restype = ctypes.c_void_p
    lib.cmsBuildParametricToneCurve.argtypes = [ctypes.c_void_p, ctypes.c_int, ctypes.POINTER(ctypes.c_double)]
    lib.cmsBuildParametricToneCurve.restype = ctypes.c_void_p
    lib.cmsCreateRGBProfile.argtypes = [ctypes.POINTER(xyY), ctypes.POINTER(Triple), ctypes.POINTER(ctypes.c_void_p)]
    lib.cmsCreateRGBProfile.restype = ctypes.c_void_p
    lib.cmsSaveProfileToMem.argtypes = [ctypes.c_void_p, ctypes.c_void_p, ctypes.POINTER(ctypes.c_uint32)]
    lib.cmsCloseProfile.argtypes = [ctypes.c_void_p]
    lib.cmsFreeToneCurve.argtypes = [ctypes.c_void_p]
    if transfer == 'srgb':
        # LCMS type 4 decodes the standard sRGB piecewise transfer function.
        params = (ctypes.c_double * 5)(2.4, 1 / 1.055, .055 / 1.055, 1 / 12.92, .04045)
        curve = lib.cmsBuildParametricToneCurve(None, 4, params)
    else:
        curve = lib.cmsBuildGamma(None, transfer)
    if not curve:
        raise RuntimeError('LittleCMS curve creation failed')
    white = xyY(.3127, .3290, 1)
    triple = Triple(*(xyY(x, y, 1) for x, y in primaries))
    profile = lib.cmsCreateRGBProfile(ctypes.byref(white), ctypes.byref(triple), (ctypes.c_void_p * 3)(curve, curve, curve))
    try:
        if not profile:
            raise RuntimeError('LittleCMS profile creation failed')
        size = ctypes.c_uint32()
        if not lib.cmsSaveProfileToMem(profile, None, ctypes.byref(size)):
            raise RuntimeError('LittleCMS profile sizing failed')
        buf = ctypes.create_string_buffer(size.value)
        if not lib.cmsSaveProfileToMem(profile, buf, ctypes.byref(size)):
            raise RuntimeError('LittleCMS profile serialization failed')
        return fixed_header(buf.raw[:size.value])
    finally:
        if profile:
            lib.cmsCloseProfile(profile)
        lib.cmsFreeToneCurve(curve)


def transform(image, source, target):
    mode = 'RGBA' if image.mode == 'RGBA' else 'RGB'
    return ImageCms.profileToProfile(image, ImageCms.ImageCmsProfile(io.BytesIO(source)),
                                    ImageCms.ImageCmsProfile(io.BytesIO(target)),
                                    renderingIntent=1, outputMode=mode)


def art(kind, size, alpha=False):
    w, h = size
    y, x = np.mgrid[0:h, 0:w]
    rgb = np.empty((h, w, 3), dtype=np.uint8)
    if kind == 0:  # Smooth ramps + saturated boundary patches.
        rgb[..., 0] = x * 255 // max(w - 1, 1)
        rgb[..., 1] = y * 255 // max(h - 1, 1)
        rgb[..., 2] = (x + y) * 255 // (w + h - 2)
    elif kind == 1:
        rgb[:] = (245, 247, 250)
        rgb[(x // 5 + y // 5) % 2 == 0] = (12, 35, 63)
    elif kind == 2:
        rgb[..., 0] = ((x // 31) % 2) * 255
        rgb[..., 1] = ((y // 29) % 2) * 255
        rgb[..., 2] = (((x + y) // 37) % 2) * 255
    else:
        rgb[:] = (252, 248, 237)
    image = Image.fromarray(rgb)
    draw = ImageDraw.Draw(image)
    font = ImageFont.load_default(size=18)
    for i, label in enumerate(['YoOhw / AaBb 0123456789', 'Fine edges: 1px | RGB | alpha', 'Small text and gradients']):
        yy = 18 + i * 31
        draw.rectangle((12, yy - 3, min(w - 12, 420), yy + 24), fill=(255, 255, 255))
        draw.text((18, yy), label, font=font, fill=(10, 10, 10))
    for i, color in enumerate([(255, 0, 0), (0, 255, 0), (0, 0, 255), (255, 255, 0), (255, 0, 255)]):
        draw.rectangle((i * w // 5, h - 45, (i + 1) * w // 5 - 1, h - 1), fill=color)
    if alpha:
        # Soft edge alpha, fully opaque center, fully transparent outer region.
        radius = np.sqrt(((x - .5*w) / (.48*w))**2 + ((y - .5*h) / (.48*h))**2)
        a = np.clip((1 - radius) * 4, 0, 1)
        image.putalpha(Image.fromarray(np.uint8(np.rint(a * 255))))
    return image


def build(args):
    sources = json.loads((HERE / 'SOURCES.json').read_text())
    cache = args.sources.resolve()
    cache.mkdir(parents=True, exist_ok=True)
    for row in sources['sources']:
        path = cache / row['file']
        if not path.exists():
            if not args.fetch:
                raise RuntimeError(f'Missing source {path}; use --fetch once')
            request = urllib.request.Request(row['url'], headers={'User-Agent': 'YoOhw-offline-benchmark/1'})
            data = urllib.request.urlopen(request, timeout=45).read()
            if hashlib.sha256(data).hexdigest() != row['sha256']:
                raise RuntimeError(f'Source hash mismatch: {row["id"]}')
            # Exclusive creation prevents overwriting unrelated cache content.
            with path.open('xb') as out:
                out.write(data)
        if path.is_symlink() or digest(path) != row['sha256']:
            raise RuntimeError(f'Untrusted source cache: {path}')
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=False)
    (output / 'images').mkdir()
    (output / 'references').mkdir()
    profiles = {
        'srgb': fixed_header(ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes()),
        'display_p3': rgb_profile([(.68, .32), (.265, .69), (.15, .06)], 'srgb'),
        'adobe_rgb_1998': rgb_profile([(.64, .33), (.21, .71), (.15, .06)], 563 / 256),
    }
    (output / 'profiles').mkdir()
    for name, data in profiles.items():
        (output / 'profiles' / (name + '.icc')).write_bytes(data)
    rows = []

    def save(image, ident, group, profile, provenance, master, ext='png', orientation=1, **options):
        path = output / 'images' / (ident + '.' + ext)
        if profile:
            options['icc_profile'] = profiles[profile]
        if orientation != 1:
            exif = Image.Exif()
            exif[274] = orientation
            options['exif'] = exif
        image.save(path, **options)
        with Image.open(path) as decoded:
            decoded.load()
            display = ImageOps.exif_transpose(decoded)
            display = display.convert('RGBA' if 'A' in display.getbands() or 'transparency' in display.info else 'RGB')
            if profile:
                display = transform(display, profiles[profile], profiles['srgb'])
            reference = output / 'references' / (ident + '.png')
            display.save(reference, icc_profile=profiles['srgb'])
            row = dict(id=ident, path=path.relative_to(output).as_posix(), group=group, sha256=digest(path),
                       provenance=provenance, independent_master_id=master, width=decoded.width, height=decoded.height,
                       mode=decoded.mode, icc_profile=profile, orientation=orientation,
                       reference_path=reference.relative_to(output).as_posix(), reference_sha256=digest(reference))
            if not profile:
                row['color_policy'] = 'assume_srgb'
            rows.append(row)

    for source in sources['sources']:
        with Image.open(cache / source['file']) as im:
            base = im.convert('RGB')
        base.thumbnail((768, 768), Image.Resampling.LANCZOS)
        for variant in range(4):
            image = base.copy()
            if variant == 1:
                w, h = image.size
                image = image.crop((w // 8, h // 8, w - w // 8, h - h // 8))
                image.thumbnail((384, 384), Image.Resampling.LANCZOS)
            profile = ['srgb', None, 'display_p3', 'adobe_rgb_1998'][variant]
            if profile and profile != 'srgb':
                image = transform(image, profiles['srgb'], profiles[profile])
            orient = 6 if source['id'] == 'camera' and variant == 0 else 2 if source['id'] == 'coffee' and variant == 3 else 1
            ext = 'png' if variant == 2 else 'jpg'
            options = {} if ext == 'png' else dict(quality=55 if variant == 1 else 95, subsampling=2 if variant == 1 else 0)
            save(image, f'{source["id"]}-{variant}', source['group'], profile,
                 dict(source_id=source['id'], author=source['author'], license=source['license'],
                      source_page=source['source_page'], master_sha256=source['sha256'],
                      derivative=['resized JPEG q95', 'center crop resized JPEG q55', 'resized sRGB-to-P3 PNG', 'resized sRGB-to-AdobeRGB JPEG q95'][variant]),
                 source['id'], ext, orient, **options)

    sizes = [(768, 512), (640, 480), (512, 768), (320, 240)]
    for kind in range(4):
        for variant in range(4):
            profile = ['srgb', 'display_p3', 'adobe_rgb_1998', None][variant]
            image = art(kind, sizes[kind])
            # Synthetic RGB numbers are defined IN their tagged encoding, including
            # out-of-sRGB P3 patches; photos above are genuinely CMS-transformed.
            save(image, f'graphics-{kind}-{variant}', 'graphics', profile,
                 dict(type='synthetic', author='YoOhw benchmark corpus.py', license='GPL-2.0-or-later',
                      recipe=f'art({kind}); RGB values defined in {profile or "assumed sRGB"}'),
                 f'synthetic-graphics-{kind}')
            image = art(kind, sizes[kind], alpha=True)
            profile = ['srgb', 'display_p3', None, 'srgb'][variant]
            extra = {}
            if variant == 2:
                image = image.quantize(colors=64, method=Image.Quantize.FASTOCTREE)
            elif variant == 3:
                pixels = np.array(image)
                hidden = pixels[..., 3] == 0
                pixels[..., :3][hidden] = (255, 0, 255)
                image = Image.fromarray(pixels)
            save(image, f'alpha-{kind}-{variant}', 'alpha', profile,
                 dict(type='synthetic', author='YoOhw benchmark corpus.py', license='GPL-2.0-or-later',
                      recipe=f'art({kind},alpha=True); variant {variant}; palette+tRNS=2; hidden-magenta=3'),
                 f'synthetic-alpha-{kind}', **extra)

    probes = []
    for ident, image, options, policy, failure in [
        ('invalid-icc', art(0, (128, 96)), {'icc_profile': b'not-an-ICC-profile'}, 'invalid_icc', 'invalid ICC'),
        ('unprofiled-cmyk', art(1, (128, 96)).convert('CMYK'), {}, 'unsupported_cmyk', 'unprofiled CMYK'),
    ]:
        path = output / 'images' / (ident + '.jpg')
        image.save(path, quality=90, **options)
        probes.append(dict(id=ident, path=path.relative_to(output).as_posix(), group='policy_probe', sha256=digest(path),
                           provenance={'type': 'synthetic', 'author': 'YoOhw corpus.py', 'license': 'GPL-2.0-or-later'},
                           color_policy=policy, expected_failure=failure))
    manifest = dict(schema_version=1, corpus='round1-64', images=rows, sources=sources,
                    counts={'natural': 16, 'product_detail': 16, 'graphics': 16, 'alpha': 16, 'independent_photographic_masters': 8},
                    environment=dict(python=platform.python_version(), pillow=Image.__version__, numpy=np.__version__,
                                     littlecms=features.version('littlecms2')),
                    limitations=['32 photographic derivatives share eight masters; 32 cases are synthetic.',
                                 'product_detail uses object/detail/texture proxies; no independent product catalog coverage.',
                                 'CMYK ICC conversion NOT RUN: no reliably redistributable CMYK profile acquired.',
                                 'Original untagged photographic masters are assumed sRGB, not certified camera colorimetry.'])
    (output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    (output / 'policy-probes.json').write_text(json.dumps(dict(schema_version=1, images=probes), indent=2) + '\n')
    print(output / 'manifest.json')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sources', type=Path, required=True, help='explicit source download/cache directory')
    parser.add_argument('--output', type=Path, required=True, help='new exclusive corpus directory; never overwritten')
    parser.add_argument('--fetch', action='store_true', help='explicitly allow fetching missing pinned masters')
    build(parser.parse_args())
