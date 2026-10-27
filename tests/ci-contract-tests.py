#!/usr/bin/env python3
"""Repository metadata and CI control-plane regression checks (stdlib only)."""

import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("ci_plan", ROOT / "bin/ci-plan.py")
ci_plan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci_plan)


class CIContracts(unittest.TestCase):
    def setUp(self):
        self.readme = (ROOT / "readme.txt").read_text()

    def test_support_matrix_and_intensity(self):
        # A controlled fixture ensures boundary selection does not drift silently.
        fixture = "Requires at least: 6.4\nTested up to: 7.0\nRequires PHP: 7.4\n"
        final = ci_plan.plan("pull_request", "false", fixture)
        self.assertEqual(final["full"], "true")
        self.assertEqual(json.loads(final["matrix"])["include"], [
            {"wordpress": "6.4", "php": "7.4"},
            {"wordpress": "6.4", "php": "8.2"},
            {"wordpress": "7.0", "php": "7.4"},
            {"wordpress": "7.0", "php": "8.4"},
        ])
        draft = ci_plan.plan("pull_request", "true", fixture)
        self.assertEqual(draft["full"], "false")
        self.assertEqual(json.loads(draft["matrix"])["include"], [{"wordpress": "7.0", "php": "8.4"}])
        for event in ("push", "workflow_dispatch"):
            self.assertEqual(ci_plan.plan(event, "true", fixture), final)

    def test_invalid_metadata_fails(self):
        for invalid in ("", self.readme.replace("Requires PHP: 7.4", "Requires PHP: 8.0"),
                        self.readme.replace("Tested up to:", "Missing:")):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                ci_plan.plan("pull_request", "false", invalid)

    def test_metadata_consistency(self):
        plugin = (ROOT / "yoohw-media-optimizer.php").read_text()
        def value(text, key):
            match = re.search(r"^\s*(?:\*\s*)?" + re.escape(key) + r":\s*(\S+)", text, re.M)
            self.assertIsNotNone(match, key)
            return match.group(1)
        self.assertEqual(value(plugin, "Version"), value(self.readme, "Stable tag"))
        for key in ("Requires at least", "Requires PHP"):
            self.assertEqual(value(plugin, key), value(self.readme, key))
        version = re.search(r"define\( 'YOOHW_MEDIA_OPTIMIZER_VERSION', '([^']+)' \)", plugin).group(1)
        self.assertEqual(version, value(plugin, "Version"))
        self.assertEqual((ROOT / "README.md").read_text(), self.readme)
        ci_plan.plan("workflow_dispatch", "false", self.readme)

    def test_development_files_are_excluded(self):
        excludes = set((ROOT / ".distignore").read_text().splitlines())
        for path in ("/.git", "/.github", "/AGENTS.md", "/bin", "/tests"):
            self.assertIn(path, excludes)

    def test_native_profile_routing(self):
        workflow = (ROOT / ".github/workflows/regression.yml").read_text()
        self.assertIn("extensions: mysqli, gd, exif, imagick", workflow)
        self.assertRegex(workflow, r"if: \$\{\{ inputs.php == '8\.4' \}\}\n        run: \|\n          sudo apt-get update\n          sudo apt-get install --no-install-recommends -y webp libavif-bin libjpeg-turbo-progs")
        self.assertIn("YOOHW_MO_REQUIRE_NATIVE_QUALITY: ${{ inputs.php == '8.4' && '1' || '0' }}", workflow)
        self.assertNotIn("continue-on-error", workflow)
        canary = (ROOT / ".github/workflows/compatibility-canary.yml").read_text()
        self.assertIn("uses: ./.github/workflows/regression.yml", canary)
        self.assertIn("wordpress: nightly\n      php: '8.4'", canary)
        for path in (".github/workflows/regression.yml", "bin/run-wp-regression.sh", "bin/setup-ci-imagick.sh"):
            self.assertIn("      - " + path, canary)

    def test_pinned_imagick_environment(self):
        workflow = (ROOT / ".github/workflows/regression.yml").read_text()
        self.assertLess(workflow.index("php-version:"), workflow.index("run: bash bin/setup-ci-imagick.sh"))
        self.assertLess(workflow.index("run: bash bin/setup-ci-imagick.sh"), workflow.index("bash bin/run-wp-regression.sh"))
        script = (ROOT / "bin/setup-ci-imagick.sh").read_text()
        for pin in ("6.9.13-59", "3.8.1", "25f456444a7b8fa6622a87a901791acc8617f91ef7fb815fa383732e8a271366",
                    "3a3587c0a524c17d0dad9673a160b90cd776e836838474e173b549ed864352ee"):
            self.assertIn(pin, script)
        self.assertEqual(script.count("sha256sum --check --strict"), 2)
        self.assertLess(script.rindex("sha256sum --check --strict"), script.index("7z x"))
        self.assertIn('--with-php-config="$(command -v php-config)"', script)
        self.assertIn('--prefix="$prefix"', script)
        self.assertIn('$(php-config --extension-dir)/imagick.so', script)
        self.assertIn('export LD_LIBRARY_PATH=', script)
        self.assertIn('export MAGICK_CODER_MODULE_PATH=', script)
        self.assertNotIn("continue-on-error", workflow)
        # The CI-only script must refuse local execution before installing packages/files.
        env = dict(os.environ)
        env.pop("GITHUB_ACTIONS", None)
        refused = subprocess.run(["bash", str(ROOT / "bin/setup-ci-imagick.sh")], env=env, capture_output=True, text=True)
        self.assertNotEqual(refused.returncode, 0)
        self.assertIn("GitHub Linux runner required", refused.stderr)

    def test_required_capabilities_fail_closed(self):
        runner = (ROOT / "bin/run-wp-regression.sh").read_text()
        self.assertIn('export YOOHW_MO_REQUIRE_NATIVE_QUALITY="${YOOHW_MO_REQUIRE_NATIVE_QUALITY:-0}"', runner)
        # Bound assertions to the actual pre-provisioning PHP guard, not docs/log text.
        preflight = runner.split("php <<'PHP'\n", 1)[1].split("\nPHP\n", 1)[0]
        self.assertIn("array( 'mysqli', 'gd', 'exif' )", preflight)
        self.assertIn("array( 'imagepng', 'imagecreatetruecolor', 'exif_read_data' )", preflight)
        native = preflight.split("if ( '1' === getenv( 'YOOHW_MO_REQUIRE_NATIVE_QUALITY' ) ) {", 1)[1]
        self.assertIn("array( 'JPEG', 'PNG', 'WEBP', 'AVIF' )", native)
        for condition in ("! extension_loaded( $extension )", "! function_exists( $function )",
                          "! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' )",
                          "! Imagick::queryFormats( $format )"):
            self.assertRegex(preflight, r"if \( " + re.escape(condition) + r" \) \{\s*fwrite\([^;]+;\s*exit\( 1 \);")
        self.assertLess(runner.index("\nPHP\n"), runner.index("wp core download"))

    def test_required_encoder_preflight(self):
        runner = (ROOT / "bin/run-wp-regression.sh").read_text()
        guard = runner.split("\nPHP\n", 1)[1].split("\nwp core download", 1)[0]
        # Execute the real shell guard with isolated executable fixtures: no PHP/WP/site writes.
        def run(required, missing="", failed="", old_avif=False, spaced=False):
            with tempfile.TemporaryDirectory() as directory:
                for encoder in ("cwebp", "avifenc", "jpegtran"):
                    if encoder == missing:
                        continue
                    executable = Path(directory) / encoder
                    help_text = "old interface" if old_avif else ("-q, --qcolor Q" if spaced else "-q,--qcolor Q")
                    executable.write_text("#!/bin/sh\n" + ("exit 1\n" if encoder == failed else
                                          "if [ \"$1\" = --help ]; then printf '%s\\n' '" + help_text + "'; else printf 'test encoder 1.0\\n'; fi\n"))
                    executable.chmod(0o755)
                return subprocess.run(["/bin/bash", "-euo", "pipefail", "-c", guard],
                                      env=dict(os.environ, PATH=directory, YOOHW_MO_REQUIRE_NATIVE_QUALITY=required),
                                      capture_output=True, text=True)
        self.assertEqual(run("1").returncode, 0)
        self.assertEqual(run("1", spaced=True).returncode, 0)
        for encoder in ("cwebp", "avifenc", "jpegtran"):
            with self.subTest(missing=encoder):
                result = run("1", missing=encoder)
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("Missing required encoder: " + encoder, result.stderr)
            with self.subTest(failed=encoder):
                self.assertNotEqual(run("1", failed=encoder).returncode, 0)
        self.assertNotEqual(run("1", old_avif=True).returncode, 0)
        self.assertEqual(run("0", missing="cwebp").returncode, 0)

    def test_gate_rejects_incomplete_results(self):
        env = dict(os.environ, FULL="true", PLAN="success", CONTRACTS="success", QUALITY="success",
                   JAVASCRIPT="success", REGRESSION="success", PLUGIN_CHECK="success")
        def gate(values):
            return subprocess.run(["bash", str(ROOT / "bin/validate-ci-gate.sh")], env=values,
                                  capture_output=True, text=True).returncode
        self.assertEqual(gate(env), 0)
        for job in ("PLAN", "CONTRACTS", "QUALITY", "JAVASCRIPT", "REGRESSION", "PLUGIN_CHECK"):
            for result in ("failure", "cancelled", "skipped", ""):
                with self.subTest(job=job, result=result):
                    self.assertNotEqual(gate(dict(env, **{job: result})), 0)
        self.assertEqual(gate(dict(env, FULL="false", PLUGIN_CHECK="skipped")), 0)
        self.assertNotEqual(gate(dict(env, FULL="", PLUGIN_CHECK="success")), 0)
        self.assertNotEqual(gate(dict(env, FULL="false", REGRESSION="skipped", PLUGIN_CHECK="skipped")), 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
