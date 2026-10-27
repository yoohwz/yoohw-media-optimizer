#!/usr/bin/env python3
"""Offline factor tests; no site or real benchmark writes."""
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

from PIL import Image, ImageCms
import avif_factors as F


class FactorsTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.source = self.root / 'source.png'
        image = Image.new('RGBA', (16, 12), (240, 90, 20, 128))
        self.icc = ImageCms.ImageCmsProfile(ImageCms.createProfile('sRGB')).tobytes()
        image.save(self.source, icc_profile=self.icc)
        self.manifest = self.root / 'manifest.json'
        self.row = dict(id='source', path='source.png', group='alpha', independent_master_id='master',
                        provenance='generated test', sha256=F.digest(self.source))
        self.manifest.write_text(json.dumps({'images': [self.row]}))
        self.out = self.root.parent / (self.root.name + '-output')
        self.addCleanup(lambda: __import__('shutil').rmtree(self.out, ignore_errors=True))

    def test_prepared_source_preserves_pixels_and_icc_and_rejects_reuse(self):
        original = self.source.read_bytes()
        prepared = F.prepare(self.manifest, self.out)
        row = json.loads(prepared.read_text())['images'][0]
        with Image.open(self.out / row['path']) as image:
            self.assertEqual(image.info['icc_profile'], self.icc)
            self.assertEqual(hashlib.sha256(image.tobytes()).hexdigest(), row['rgba_sha256'])
            self.assertEqual(image.getpixel((0, 0)), (240, 90, 20, 128))
        self.assertEqual(self.source.read_bytes(), original)
        self.assertEqual(row['original_source_sha256'], self.row['sha256'])
        self.assertEqual(self.out.stat().st_mode & 0o777, 0o700)
        with self.assertRaises(FileExistsError):
            F.prepare(self.manifest, self.out)

    def test_stale_source_and_overlap_do_not_create_output(self):
        self.source.write_bytes(b'changed')
        with self.assertRaisesRegex(ValueError, 'hash mismatch'):
            F.prepare(self.manifest, self.out)
        self.assertFalse(self.out.exists())
        with self.assertRaisesRegex(ValueError, 'overlaps'):
            F.fresh_output(self.root / 'inside', [self.root])
        self.assertFalse((self.root / 'inside').exists())

    def test_one_factor_changes_keep_color_quality_speed_threads_and_cicp_fixed(self):
        for backend in ['avifenc', 'heifenc']:
            a = F.command(backend, 'tool', 'source', 'target', '420', '82')
            b = F.command(backend, 'tool', 'source', 'target', '444', '82')
            c = F.command(backend, 'tool', 'source', 'target', '420', '100')
            self.assertEqual(sum(x != y for x, y in zip(a, b)), 1)
            self.assertEqual(sum(x != y for x, y in zip(a, c)), 1)
            self.assertEqual(len(a), len(b)); self.assertEqual(len(a), len(c))
            self.assertIn('82', a)
            self.assertNotIn('--lossless', c)
        self.assertIn('alpha-quality=82', F.command('heifenc', 'tool', 's', 't', '444', '100'))
        for args in [('unknown', '420', '82'), ('avifenc', '422', '82'), ('heifenc', '444', '50')]:
            with self.assertRaises(ValueError):
                F.command(args[0], 'tool', 's', 't', *args[1:])

    def test_failed_encode_remains_failed_and_preserves_sources(self):
        prepared = F.prepare(self.manifest, self.out)
        output = self.out.parent / (self.out.name + '-encode')
        self.addCleanup(lambda: __import__('shutil').rmtree(output, ignore_errors=True))
        fake = dict(returncode=1, timed_out=False, wall_seconds=.01)
        with patch.object(F, 'run_process', return_value=fake):
            with self.assertRaises(RuntimeError):
                F.encode(prepared, output, 'avifenc', '/usr/bin/true')
        report = json.loads((output / 'receipt.json').read_text())
        self.assertFalse(report['execution_ok'])
        self.assertTrue(report['source_hashes_unchanged'])
        self.assertIn('error', report['rows'][0])


if __name__ == '__main__':
    unittest.main()
