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
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['probe_filters'][$name][$priority][] = $callback; }
function apply_filters($name, $value, ...$args) {
    $priorities = $GLOBALS['probe_filters'][$name] ?? array();
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) { $value = $callback($value, ...$args); }
    }
    return $value;
}
function add_action(...$args) {}
function get_option($name, $default = false) {
    return apply_filters('pre_option_' . $name, $default);
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

    def stub_imagick_editor(self):
        """No encoder: expose setOption/save calls through fake WordPress editor classes."""
        includes = self.wordpress / "wp-includes"
        includes.mkdir()
        (includes / "class-wp-image-editor.php").write_text("<?php class WP_Image_Editor {}")
        (includes / "class-wp-image-editor-imagick.php").write_text("""<?php
class ProbeImage {
    public $options = array();
    function setOption($key, $value) { $this->options[$key] = $value; }
}
class WP_Image_Editor_Imagick extends WP_Image_Editor {
    protected $image;
    function __construct() { $this->image = new ProbeImage(); }
    function save($destination = null, $mime = null) {
        return array('destination' => $destination, 'mime' => $mime, 'options' => $this->image->options);
    }
}
""")

    def test_custom_avif_controls_require_imagick_before_bootstrap(self):
        for engine in (None, "native", "gd"):
            for control in ("--avif-chroma=420", "--avif-chroma=444", "--avif-speed=6"):
                arguments = (["--engine=" + engine] if engine else []) + [control]
                with self.subTest(arguments=arguments):
                    self.assert_prebootstrap_failure("Test-only AVIF controls require --engine=imagick", *arguments)

    def test_custom_avif_adapter_instruments_only_avif_and_calls_parent_save(self):
        self.stub_runtime()
        self.stub_imagick_editor()
        # A wrapper inspects the loaded test subclass after the probe has completed.
        # Its input stays invalid, so no real image encoding or site bootstrap occurs.
        wrapper = self.directory / "instrument.php"
        wrapper.write_text("""<?php
array_shift($argv);
$probe = array_shift($argv);
array_unshift($argv, $probe);
$argc = count($argv);
require $probe;
$webp = (new YoOhw_Benchmark_Imagick())->save('webp-output', 'image/webp');
$avif = (new YoOhw_Benchmark_Imagick())->save('avif-output', 'image/avif');
echo json_encode(array('webp' => $webp, 'avif' => $avif,
    'applied' => YoOhw_Benchmark_Imagick::$applied_options,
    'editors' => apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick')),
    'parent' => get_parent_class('YoOhw_Benchmark_Imagick'),
    'prepare_owner' => (new ReflectionMethod('YoOhw_Benchmark_Imagick', 'prepare_color'))->getDeclaringClass()->getName()));
""")
        for chroma, speed in (("420", "default"), ("444", "6"), ("default", "6")):
            with self.subTest(chroma=chroma, speed=speed):
                output = self.directory / ("output-" + chroma + "-" + speed)
                result = subprocess.run([PHP, str(wrapper), str(PROBE), str(self.wordpress), str(self.manifest),
                                         str(output), "--engine=imagick", "--codec=avif",
                                         "--avif-chroma=" + chroma, "--avif-speed=" + speed],
                                        text=True, capture_output=True, timeout=10)
                self.assertEqual(result.returncode, 0, result.stderr)
                calls = json.loads(result.stdout.splitlines()[-1])
                expected = {}
                if chroma != "default":
                    expected["heic:chroma"] = chroma
                if speed != "default":
                    expected["heic:speed"] = speed
                self.assertEqual(calls["webp"], {"destination": "webp-output", "mime": "image/webp", "options": []})
                self.assertEqual(calls["avif"], {"destination": "avif-output", "mime": "image/avif", "options": expected})
                self.assertEqual(calls["applied"], expected)
                self.assertEqual(calls["editors"], ["YoOhw_Benchmark_Imagick"])
                self.assertEqual(calls["parent"], "YoOhw_Media_Optimizer_Imagick")
                self.assertEqual(calls["prepare_owner"], "YoOhw_Media_Optimizer_Imagick")
                report = json.loads((output / "plugin-baseline.json").read_text())
                self.assertEqual(report["codec_request"], "avif")
                self.assertEqual(report["test_instrumentation"], {"avif_chroma": chroma, "avif_speed": speed})
                self.assertEqual([row["codec"] for row in report["images"]], ["avif"])
                row = report["images"][0]
                self.assertEqual(row["test_instrumentation"], {"avif_chroma": chroma, "avif_speed": speed, "applied_options": []})
                self.assertIn("yoohw_mo_color_unsupported", row["error"])
                self.assertIn(str((self.wordpress / "wp-includes/class-wp-image-editor-imagick.php").resolve()), report["loaded_file_sha256"])

    def test_explicit_default_controls_do_not_load_test_editor(self):
        self.stub_runtime()
        # No fake editor files exist: loading the test adapter would fail.
        for engine in ("native", "imagick", "gd"):
            with self.subTest(engine=engine):
                output = self.directory / ("default-" + engine)
                result = self.run_probe("--engine=" + engine, "--avif-chroma=default", "--avif-speed=default",
                                        "--codec=avif", output=output)
                self.assertEqual(result.returncode, 0, result.stderr)
                report = json.loads((output / "plugin-baseline.json").read_text())
                self.assertEqual(report["test_instrumentation"], {"avif_chroma": "default", "avif_speed": "default"})
                self.assertEqual([row["codec"] for row in report["images"]], ["avif"])
                self.assertFalse(any("class-wp-image-editor" in path for path in report["loaded_file_sha256"]))

    def test_invalid_options_fail_before_bootstrap(self):
        for arguments in (("--engine=unknown",), ("--webp-quality=0",), ("--avif-quality=101",),
                          ("--webp-quality=82.5",), ("--png-webp-mode=auto",),
                          ("--avif-alpha-mode=auto",), ("--engine=gd", "--engine=gd"),
                          ("--avif-chroma=422",), ("--avif-speed=5",), ("--avif-speed=06",),
                          ("--codec=webp",), ("--avif-chroma=420", "--avif-chroma=444"),
                          ("--avif-speed=6", "--avif-speed=6"), ("--codec=both", "--codec=avif"),
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
        self.assertEqual(report["codec_request"], "both")
        self.assertEqual(report["test_instrumentation"], {"avif_chroma": "default", "avif_speed": "default"})
        self.assertEqual([row["codec"] for row in report["images"]], ["webp", "avif"])
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
