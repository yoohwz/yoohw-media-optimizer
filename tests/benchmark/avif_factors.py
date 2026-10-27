#!/usr/bin/env python3
"""Local-only AVIF factor preparation/encoding; shipping plugin is untouched."""
import argparse
import hashlib
import json
from pathlib import Path
import shutil

from PIL import Image
from run import digest, read_manifest, run_process


def fresh_output(output, protected):
    output = Path(output).resolve()
    protected = [Path(p).resolve() for p in protected]
    if any(output.is_relative_to(p) or p.is_relative_to(output) for p in protected):
        raise ValueError('output overlaps protected input/receipt')
    output.mkdir(mode=0o700, parents=True, exist_ok=False)
    output.chmod(0o700)
    return output


def prepare(manifest, output):
    manifest = Path(manifest).resolve(strict=True)
    entries = read_manifest(manifest)
    selected = [e for e in entries if Path(e['source']).suffix.lower() == '.png']
    if not selected:
        raise ValueError('no PNG controls')
    manifest_hash = digest(manifest)
    out = fresh_output(output, [manifest.parent])
    (out / 'images').mkdir()
    rows = []
    try:
        for entry in selected:
            source = Path(entry['source'])
            header = source.read_bytes()[:29]
            if header[:8] != b'\x89PNG\r\n\x1a\n' or header[24] not in (1, 2, 4, 8):
                raise ValueError('only at-most-8-bit PNG is in scope')
            with Image.open(source) as image:
                image.load()
                if image.getexif().get(274, 1) != 1:
                    raise ValueError('non-neutral PNG orientation')
                icc = image.info.get('icc_profile', b'')
                if not icc and entry.get('color_policy') not in ('assume_srgb', 'untagged_srgb'):
                    raise ValueError('untagged input lacks explicit sRGB assumption')
                rgba = image.convert('RGBA')
                # Start a fresh image so private text/EXIF/XMP do not survive.
                clean = Image.frombytes('RGBA', rgba.size, rgba.tobytes())
                if rgba.getchannel('A').getextrema() == (255, 255):
                    clean = clean.convert('RGB')
                target = out / 'images' / (entry['id'] + '.png')
                clean.save(target, icc_profile=icc or None)
                with Image.open(target) as saved:
                    assert saved.convert('RGBA').tobytes() == rgba.tobytes()
                    assert saved.info.get('icc_profile', b'') == icc
                    assert not saved.getexif() and not saved.info.get('xmp')
                row = {k: v for k, v in entry.items() if k not in ('source', 'path', 'sha256', 'reference_path', 'reference_sha256')}
                row.update(path=target.relative_to(out).as_posix(), sha256=digest(target),
                           original_source_sha256=entry['sha256'], mode=clean.mode,
                           width=rgba.width, height=rgba.height, orientation=1,
                           rgba_sha256=hashlib.sha256(rgba.tobytes()).hexdigest(),
                           icc_sha256=hashlib.sha256(icc).hexdigest() if icc else None,
                           preparation='8-bit RGB/RGBA serialization;  original source encoding pixels and ICC unchanged; privacy stripped')
                rows.append(row)
    finally:
        if digest(manifest) != manifest_hash or any(digest(Path(e['source'])) != e['sha256'] for e in entries):
            raise RuntimeError('source or manifest mutated')
    data = dict(images=rows, source_manifest_sha256=manifest_hash, source_hashes_unchanged=True,
                preparation_script_sha256=digest(Path(__file__)))
    (out / 'manifest.json').write_text(json.dumps(data, indent=2) + '\n')
    return out / 'manifest.json'


def command(backend, tool, source, target, chroma, alpha):
    if backend not in ('avifenc', 'heifenc') or chroma not in ('420', '444') or alpha not in ('82', '100'):
        raise ValueError('unsupported factor')
    if backend == 'avifenc':
        return [tool, '--no-overwrite', '--ignore-exif', '--ignore-xmp', '-c', 'aom',
                '-q', '82', '--qalpha', alpha, '--speed', '6', '--jobs', '1',
                '--depth', '8', '--yuv', chroma, '--cicp', '1/13/6', '--range', 'full', source, target]
    return [tool, '-A', '-e', 'aom', '-q', '82', '-p', 'speed=6', '-p', 'threads=1',
            '-p', 'chroma=' + chroma, '-p', 'alpha-quality=82', '-p', 'lossless-alpha=' + ('true' if alpha == '100' else 'false'),
            '--colour_primaries', '1', '--transfer_characteristic', '13', '--matrix_coefficients', '6',
            '--full_range_flag', '1', '--enable-two-colr-boxes', '-o', target, source]


def encode(manifest, output, backend, executable, timeout=120):
    manifest = Path(manifest).resolve(strict=True)
    entries = read_manifest(manifest)
    for entry in entries:
        with Image.open(entry['source']) as image:
            if image.format != 'PNG' or image.mode not in ('RGB', 'RGBA') or max(image.size) > 1600 or image.getexif().get(274, 1) != 1:
                raise ValueError('expected prepared neutral RGB/RGBA PNG at most1600px')
            if hashlib.sha256(image.convert('RGBA').tobytes()).hexdigest() != entry.get('rgba_sha256'):
                raise ValueError('prepared pixel identity mismatch')
    tool = Path(shutil.which(executable) or executable).resolve(strict=True)
    mh, th = digest(manifest), digest(tool)
    out = fresh_output(output, [manifest.parent, tool])
    profiles = [backend + '_c' + c + '_a' + a for c in ('420', '444') for a in ('82', '100')]
    report = dict(manifest_sha256=mh, source_hashes_unchanged=False, expected_profiles=profiles,
                  script_sha256=digest(Path(__file__)), binary_sha256=th,
                  version=run_process([tool, '--version'], timeout), backend=backend, rows=[])
    try:
        for entry in entries:
            source = Path(entry['source'])
            for chroma in ('420', '444'):
                for alpha in ('82', '100'):
                    profile = backend + '_c' + chroma + '_a' + alpha
                    target = out / (entry['id'] + '-' + profile + '.avif')
                    process = run_process(command(backend, tool, source, target, chroma, alpha), timeout)
                    row = dict(id=entry['id'], profile=profile, path=target.name,
                               source_sha256=entry['sha256'], requested_chroma=chroma,
                               requested_color_quality=82, requested_alpha_policy=alpha,
                               requested_speed=6, requested_threads=1,
                               seconds=process['wall_seconds'], encode_process=process)
                    report['rows'].append(row)
                    if process['returncode'] or process['timed_out'] or not target.is_file() or not target.stat().st_size:
                        row['error'] = 'encoder failed, timed out or produced empty output'
                        raise RuntimeError(row['error'])
                    row.update(sha256=digest(target), bytes=target.stat().st_size)
                    if digest(source) != entry['sha256']:
                        raise RuntimeError('source mutated')
            print(entry['id'], flush=True)
    finally:
        report['source_hashes_unchanged'] = digest(manifest) == mh and all(digest(Path(e['source'])) == e['sha256'] for e in entries)
        report['execution_ok'] = report['source_hashes_unchanged'] and digest(tool) == th and len(report['rows']) == len(entries) * 4 and all('sha256' in r for r in report['rows'])
        (out / 'receipt.json').write_text(json.dumps(report, indent=2) + '\n')
    if not report['execution_ok']:
        raise RuntimeError('incomplete or mutated run')
    if digest(tool) != th:
        raise RuntimeError('encoder binary changed')
    return out / 'receipt.json'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='action', required=True)
    prep = sub.add_parser('prepare')
    prep.add_argument('manifest', type=Path)
    prep.add_argument('output', type=Path)
    enc = sub.add_parser('encode')
    enc.add_argument('manifest', type=Path)
    enc.add_argument('output', type=Path)
    enc.add_argument('--backend', choices=['avifenc', 'heifenc'], required=True)
    enc.add_argument('--executable')
    args = parser.parse_args()
    if args.action == 'prepare':
        print(prepare(args.manifest, args.output))
    else:
        print(encode(args.manifest, args.output, args.backend, args.executable or ('heif-enc' if args.backend == 'heifenc' else 'avifenc')))


if __name__ == '__main__':
    main()
