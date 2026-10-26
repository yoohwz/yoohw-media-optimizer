#!/usr/bin/env python3
"""Repository metadata and CI control-plane regression checks (stdlib only)."""

import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
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
