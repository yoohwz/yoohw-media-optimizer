#!/usr/bin/env python3
"""Probe boundary tests with a temporary PHP bootstrap; never load a real site."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]
PROBE = ROOT / "tests/benchmark/plugin-baseline.php"
PHP = shutil.which("php")


@unittest.skipUnless(PHP, "PHP CLI unavailable")
class PluginProbeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="yoohw-probe-test-")
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.wordpress = self.directory / "wordpress"
        self.wordpress.mkdir()
        self.marker = self.wordpress / "booted"
        self.bootstrap = self.wordpress / "wp-load.php"
        self.bootstrap.write_text("<?php file_put_contents(__DIR__ . '/booted', 'yes'); throw new RuntimeException('test bootstrap reached');")
        self.corpus = self.directory / "corpus"
        self.corpus.mkdir()
        self.source = self.corpus / "invalid.png"
        self.source.write_bytes(b"invalid-image-policy-probe")
        self.entry = {"id": "policy", "path": self.source.name,
                      "sha256": hashlib.sha256(self.source.read_bytes()).hexdigest()}
        self.manifest = self.corpus / "manifest.json"
        self.write_manifest([self.entry])
        self.output = self.directory / "output"

    def write_manifest(self, images):
        self.manifest.write_text(json.dumps({"images": images}))

    def run_probe(self, *arguments, output=None):
        return subprocess.run([PHP, str(PROBE), str(self.wordpress), str(self.manifest),
                               str(output or self.output), *arguments], text=True, capture_output=True, timeout=10)

    def assert_prebootstrap_failure(self, expected, *arguments, output=None):
        result = self.run_probe(*arguments, output=output)
        self.assertNotEqual(result.returncode, 0, result.stdout)
        self.assertIn(expected, result.stderr)
        self.assertFalse(self.marker.exists(), result.stderr)
        self.assertFalse(self.output.exists())

    def stub_runtime(self):
        """Load real class definitions, with only the WordPress APIs needed for rejection rows."""
        plugin = self.wordpress / "plugin"
        for source in ROOT.rglob("*.php"):
            relative = source.relative_to(ROOT)
            if relative.parts[0] in ("tests", ".git"):
                continue
            target = plugin / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(source, target)
        self.bootstrap.write_text("""<?php
file_put_contents(__DIR__ . '/booted', 'yes');
define('ABSPATH', __DIR__ . '/');
$GLOBALS['wp_version'] = 'stub-not-real-wordpress';
$GLOBALS['probe_filters'] = array();
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['probe_filters'][$name][] = $callback; }
function add_action(...$args) {}
function get_option($name, $default = false) {
    foreach ($GLOBALS['probe_filters']['pre_option_' . $name] ?? array() as $callback) { return $callback(); }
    return $default;
}
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function absint($value) { return abs((int) $value); }
function __($value, $domain = '') { return $value; }
class WP_Error {
    public $code; public $message;
    function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    function get_error_code() { return $this->code; }
    function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
require __DIR__ . '/plugin/includes/class-yoohw-media-optimizer.php';
""")
        return plugin

    def test_invalid_options_fail_before_bootstrap(self):
        for arguments in (("--engine=unknown",), ("--webp-quality=0",), ("--avif-quality=101",),
                          ("--webp-quality=82.5",), ("--png-webp-mode=auto",),
                          ("--avif-alpha-mode=auto",), ("--engine=gd", "--engine=gd"),
                          ("--bogus=yes",), ("--engine", "gd")):
            with self.subTest(arguments=arguments):
                self.assert_prebootstrap_failure("", *arguments)

    def test_advanced_modes_refuse_editor_preferences_before_bootstrap(self):
        for engine in ("imagick", "gd"):
            for mode in ("--png-webp-mode=lossless", "--avif-alpha-mode=lossless"):
                self.assert_prebootstrap_failure("Advanced PNG modes require --engine=native", "--engine=" + engine, mode)

    def test_sources_must_be_contained_unique_and_unchanged(self):
        outside = self.directory / "outside.png"
        outside.write_bytes(self.source.read_bytes())
        alias = self.corpus / "escaped.png"
        alias.symlink_to(outside)
        cases = [[dict(self.entry, path="../outside.png")], [dict(self.entry, path=alias.name)],
                 [dict(self.entry, sha256="0" * 64)], [self.entry, self.entry],
                 [dict(self.entry, id="../escape")], [dict(self.entry, path=[])]]
        for images in cases:
            with self.subTest(images=images):
                self.write_manifest(images)
                self.assert_prebootstrap_failure("Invalid, duplicate, escaped, or changed manifest input")

    def test_outputs_cannot_be_inside_protected_trees_or_existing(self):
        for output in (self.corpus / "output", self.wordpress / "output", self.source):
            self.assert_prebootstrap_failure("Output must be outside", output=output)
        self.output.mkdir()
        result = self.run_probe()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Output must not already exist", result.stderr)
        self.assertFalse(self.marker.exists())

    def test_lazy_runtime_adapter_mismatch_is_rejected(self):
        plugin = self.stub_runtime()
        adapter = plugin / "includes/class-yoohw-media-optimizer-imagick.php"
        adapter.write_bytes(adapter.read_bytes() + b"\n// test mismatch\n")
        result = self.run_probe()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Loaded plugin differs", result.stderr)
        self.assertTrue(self.marker.exists())
        self.assertFalse(self.output.exists())

    def test_missing_and_extra_runtime_php_are_rejected(self):
        plugin = self.stub_runtime()
        adapter = plugin / "includes/class-yoohw-media-optimizer-thumbnail-imagick.php"
        original = adapter.read_bytes()
        adapter.unlink()
        for extra in (False, True):
            if extra:
                adapter.write_bytes(original)
                (plugin / "extra.php").write_text("<?php // unexpected runtime file\n")
            result = self.run_probe()
            self.assertNotEqual(result.returncode, 0)
            self.assertIn("Loaded plugin differs", result.stderr)
            self.assertFalse(self.output.exists())

    def test_options_receipts_and_rejection_rows_without_real_site(self):
        self.stub_runtime()
        result = self.run_probe("--engine=imagick", "--webp-quality=71", "--avif-quality=63")
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads((self.output / "plugin-baseline.json").read_text())
        self.assertEqual(report["engine_request"], "imagick")
        self.assertEqual(report["options"]["use_external_binaries"], 0)
        self.assertEqual(report["manifest_sha256"], hashlib.sha256(self.manifest.read_bytes()).hexdigest())
        expected_runtime = {str(path.relative_to(ROOT)) for path in ROOT.rglob("*.php")
                            if path.relative_to(ROOT).parts[0] not in ("tests", ".git")}
        self.assertEqual(set(report["runtime_php_sha256"]), expected_runtime)
        self.assertIn("includes/class-yoohw-media-optimizer-imagick.php", report["runtime_php_sha256"])
        self.assertTrue(report["loaded_file_sha256"])
        self.assertGreater(report["seconds"], 0)
        self.assertEqual(report["wordpress"], "stub-not-real-wordpress")
        for row, quality in zip(report["images"], (71, 63)):
            self.assertEqual(row["quality"], quality)
            self.assertEqual(row["engine_request"], "imagick")
            self.assertIsNone(row["engine"])
            self.assertIn("yoohw_mo_color_unsupported", row["error"])
            self.assertEqual(row["source_sha256"], self.entry["sha256"])
        self.assertEqual(hashlib.sha256(self.source.read_bytes()).hexdigest(), self.entry["sha256"])

    def test_default_and_native_advanced_option_names_are_preserved(self):
        self.stub_runtime()
        result = self.run_probe()
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads((self.output / "plugin-baseline.json").read_text())
        self.assertEqual(report["engine_request"], "native")
        self.assertEqual(report["options"]["webp_quality"], 82)
        self.assertEqual(report["options"]["avif_quality"], 82)
        self.assertEqual(report["options"]["webp_png_mode"], "lossy")
        self.assertEqual(report["options"]["avif_alpha_mode"], "default")
        self.assertEqual(report["options"]["use_external_binaries"], 1)
        output = self.directory / "advanced"
        result = self.run_probe("--engine=native", "--png-webp-mode=lossless", "--avif-alpha-mode=lossless",
                                "--webp-quality=1", "--avif-quality=100", output=output)
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads((output / "plugin-baseline.json").read_text())
        self.assertEqual(report["options"]["webp_png_mode"], "lossless")
        self.assertEqual(report["options"]["avif_alpha_mode"], "lossless")
        self.assertEqual([row["quality"] for row in report["images"]], [1, 100])


if __name__ == "__main__":
    unittest.main()
