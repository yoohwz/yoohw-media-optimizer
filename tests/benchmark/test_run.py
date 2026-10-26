#!/usr/bin/env python3
"""Focused offline harness tests; no WordPress install or network needed."""

import importlib.util
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest

from PIL import Image, ImageCms


SPEC = importlib.util.spec_from_file_location("benchmark_run", Path(__file__).with_name("run.py"))
RUN = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RUN)


class HarnessTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.directory = Path(self.temporary.name)
        self.source = self.directory / "source.png"
        Image.new("RGBA", (16, 16), (220, 70, 25, 128)).save(self.source)
        self.manifest = self.directory / "manifest.json"
        self.entry = {"id": "alpha", "path": "source.png", "group": "alpha", "provenance": "test generated", "color_policy": "assume_srgb", "sha256": RUN.digest(self.source)}
        self.write_manifest()

    def tearDown(self):
        self.temporary.cleanup()

    def write_manifest(self):
        self.manifest.write_text(json.dumps({"images": [self.entry]}))

    def executable(self, name, code):
        target = self.directory / name
        target.write_text("#!" + sys.executable + "\n" + code + "\n")
        target.chmod(0o700)
        return str(target)

    def test_manifest_validation_hash_traversal_and_duplicates(self):
        for data in ({}, {"images": []}, {"images": [self.entry, self.entry]}):
            self.manifest.write_text(json.dumps(data))
            with self.assertRaises(ValueError):
                RUN.read_manifest(self.manifest)
        self.entry["sha256"] = "0" * 64
        self.write_manifest()
        with self.assertRaisesRegex(ValueError, "hash mismatch"):
            RUN.read_manifest(self.manifest)
        self.entry["path"] = "../escape.png"
        self.write_manifest()
        with self.assertRaises((ValueError, FileNotFoundError)):
            RUN.read_manifest(self.manifest)

    def test_output_reuse_refused_before_encode(self):
        output = self.directory / "output"
        output.mkdir()
        sentinel = output / "sentinel"
        sentinel.write_text("preserve")
        with self.assertRaises(FileExistsError):
            RUN.benchmark(self.manifest, output, [], 5, {})
        self.assertEqual(sentinel.read_text(), "preserve")
        self.assertEqual(RUN.digest(self.source), self.entry["sha256"])

    def test_output_source_overlap_refused(self):
        with self.assertRaisesRegex(ValueError, "overlaps sources"):
            RUN.benchmark(self.manifest, self.directory, [], 5, {})

    def test_expected_failure_is_explicit_and_matching(self):
        self.entry["expected_failure"] = "invalid ICC"
        image = Image.new("RGB", (16, 16))
        image.save(self.source, icc_profile=b"broken ICC")
        self.entry["sha256"] = RUN.digest(self.source)
        self.write_manifest()
        tool = self.executable("version-only", "print('test tool')")
        output = self.directory / "expected-output"
        self.assertEqual(RUN.benchmark(self.manifest, output, [], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), tool)), 0)
        report = json.loads((output / "results.json").read_text())
        self.assertEqual(report["rows"][0]["status"], "expected_error")
        self.assertTrue(report["source_hashes_unchanged"])

    def test_input_is_immutable_and_encoding_failure_is_nonzero(self):
        failed = self.executable("failed", "import sys; sys.exit(7)")
        output = self.directory / "failed-output"
        self.assertEqual(RUN.benchmark(self.manifest, output, ["webp_baseline82"], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), failed)), 1)
        report = json.loads((output / "results.json").read_text())
        self.assertFalse(report["execution_ok"])
        self.assertEqual(report["rows"][0]["status"], "error")
        self.assertEqual(RUN.digest(self.source), self.entry["sha256"])

    def test_unprofiled_cmyk_and_invalid_icc_never_relabelled(self):
        with self.assertRaisesRegex(ValueError, "unprofiled CMYK"):
            RUN.color_managed(Image.new("CMYK", (16, 16)), assume_srgb=True)
        image = Image.new("RGB", (16, 16))
        with self.assertRaisesRegex(ValueError, "requires explicit"):
            RUN.color_managed(image)
        image.info["icc_profile"] = b"invalid-profile"
        with self.assertRaisesRegex(ValueError, "invalid ICC"):
            RUN.color_managed(image, assume_srgb=True)

    def test_icc_transform_retains_alpha_and_orientation(self):
        image = Image.new("RGBA", (16, 12), (100, 50, 20, 64))
        image.info["icc_profile"] = ImageCms.ImageCmsProfile(ImageCms.createProfile("sRGB")).tobytes()
        image.getexif()[274] = 6
        transformed = RUN.color_managed(image)
        self.assertEqual(transformed.size, (12, 16))
        self.assertEqual(transformed.getpixel((0, 0)), (100, 50, 20, 64))
        self.assertEqual(RUN.alpha_error(transformed, transformed)["alpha_max_error"], 0)

    def test_alpha_composites_detect_alpha_loss_hidden_rgb_is_irrelevant(self):
        left = Image.new("RGBA", (16, 16), (255, 0, 0, 0))
        hidden = Image.new("RGBA", left.size, (0, 255, 255, 0))
        for background in (0, 255):
            self.assertEqual(RUN.composite(left, background).tobytes(), RUN.composite(hidden, background).tobytes())
        opaque = Image.new("RGBA", left.size, (255, 0, 0, 255))
        self.assertEqual(RUN.alpha_error(left, opaque)["alpha_max_error"], 255)
        self.assertNotEqual(RUN.composite(left, 255).tobytes(), RUN.composite(opaque, 255).tobytes())

    def test_metric_failure_nonfinite_and_dimensions_are_errors(self):
        image = Image.new("RGB", (16, 16))
        for code in ("import sys; sys.exit(4)", "print('nan')", "print('garbage')"):
            tool = self.executable("metric", code)
            with self.assertRaisesRegex(ValueError, "metric|nonfinite"):
                RUN.metric_score(tool, image, image, self.directory, 5)
        with self.assertRaisesRegex(ValueError, "dimension mismatch"):
            RUN.metric_score("unused", image, Image.new("RGB", (8, 8)), self.directory, 5)

    def test_decode_failure_is_nonzero_and_persisted(self):
        bad_encoder = self.executable("bad_encoder", "import pathlib, sys\nif '-o' in sys.argv: pathlib.Path(sys.argv[sys.argv.index('-o')+1]).write_bytes(b'invalid image')")
        output = self.directory / "decode-output"
        self.assertEqual(RUN.benchmark(self.manifest, output, ["webp_baseline82"], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), bad_encoder)), 1)
        row = json.loads((output / "results.json").read_text())["rows"][0]
        self.assertEqual(row["error"], "decode failed")
        self.assertNotEqual(row["decode_process"]["returncode"], 0)

    def test_decode_worker_refuses_existing_destination_and_source_alias(self):
        with self.assertRaises(FileExistsError):
            RUN.worker_decode(self.source, self.source)
        self.assertEqual(RUN.digest(self.source), self.entry["sha256"])

    def test_source_mutation_is_fatal_and_recorded(self):
        malicious = self.executable("mutating", "import pathlib, sys\nif '-o' in sys.argv: pathlib.Path(sys.argv[sys.argv.index('-o')-1]).write_bytes(b'mutated')")
        output = self.directory / "mutation-output"
        with self.assertRaisesRegex(RuntimeError, "SOURCE MUTATED"):
            RUN.benchmark(self.manifest, output, ["webp_baseline82"], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), malicious))
        report = json.loads((output / "results.json").read_text())
        self.assertFalse(report["source_hashes_unchanged"])
        self.assertFalse(report["execution_ok"])

    def test_actual_plugin_baseline_is_hash_checked_scored_and_rss_absent(self):
        good = self.directory / "actual.webp"
        Image.open(self.source).save(good, lossless=True)
        bridge = self.directory / "plugin-baseline.json"
        bridge.write_text(json.dumps({"source_hashes_unchanged": True, "php": "test", "images": [
            {"id": "alpha", "codec": "webp", "path": good.name, "seconds": .02, "sha256": RUN.digest(good), "engine": "test engine"}]}))
        tool = self.executable("fixed-score", "print('100')")
        output = self.directory / "actual-output"
        self.assertEqual(RUN.benchmark(self.manifest, output, ["webp_baseline82"], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), tool), bridge), 0)
        report = json.loads((output / "results.json").read_text())
        row = report["rows"][0]
        self.assertEqual(row["status"], "ok")
        self.assertEqual(row["engine"], "test engine")
        self.assertIsNone(row["encode_peak_rss_bytes"])
        self.assertEqual(row["alpha_max_error"], 0)
        self.assertEqual(report["summary"][0]["encode_peak_rss_bytes"], None)
        data = json.loads(bridge.read_text())
        data["images"][0]["sha256"] = "0" * 64
        bridge.write_text(json.dumps(data))
        self.assertEqual(RUN.benchmark(self.manifest, self.directory / "bad-actual-output", ["webp_baseline82"], 5, dict.fromkeys(("cwebp", "avifenc", "ssimulacra2"), tool), bridge), 1)

    def test_child_peak_rss_units_and_timeout(self):
        process = RUN.run_process([sys.executable, "-c", "data=bytearray(32*1024*1024)"], 5)
        self.assertGreater(process["peak_rss_bytes"], 32 * 1024 * 1024)
        self.assertLess(process["peak_rss_bytes"], 512 * 1024 * 1024)
        process = RUN.run_process([sys.executable, "-c", "import time; time.sleep(2)"], .05)
        self.assertTrue(process["timed_out"])
        self.assertNotEqual(process["returncode"], 0)

    @unittest.skipUnless(shutil.which("ssimulacra2"), "real SSIMULACRA2 unavailable")
    def test_real_metric_identity_and_visible_degradation(self):
        image = Image.new("RGB", (64, 64), (30, 160, 220))
        for x in range(64):
            for y in range(64):
                image.putpixel((x, y), ((x * 13 + y * 5) % 256, (x * 3) % 256, (y * 7) % 256))
        identity = RUN.metric_score(shutil.which("ssimulacra2"), image, image, self.directory, 10)
        degraded = RUN.metric_score(shutil.which("ssimulacra2"), image, Image.new("RGB", image.size), self.directory, 10)
        self.assertGreater(identity["ssimulacra2_worst"], 99)
        self.assertGreater(identity["ssimulacra2_worst"], degraded["ssimulacra2_worst"])

    @unittest.skipUnless(shutil.which("cwebp") and shutil.which("ssimulacra2"), "real WebP/metric unavailable")
    def test_square_oriented_raw_baseline_is_not_plugin_equivalent(self):
        image = Image.new("RGB", (16, 16), (30, 60, 90))
        image.getexif()[274] = 6
        image.save(self.source, exif=image.getexif())
        self.entry["sha256"] = RUN.digest(self.source)
        self.write_manifest()
        output = self.directory / "orientation-output"
        tools = {"cwebp": shutil.which("cwebp"), "avifenc": shutil.which("cwebp"), "ssimulacra2": shutil.which("ssimulacra2")}
        self.assertEqual(RUN.benchmark(self.manifest, output, ["webp_baseline82"], 5, tools), 0)
        report = json.loads((output / "results.json").read_text())
        self.assertEqual(report["rows"][0]["status"], "observed_orientation_limitation")
        self.assertEqual(report["summary"], [])

    def test_cli_rejects_bad_timeout_and_profile(self):
        for options in (("--timeout", "0"), ("--profiles", "no-such-profile")):
            result = RUN.run_process([sys.executable, str(Path(RUN.__file__)), str(self.manifest), str(self.directory / "cli"), *options], 5)
            self.assertNotEqual(result["returncode"], 0)
            self.assertFalse((self.directory / "cli").exists())

    def test_optional_icc_control_changes_only_metadata_flag(self):
        baseline = RUN.encode_args("webp_baseline82", {"cwebp": "cwebp"}, "source", "normalized", "preserved", "target")
        control = RUN.encode_args("webp_icc82", {"cwebp": "cwebp"}, "source", "normalized", "preserved", "target")
        self.assertEqual([arg for arg in control if arg not in ("-metadata", "icc")], baseline)
        self.assertNotIn("webp_icc82", RUN.PROFILES)


if __name__ == "__main__":
    unittest.main()
