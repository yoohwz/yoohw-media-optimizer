#!/usr/bin/env python3
"""Score immutable, receipt-bound AVIF outputs; never encode or touch WordPress."""
import argparse
import csv
import hashlib
import json
import math
from pathlib import Path
import re
import shutil

import numpy as np
from PIL import Image
from run import read_manifest, digest, run_process, color_managed, metric_score, alpha_error


def parse_info(text, requested_chroma=None):
    fields = {key.strip(): value for key, value in re.findall(r'^\s*\*\s+([^:]+):\s*(.*?)\s*$', text, re.M)}
    required = ('Resolution', 'Bit Depth', 'Format', 'Alpha', 'Range', 'Color Primaries',
                'Transfer Char.', 'Matrix Coeffs.', 'ICC Profile', 'XMP Metadata', 'Exif Metadata')
    if any(key not in fields for key in required):
        raise ValueError('incomplete avifdec inspector output')
    result = {key: fields[key] for key in required}
    if result['Format'] not in ('YUV420', 'YUV444') or result['Bit Depth'] != '8':
        raise ValueError('expected 8-bit YUV420 or YUV444')
    if requested_chroma is not None and result['Format'] != 'YUV' + str(requested_chroma):
        raise ValueError('requested chroma does not match bitstream')
    if result['XMP Metadata'] != 'Absent' or result['Exif Metadata'] != 'Absent':
        raise ValueError('unexpected Exif/XMP metadata')
    return result


def validate_receipt(manifest, receipt, label=None):
    manifest, receipt = manifest.resolve(strict=True), receipt.resolve(strict=True)
    images, data = read_manifest(manifest), json.loads(receipt.read_text())
    if not isinstance(data, dict) or data.get('manifest_sha256') != digest(manifest) or data.get('source_hashes_unchanged') is not True:
        raise ValueError('stale receipt manifest or source hashes')
    if 'rows' in data:
        rows, profiles = data['rows'], data.get('expected_profiles')
    else:
        if not label or not isinstance(data.get('images'), list):
            raise ValueError('plugin receipt requires images array and --profile-label')
        rows = [dict(row, profile=label) for row in data['images'] if isinstance(row, dict) and row.get('codec') == 'avif']
        if any(not isinstance(row, dict) for row in data['images']):
            raise ValueError('receipt row must be an object')
        profiles = [label]
    if not isinstance(rows, list) or not isinstance(profiles, list) or not profiles or any(not isinstance(p, str) or not re.fullmatch(r'[A-Za-z0-9_-]+', p) for p in profiles) or len(set(profiles)) != len(profiles):
        raise ValueError('expected nonempty unique profile and row arrays')
    sources = {entry['id']: entry for entry in images}
    expected, seen, checked = {(key, profile) for key in sources for profile in profiles}, set(), []
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('receipt row must be an object')
        if not isinstance(row.get('id'), str) or not isinstance(row.get('profile'), str):
            raise ValueError('invalid receipt id/profile')
        key = (row['id'], row['profile'])
        if key not in expected or key in seen or row.get('source_sha256') != sources[key[0]]['sha256']:
            raise ValueError('duplicate, unexpected, or stale receipt row')
        seen.add(key)
        relative = row.get('path')
        if not isinstance(relative, str) or Path(relative).is_absolute():
            raise ValueError('encoded path must be relative to receipt')
        encoded = (receipt.parent / relative).resolve(strict=True)
        if not encoded.is_relative_to(receipt.parent) or not encoded.is_file() or encoded.suffix.lower() != '.avif':
            raise ValueError('encoded path escapes receipt directory or is not AVIF')
        if row.get('sha256') != digest(encoded) or type(row.get('bytes')) is not int or row['bytes'] != encoded.stat().st_size:
            raise ValueError('encoded hash or size mismatch')
        if type(row.get('seconds')) not in (int, float) or not math.isfinite(row['seconds']) or row['seconds'] < 0:
            raise ValueError('invalid recorded encode time')
        if 'requested_chroma' in row and str(row['requested_chroma']) not in ('420', '444'):
            raise ValueError('invalid requested chroma')
        checked.append(dict(row, encoded_path=str(encoded)))
    if seen != expected:
        raise ValueError('incomplete expected image/profile matrix')
    for entry in images:
        with Image.open(entry['source']) as image:
            if image.format != 'PNG' or image.mode not in ('RGB', 'RGBA') or max(image.size) > 1600 or min(image.size) < 8 or Path(entry['source']).read_bytes()[24] != 8 or image.getexif() or image.info.get('xmp'):
                raise ValueError('source must be canonical 8-bit PNG, 8..1600 pixels, without Exif/XMP')
    return images, checked


def score_images(reference, candidate, directory, metric):
    fixed = candidate.copy()
    fixed.putalpha(reference.convert('RGBA').getchannel('A'))
    reference.save(directory / 'reference.png')
    candidate.save(directory / 'decoded.png')
    fixed.save(directory / 'fixed-alpha.png')
    scores = {}
    for name, image in (('joint', candidate), ('fixed_source_alpha', fixed)):
        folder = directory / name
        folder.mkdir()
        scores[name] = metric_score(metric, reference, image, folder, 120)
    left, right = np.asarray(reference.convert('RGBA')).astype(float), np.asarray(candidate.convert('RGBA')).astype(float)
    error, weights = np.abs(left[:, :, :3] - right[:, :, :3]).mean(axis=2), left[:, :, 3] / 255
    opaque = left[:, :, 3] == 255
    return dict(scores, **alpha_error(reference, candidate),
                alpha_changed_pixels=int(np.count_nonzero(left[:, :, 3] != right[:, :, 3])),
                visible_rgb_weighted_mae=float((error * weights).sum() / weights.sum()) if weights.sum() else None,
                opaque_rgb_mae=float(error[opaque].mean()) if opaque.any() else None)


def measure(manifest, output, receipt, avifdec, metric, label=None):
    if output.is_symlink():
        raise FileExistsError('output must be fresh')
    manifest, receipt, output = manifest.resolve(strict=True), receipt.resolve(strict=True), output.resolve()
    if any(output == parent or output.is_relative_to(parent) or parent.is_relative_to(output) for parent in (manifest.parent, receipt.parent)):
        raise ValueError('output overlaps input or receipt directories')
    if output.exists() or output.is_symlink():
        raise FileExistsError('output must be fresh')
    images, rows = validate_receipt(manifest, receipt, label)
    inputs = {str(path): digest(path) for path in [manifest, receipt] + [Path(entry['source']) for entry in images] + [Path(row['encoded_path']) for row in rows]}
    sources = {entry['id']: entry for entry in images}
    decoder_hash = digest(shutil.which(avifdec) or avifdec)
    output.mkdir(mode=0o700)
    decoder_flags = ['-j', '1', '-d', '8', '--upsampling', 'bilinear']
    report = dict(manifest_sha256=inputs[str(manifest)], receipt_sha256=inputs[str(receipt)], rows=[], execution_ok=False, decoder_flags=decoder_flags, decoder_sha256=decoder_hash)
    try:
        for encoded in rows:
            row = dict(encoded, status='error', requested_label=encoded['profile'], encode_peak_rss_bytes=encoded.get('enc_process', {}).get('peak_rss_bytes') if isinstance(encoded.get('enc_process'), dict) else None)
            report['rows'].append(row)
            directory = output / encoded['id'] / encoded['profile']
            directory.mkdir(parents=True)
            try:
                row['inspector_process'] = run_process([avifdec, '--info', encoded['encoded_path']], 120)
                inspection = row['inspector_process']
                if inspection['returncode'] or inspection['timed_out']:
                    raise ValueError('AVIF inspection failed')
                row['actual_decoded_metadata'] = parse_info(inspection['stdout'], encoded.get('requested_chroma'))
                entry = sources[encoded['id']]
                with Image.open(entry['source']) as source, Image.open(encoded['encoded_path']) as actual:
                    if actual.format != 'AVIF' or source.size != actual.size or source.info.get('icc_profile') != actual.info.get('icc_profile') or actual.getexif() or actual.info.get('xmp'):
                        raise ValueError('AVIF format, dimensions, ICC, or Exif/XMP mismatch')
                    source_icc = source.info.get('icc_profile')
                    row['actual_icc_sha256'] = hashlib.sha256(source_icc).hexdigest() if source_icc else None
                    reference = color_managed(source, assume_srgb=entry.get('color_policy') in ('assume_srgb', 'untagged_srgb')).convert('RGBA')
                row['decode_process'] = run_process([avifdec, *decoder_flags, encoded['encoded_path'], directory / 'raw-decoded.png'], 120)
                if row['decode_process']['returncode'] or row['decode_process']['timed_out']:
                    raise ValueError('AVIF decode failed')
                with Image.open(directory / 'raw-decoded.png') as image:
                    if image.info.get('icc_profile') != source_icc:
                        raise ValueError('decoded PNG ICC mismatch')
                    row['decoded_rgba_sha256'] = hashlib.sha256(image.convert('RGBA').tobytes()).hexdigest()
                    row['decoded_rgb_sha256'] = hashlib.sha256(image.convert('RGB').tobytes()).hexdigest()
                    candidate = color_managed(image, assume_srgb=entry.get('color_policy') in ('assume_srgb', 'untagged_srgb')).convert('RGBA')
                row.update(score_images(reference, candidate, directory, metric))
                row.update(status='ok', execution_ok=True, requested_label=encoded['profile'], metric_directory=str(directory))
            except Exception as error:
                row.update(error=str(error), execution_ok=False)
    finally:
        report['source_hashes_unchanged'] = all(Path(path).is_file() and digest(path) == value for path, value in inputs.items())
        report['execution_ok'] = report['source_hashes_unchanged'] and len(report['rows']) == len(rows) and all(row.get('execution_ok') for row in report['rows'])
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')
        with (output / 'results.csv').open('w', newline='') as handle:
            fields = ['id', 'profile', 'status', 'execution_ok', 'bytes', 'seconds', 'encode_peak_rss_bytes', 'alpha_max_error', 'alpha_mean_error', 'alpha_changed_pixels', 'visible_rgb_weighted_mae', 'opaque_rgb_mae', 'joint_ssimulacra2_worst', 'fixed_source_alpha_ssimulacra2_worst', 'error']
            writer = csv.DictWriter(handle, fieldnames=fields, lineterminator='\n')
            writer.writeheader()
            for row in report['rows']:
                writer.writerow({key: row.get(key, row.get(key.removesuffix('_ssimulacra2_worst'), {}).get('ssimulacra2_worst') if key.endswith('_ssimulacra2_worst') else None) for key in fields})
    return 0 if report['execution_ok'] else 1


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest', type=Path)
    parser.add_argument('output', type=Path)
    parser.add_argument('--receipt', type=Path, required=True)
    parser.add_argument('--profile-label')
    parser.add_argument('--avifdec', required=True)
    parser.add_argument('--ssimulacra2', required=True)
    args = parser.parse_args()
    raise SystemExit(measure(args.manifest, args.output, args.receipt, args.avifdec, args.ssimulacra2, args.profile_label))
