#!/usr/bin/env python3
"""Offline import tests using temporary generated images; no private media."""

import io
import json
from pathlib import Path
import stat
import tempfile
import unittest
from unittest import mock

from PIL import Image, ImageCms

import expand_corpus as EXPAND
from corpus import rgb_profile


class ExpandCorpusTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.base = self.root / "base"
        self.base.mkdir()
        self.master = self.root / "master.png"
        Image.new("RGBA", (40, 24), (220, 30, 80, 90)).save(self.master)
        Image.new("RGB", (24, 16), (100, 70, 20)).save(self.base / "base.png")
        Image.new("RGB", (24, 16), (100, 70, 20)).save(self.base / "reference.png")
        self.base_row = dict(id="base", path="base.png", group="graphics", provenance="generated test",
                             independent_master_id="base-master", color_policy="assume_srgb",
                             sha256=EXPAND.digest(self.base / "base.png"), reference_path="reference.png",
                             reference_sha256=EXPAND.digest(self.base / "reference.png"))
        self.base_manifest = self.base / "manifest.json"
        self.write_base()
        self.spec = self.root / "spec.json"
        self.row = dict(id="imported", path=str(self.master), sha256=EXPAND.digest(self.master), group="alpha",
                        independent_master_id="new-master", provenance={"type": "generated test"}, color_policy="assume_srgb")
        self.write_spec()

    def tearDown(self):
        self.temporary.cleanup()

    def write_base(self):
        self.base_manifest.write_text(json.dumps({"schema_version": 1, "images": [self.base_row]}))

    def write_spec(self, rows=None):
        self.spec.write_text(json.dumps({"images": rows if rows is not None else [self.row]}))

    def expand(self, name="output"):
        target = self.root / name
        return json.loads(EXPAND.expand(self.base_manifest, self.spec, target).read_text()), target

    def test_original_and_base_copy_are_identical_and_repeatable(self):
        before = EXPAND.digest(self.master)
        first, output = self.expand()
        second, second_output = self.expand("second")
        self.assertEqual(first, second)
        self.assertEqual(stat.S_IMODE(output.stat().st_mode), 0o700)
        for row in first["images"]:
            self.assertEqual(EXPAND.digest(output / row["path"]), row["sha256"])
            self.assertEqual(EXPAND.digest(output / row["reference_path"]), row["reference_sha256"])
            self.assertEqual((output / row["path"]).read_bytes(), (second_output / row["path"]).read_bytes())
        self.assertEqual((output / "images/base.png").read_bytes(), (self.base / "base.png").read_bytes())
        self.assertEqual((output / "references/base.png").read_bytes(), (self.base / "reference.png").read_bytes())
        self.assertEqual((output / "images/imported.png").read_bytes(), self.master.read_bytes())
        self.assertEqual(before, EXPAND.digest(self.master))
        imported = first["images"][1]
        self.assertEqual((imported["width"], imported["height"], imported["mode"], imported["orientation"]), (40, 24, "RGBA", 1))
        self.assertIsNone(imported["icc_sha256"])
        self.assertEqual(imported["import_operation"], "byte_identical_copy")
        self.assertNotIn(str(self.master), json.dumps(first))

    def test_original_embedded_icc_and_exif_are_not_reencoded(self):
        profile = rgb_profile([(.68, .32), (.265, .69), (.15, .06)], "srgb")
        source = self.root / "tagged.png"
        image = Image.new("RGB", (40, 24), (180, 110, 70))
        exif = Image.Exif()
        exif[274] = 6
        image.save(source, icc_profile=profile, exif=exif)
        self.row.update(path=str(source), sha256=EXPAND.digest(source))
        self.row.pop("color_policy")
        self.write_spec()
        report, output = self.expand()
        self.assertEqual((output / "images/imported.png").read_bytes(), source.read_bytes())
        row = report["images"][1]
        self.assertEqual(row["orientation"], 6)
        self.assertIsNotNone(row["icc_sha256"])
        with Image.open(output / row["reference_path"]) as reference:
            self.assertEqual(reference.size, (24, 40))

    def test_resize_transforms_p3_and_orientation_before_downsampling(self):
        source = self.root / "p3.png"
        profile = rgb_profile([(.68, .32), (.265, .69), (.15, .06)], "srgb")
        image = Image.new("RGBA", (2000, 1000), (180, 110, 70, 128))
        exif = Image.Exif()
        exif[274] = 6
        image.save(source, icc_profile=profile, exif=exif)
        self.row.update(path=str(source), sha256=EXPAND.digest(source), max_edge=1600)
        self.write_spec()
        report, output = self.expand()
        row = report["images"][1]
        self.assertEqual((row["width"], row["height"]), (800, 1600))
        self.assertEqual(row["orientation"], 1)
        self.assertEqual(row["independent_master_id"], "new-master")
        self.assertEqual(row["source_sha256"], EXPAND.digest(source))
        self.assertNotEqual(row["sha256"], row["source_sha256"])
        self.assertEqual(row["import_operation"], "exif_icc_to_srgb_png_resize")
        with Image.open(source) as original:
            expected = EXPAND.color_managed(original)
            expected.thumbnail((1600, 1600), Image.Resampling.LANCZOS)
        with Image.open(output / row["path"]) as saved:
            self.assertEqual(saved.tobytes(), expected.tobytes())
            self.assertEqual(saved.getpixel((400, 800))[3], 128)
            self.assertNotEqual(saved.getpixel((400, 800))[:3], (180, 110, 70))
            self.assertIn("sRGB", ImageCms.getProfileDescription(ImageCms.ImageCmsProfile(io.BytesIO(saved.info["icc_profile"]))))

    def test_invalid_sources_fail_without_creating_output(self):
        for change, error in [({"id": "base"}, "duplicate"), ({"id": "../escape"}, "invalid image id"),
                              ({"sha256": "0" * 64}, "hash mismatch"), ({"max_edge": -1}, "max_edge"),
                              ({"max_edge": True}, "max_edge"), ({"max_edge": 1000}, "max_edge")]:
            row = dict(self.row, **change)
            self.write_spec([row])
            with self.assertRaisesRegex(ValueError, error):
                self.expand()
            self.assertFalse((self.root / "output").exists())
        self.write_spec([self.row, self.row])
        with self.assertRaisesRegex(ValueError, "duplicate"):
            self.expand()

    def test_missing_color_policy_and_invalid_icc_are_rejected(self):
        self.row.pop("color_policy")
        self.write_spec()
        with self.assertRaisesRegex(ValueError, "explicit assume_srgb"):
            self.expand()
        Image.new("RGB", (20, 20)).save(self.master, icc_profile=b"broken")
        self.row["sha256"] = EXPAND.digest(self.master)
        self.write_spec()
        with self.assertRaisesRegex(ValueError, "invalid ICC"):
            self.expand("invalid-icc")

    def test_overlap_existing_output_and_symlink_escape_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "overlaps"):
            EXPAND.expand(self.base_manifest, self.spec, self.base / "output")
        with self.assertRaisesRegex(ValueError, "overlaps"):
            EXPAND.expand(self.base_manifest, self.spec, self.root)
        output = self.root / "output"
        output.mkdir()
        sentinel = output / "sentinel"
        sentinel.write_text("preserve")
        with self.assertRaises(FileExistsError):
            self.expand()
        self.assertEqual(sentinel.read_text(), "preserve")
        (self.base / "escape.png").symlink_to(self.master)
        self.base_row.update(path="escape.png", sha256=EXPAND.digest(self.master))
        self.write_base()
        with self.assertRaisesRegex(ValueError, "escapes"):
            self.expand("escaped")

    def test_reference_hash_and_containment_are_checked(self):
        self.base_row["reference_sha256"] = "0" * 64
        self.write_base()
        with self.assertRaisesRegex(ValueError, "hash mismatch"):
            self.expand()
        self.base_row.update(reference_path="../master.png", reference_sha256=EXPAND.digest(self.master))
        self.write_base()
        with self.assertRaisesRegex(ValueError, "reference escapes"):
            self.expand()

    def test_input_mutation_is_detected_even_after_copy_failure(self):
        original_copy = EXPAND.shutil.copyfile

        def mutate(source, destination):
            if Path(source).resolve() == self.master.resolve():
                self.master.write_bytes(b"changed")
                raise OSError("simulated copy failure")
            return original_copy(source, destination)

        with mock.patch.object(EXPAND.shutil, "copyfile", side_effect=mutate):
            with self.assertRaisesRegex(ValueError, "hash mismatch.*after import"):
                self.expand()
        self.assertFalse((self.root / "output/manifest.json").exists())


if __name__ == "__main__":
    unittest.main()
