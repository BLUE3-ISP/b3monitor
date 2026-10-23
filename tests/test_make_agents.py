"""Exercise Makefile failure handling without starting the central or lab."""

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
GO = r'''#!/usr/bin/env python3
import os
from pathlib import Path
import sys

root = Path(os.environ["B3MON_TEST_ROOT"])
command = sys.argv[1]
if command == "list":
    counter = root / "list-count"
    count = int(counter.read_text()) + 1 if counter.exists() else 1
    counter.write_text(str(count))
    if os.environ.get("B3MON_TEST_MODE") == "failed" or count > int(os.environ.get("B3MON_TEST_FAIL_AFTER", "100")):
        print("synthetic go list failure", file=sys.stderr)
        sys.exit(7)
    if os.environ.get("B3MON_TEST_MODE") != "empty":
        print("synthetic.example/probe")
else:
    with (root / "calls").open("a") as log:
        log.write(command + ":" + Path.cwd().name + "\n")
    if command == "test" and os.environ.get("B3MON_TEST_FAIL_TEST") == "1":
        sys.exit(1)
'''


class MakeAgentsTest(unittest.TestCase):
    def run_make(self, target="test-agents", mode="normal", fail_after=100, fail_test=False):
        with tempfile.TemporaryDirectory(prefix="b3mon-make-test-") as directory:
            root = Path(directory)
            shutil.copy2(ROOT / "Makefile", root / "Makefile")
            for name in ("agents/probe", "lab/target", "central/vendor/bin", "bin"):
                (root / name).mkdir(parents=True, exist_ok=True)
            (root / "agents/probe/go.mod").write_text("module synthetic.example/probe\n")
            scripts = {
                "bin/go": GO,
                "bin/composer": '#!/bin/sh\nprintf "composer\\n" >> "$B3MON_TEST_ROOT/calls"\n',
                "central/vendor/bin/pest": "#!/bin/sh\nexit 0\n",
                "lab/pg.sh": "#!/bin/sh\nexit 0\n",
            }
            for name, text in scripts.items():
                path = root / name
                path.write_text(text)
                path.chmod(0o755)
            env = dict(
                os.environ,
                PATH=str(root / "bin") + os.pathsep + os.environ["PATH"],
                B3MON_TEST_ROOT=str(root),
                B3MON_TEST_MODE=mode,
                B3MON_TEST_FAIL_AFTER=str(fail_after),
                B3MON_TEST_FAIL_TEST="1" if fail_test else "0",
            )
            result = subprocess.run(
                ["make", "--no-print-directory", target], cwd=root, env=env,
                capture_output=True, text=True, timeout=10,
            )
            calls = (root / "calls").read_text().splitlines() if (root / "calls").exists() else []
            return result, calls

    def test_failed_discovery_fails_the_suite_with_its_diagnostic(self):
        result, calls = self.run_make(mode="failed")
        self.assertNotEqual(0, result.returncode)
        self.assertIn("synthetic go list failure", result.stderr)
        self.assertEqual([], calls)

    def test_successful_empty_module_keeps_the_explicit_skip(self):
        result, calls = self.run_make(mode="empty")
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("PULADO, NÃO APROVADO", result.stderr)
        self.assertEqual(["test:target"], calls)

    def test_populated_module_and_lab_target_are_tested(self):
        result, calls = self.run_make()
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(["test:probe", "test:target"], calls)

    def test_go_test_failure_stops_before_the_lab_target(self):
        result, calls = self.run_make(fail_test=True)
        self.assertNotEqual(0, result.returncode)
        self.assertEqual(["test:probe"], calls)

    def test_verification_discovery_failure_stops_before_other_checks(self):
        result, calls = self.run_make(target="verify", fail_after=1)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("synthetic go list failure", result.stderr)
        self.assertEqual(["test:probe", "test:target"], calls)
        self.assertNotIn("NÃO VERIFICADO", result.stderr)


if __name__ == "__main__":
    unittest.main()
