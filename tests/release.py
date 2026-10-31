"""Release eligibility and metadata regression checks; no GitHub writes."""
import importlib.util
from pathlib import Path
import tempfile
import sys
sys.dont_write_bytecode = True
import unittest

spec = importlib.util.spec_from_file_location('release', Path(__file__).resolve().parents[1] / '.github/scripts/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class ReleaseTests(unittest.TestCase):
    def pr(self, **changes):
        return dict(merged=True, base={'ref': 'main'}, labels=[{'name': 'release'}], merge_commit_sha='a' * 40, **changes)

    def test_merged_release_pr_uses_exact_commit(self):
        self.assertEqual('a' * 40, release.eligible_pr(self.pr()))

    def test_reject_closed_unmerged(self):
        pr = self.pr(); pr['merged'] = False
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_reject_ordinary_pr(self):
        pr = self.pr(); pr['labels'] = []
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_reject_other_base(self):
        pr = self.pr(); pr['base']['ref'] = 'other'
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def test_reject_unsafe_commit(self):
        pr = self.pr(); pr['merge_commit_sha'] = '$(echo injected)'
        with self.assertRaises(ValueError): release.eligible_pr(pr)

    def fixture(self, root, version='1.0.5', constant='1.0.5'):
        (root / 'wpbono-rsvp-reminders.php').write_text(f" * Version: {version}\ndefine('WPBONO_RSVP_REMINDERS_VERSION', '{constant}');\n")
        (root / 'release-notes').mkdir()
        (root / 'release-notes' / (version + '.md')).write_text('## New Features\n- None.\n## Improvements\n## Bug Fixes\n')

    def test_valid_metadata(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            self.assertEqual('1.0.5', release.metadata(root))

    def test_reject_version_mismatch(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root, constant='1.0.4')
            with self.assertRaises(ValueError): release.metadata(root)

    def test_reject_non_semver(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root, '01.0.5', '01.0.5')
            with self.assertRaises(ValueError): release.metadata(root)

    def test_require_notes(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            (root / 'release-notes/1.0.5.md').unlink()
            with self.assertRaises(FileNotFoundError): release.metadata(root)

    def test_require_note_sections(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); self.fixture(root)
            (root / 'release-notes/1.0.5.md').write_text('Incomplete')
            with self.assertRaises(ValueError): release.metadata(root)


if __name__ == '__main__': unittest.main()
