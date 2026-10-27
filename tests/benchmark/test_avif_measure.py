#!/usr/bin/env python3
"""Receipt, bitstream-label, and alpha-separation tests without external encodes."""
import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

import numpy as np
from PIL import Image
sys.path.insert(0, str(Path(__file__).parent))
import avif_measure as measure
from run import composite, digest

INFO = '''Image decoded: output.avif
 * Resolution     : 16x16
 * Bit Depth      : 8
 * Format         : YUV420
 * Alpha          : Not premultiplied
 * Range          : Full
 * Color Primaries: 1
 * Transfer Char. : 13
 * Matrix Coeffs. : 6
 * ICC Profile    : Absent
 * XMP Metadata   : Absent
 * Exif Metadata  : Absent
'''


class MeasureTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.corpus, self.encoded = self.root / 'corpus', self.root / 'encoded'
        self.corpus.mkdir()
        self.encoded.mkdir()
        self.source = self.corpus / 'a.png'
        Image.new('RGBA', (16, 16), (180, 40, 70, 128)).save(self.source)
        self.manifest, self.receipt = self.corpus / 'manifest.json', self.encoded / 'receipt.json'
        self.entry = dict(id='a', path='a.png', sha256=digest(self.source), group='alpha', provenance='generated test', color_policy='assume_srgb')
        self.manifest.write_text(json.dumps(dict(images=[self.entry])))
        self.avif = self.encoded / 'a.avif'
        self.avif.write_bytes(b'not decoded in receipt tests')
        self.row = dict(id='a', profile='420_default', source_sha256=self.entry['sha256'], path='a.avif', sha256=digest(self.avif), bytes=self.avif.stat().st_size, seconds=.02, requested_chroma='420')
        self.data = dict(manifest_sha256=digest(self.manifest), source_hashes_unchanged=True, expected_profiles=['420_default'], rows=[self.row])
        self.save()

    def tearDown(self):
        self.temporary.cleanup()

    def save(self, data=None):
        self.receipt.write_text(json.dumps(self.data if data is None else data))

    def test_inspector_actual_chroma_depth_and_privacy(self):
        self.assertEqual(measure.parse_info(INFO, 420)['Format'], 'YUV420')
        for text, requested in ((INFO, 444), (INFO.replace('8\n', '10\n'), 420), (INFO.replace('YUV420', 'YUV400'), None), (INFO.replace('XMP Metadata   : Absent', 'XMP Metadata   : Present'), None), (' * Format : YUV420', None)):
            with self.subTest(text=text, requested=requested), self.assertRaises(ValueError):
                measure.parse_info(text, requested)

    def test_complete_receipt_and_plugin_label(self):
        images, rows = measure.validate_receipt(self.manifest, self.receipt)
        self.assertEqual(len(images), 1)
        self.assertEqual(rows[0]['encoded_path'], str(self.avif.resolve()))
        self.save(dict(manifest_sha256=digest(self.manifest), source_hashes_unchanged=True, images=[dict(self.row, codec='avif'), dict(self.row, codec='webp')]))
        with self.assertRaisesRegex(ValueError, 'profile-label'):
            measure.validate_receipt(self.manifest, self.receipt)
        self.assertEqual(measure.validate_receipt(self.manifest, self.receipt, 'plugin')[1][0]['profile'], 'plugin')

    def test_missing_duplicate_stale_wrong_hash_and_size_rejected_before_output(self):
        invalids = []
        for field, value in (('manifest_sha256', '0' * 64), ('rows', []), ('rows', [self.row, self.row]), ('rows', {}), ('expected_profiles', ['420_default', '444_default'])):
            data = copy.deepcopy(self.data)
            data[field] = value
            invalids.append(data)
        for field, value in (('source_sha256', '0' * 64), ('sha256', '0' * 64), ('bytes', 0), ('seconds', -1), ('id', 'other'), ('path', '../outside.avif')):
            data = copy.deepcopy(self.data)
            data['rows'][0][field] = value
            invalids.append(data)
        (self.root / 'outside.avif').write_bytes(b'outside')
        output = self.root / 'scores'
        for data in invalids:
            self.save(data)
            with self.subTest(data=data), self.assertRaises((ValueError, FileNotFoundError)):
                measure.measure(self.manifest, output, self.receipt, sys.executable, sys.executable)
            self.assertFalse(output.exists())
        self.save()
        self.source.write_bytes(b'mutated')
        with self.assertRaisesRegex(ValueError, 'source hash mismatch'):
            measure.validate_receipt(self.manifest, self.receipt)

    def test_output_overlap_and_symlink_escape_refused(self):
        with self.assertRaisesRegex(ValueError, 'overlaps'):
            measure.measure(self.manifest, self.corpus / 'scores', self.receipt, sys.executable, sys.executable)
        outside = self.root / 'outside.avif'
        outside.write_bytes(self.avif.read_bytes())
        self.avif.unlink()
        self.avif.symlink_to(outside)
        with self.assertRaisesRegex(ValueError, 'escapes'):
            measure.validate_receipt(self.manifest, self.receipt)

    def test_joint_penalty_separates_alpha_from_fixed_source_alpha_color(self):
        reference = Image.new('RGBA', (16, 16), (180, 40, 70, 128))
        candidate = Image.new('RGBA', reference.size, (180, 40, 70, 90))
        def metric(_tool, left, right, directory, _timeout):
            errors = [float(np.abs(np.asarray(composite(left, bg)).astype(float) - np.asarray(composite(right, bg)).astype(float)).mean()) for bg in (0, 255)]
            return dict(ssimulacra2_worst=100-max(errors), artifacts=str(directory))
        with patch.object(measure, 'metric_score', side_effect=metric):
            scores = measure.score_images(reference, candidate, self.root, 'mock')
        self.assertEqual(scores['fixed_source_alpha']['ssimulacra2_worst'], 100)
        self.assertLess(scores['joint']['ssimulacra2_worst'], 100)
        self.assertEqual(scores['alpha_changed_pixels'], 256)
        self.assertEqual(scores['visible_rgb_weighted_mae'], 0)
        self.assertIsNone(scores['opaque_rgb_mae'])
        self.assertEqual(Image.open(self.root / 'fixed-alpha.png').getchannel('A').tobytes(), reference.getchannel('A').tobytes())

    def test_explicit_failed_receipt_or_error_row_is_rejected(self):
        for change in ['execution', 'row']:
            data = json.loads(json.dumps(self.data))
            if change == 'execution':
                data['execution_ok'] = False
            else:
                data['rows'][0]['error'] = 'partial encoder failure'
            self.save(data)
            with self.assertRaises(ValueError):
                measure.validate_receipt(self.manifest, self.receipt)

    def test_success_pipeline_records_common_decoder_and_receipt_cost(self):
        with Image.open(self.source) as image:
            image.save(self.avif, format='AVIF', quality=82)
        self.row.update(sha256=digest(self.avif), bytes=self.avif.stat().st_size, encode_process={'peak_rss_bytes': 1024})
        self.save()
        output = self.root / 'scores'
        def process(args, _timeout):
            if '--info' not in args:
                self.assertEqual(args[1:7], ['-j', '1', '-d', '8', '--upsampling', 'bilinear'])
                with Image.open(self.avif) as image:
                    image.save(args[-1], format='PNG')
            return dict(stdout=INFO, stderr='', returncode=0, timed_out=False)
        with patch.object(measure, 'run_process', side_effect=process), patch.object(measure, 'metric_score', return_value=dict(ssimulacra2_worst=99)):
            self.assertEqual(measure.measure(self.manifest, output, self.receipt, sys.executable, sys.executable), 0)
        report = json.loads((output / 'results.json').read_text())
        row = report['rows'][0]
        self.assertTrue(report['execution_ok'])
        self.assertEqual(row['seconds'], .02)
        self.assertEqual(row['encode_peak_rss_bytes'], 1024)
        self.assertEqual(len(row['decoded_rgba_sha256']), 64)
        self.assertEqual(row['actual_decoded_metadata']['Matrix Coeffs.'], '6')
        self.assertTrue((output / 'a' / '420_default' / 'fixed-alpha.png').is_file())

    def test_source_mutation_during_inspection_is_fatal_and_persisted(self):
        def process(_args, _timeout):
            self.source.write_bytes(b'mutated source')
            return dict(stdout=INFO, stderr='', returncode=7, timed_out=False)
        output = self.root / 'scores'
        with patch.object(measure, 'run_process', side_effect=process):
            self.assertEqual(measure.measure(self.manifest, output, self.receipt, sys.executable, sys.executable), 1)
        report = json.loads((output / 'results.json').read_text())
        self.assertFalse(report['execution_ok'])
        self.assertFalse(report['source_hashes_unchanged'])

    def test_mislabeled_bitstream_is_failed_report_not_pass(self):
        self.data['rows'][0]['requested_chroma'] = '444'
        self.save()
        process = dict(stdout=INFO, stderr='', returncode=0, timed_out=False)
        output = self.root / 'scores'
        with patch.object(measure, 'run_process', return_value=process):
            self.assertEqual(measure.measure(self.manifest, output, self.receipt, sys.executable, sys.executable), 1)
        report = json.loads((output / 'results.json').read_text())
        self.assertFalse(report['execution_ok'])
        self.assertTrue(report['source_hashes_unchanged'])
        self.assertIn('chroma', report['rows'][0]['error'])
        self.assertNotIn(b'\r\n', (output / 'results.csv').read_bytes())
        self.assertNotIn('actual_quality', report['rows'][0])


if __name__ == '__main__':
    unittest.main()
