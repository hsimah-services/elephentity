import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("catalog", ROOT / "tools/catalog.py")
catalog = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(catalog)


class CatalogTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / "catalog"
        self.app = Path(self.temporary.name) / "app"
        self.root.mkdir()
        shutil.copytree(ROOT / "patterns", self.root / "patterns")
        shutil.copy(ROOT / "catalog.json", self.root)
        shutil.copy(ROOT / "LICENSE", self.root)

    def change(self, callback):
        path = self.root / "catalog.json"
        data = json.loads(path.read_text())
        callback(data["patterns"])
        path.write_text(json.dumps(data))

    def test_only_selected_source_is_copied_and_hashed(self):
        self.assertEqual(["HasTimestamps"], catalog.copy_pattern(self.root, self.app, "HasTimestamps"))
        lock = json.loads((self.app / catalog.LOCK).read_text())
        self.assertEqual(["HasTimestamps"], list(lock["patterns"]))
        target = "spec/patterns/HasTimestamps.yml"
        self.assertEqual(catalog.digest((self.app / target).read_bytes()), lock["patterns"]["HasTimestamps"]["files"][target]["sha256"])
        self.assertFalse((self.app / "spec/patterns/HasSlug.yml").exists())
        self.assertTrue((self.app / "docs/patterns/LICENSE").is_file())
        trigger = "src/Patterns/HasTimestamps/HasTimestampsTrigger.php"
        source = ROOT / "patterns/HasTimestamps/HasTimestampsTrigger.php"
        self.assertEqual(source.read_bytes(), (self.app / trigger).read_bytes())
        self.assertEqual(catalog.digest(source.read_bytes()), lock["patterns"]["HasTimestamps"]["files"][trigger]["sha256"])

    def test_custom_code_collision_does_not_copy_spec(self):
        target = self.app / "src/Patterns/HasTimestamps/HasTimestampsTrigger.php"
        target.parent.mkdir(parents=True)
        target.write_text("application-owned trigger")
        with self.assertRaisesRegex(ValueError, "already exists"):
            catalog.copy_pattern(self.root, self.app, "HasTimestamps")
        self.assertEqual("application-owned trigger", target.read_text())
        self.assertFalse((self.app / "spec").exists())
        self.assertFalse((self.app / catalog.LOCK).exists())

    def test_collision_preserves_local_code_without_partial_copy(self):
        target = self.app / "spec/patterns/HasTimestamps.yml"
        target.parent.mkdir(parents=True)
        target.write_text("my changes")
        with self.assertRaisesRegex(ValueError, "already exists"):
            catalog.copy_pattern(self.root, self.app, "HasTimestamps")
        self.assertEqual("my changes", target.read_text())
        self.assertFalse((self.app / catalog.LOCK).exists())
        self.assertFalse((self.app / "docs").exists())

    def test_planned_pattern_cannot_be_installed(self):
        with self.assertRaisesRegex(ValueError, "not yet installable"):
            catalog.copy_pattern(self.root, self.app, "HasCreator")
        self.assertFalse(self.app.exists())

    def test_dependencies_are_selected_before_consumer(self):
        self.change(lambda patterns: patterns["HasSlug"].update(dependencies=["HasTimestamps"]))
        self.assertEqual(["HasTimestamps", "HasSlug"], catalog.copy_pattern(self.root, self.app, "HasSlug"))

    def test_unchanged_dependency_can_be_reused(self):
        catalog.copy_pattern(self.root, self.app, "HasTimestamps")
        self.change(lambda patterns: patterns["HasSlug"].update(dependencies=["HasTimestamps"]))
        self.assertEqual(["HasSlug"], catalog.copy_pattern(self.root, self.app, "HasSlug"))

    def test_modified_dependency_requires_manual_reconciliation(self):
        catalog.copy_pattern(self.root, self.app, "HasTimestamps")
        (self.app / "spec/patterns/HasTimestamps.yml").write_text("customized")
        self.change(lambda patterns: patterns["HasSlug"].update(dependencies=["HasTimestamps"]))
        with self.assertRaisesRegex(ValueError, "local edits"):
            catalog.copy_pattern(self.root, self.app, "HasSlug")
        self.assertFalse((self.app / "spec/patterns/HasSlug.yml").exists())

    def test_cycle_is_rejected(self):
        self.change(lambda patterns: patterns["HasSlug"].update(dependencies=["HasSlug"]))
        with self.assertRaisesRegex(ValueError, "cycle"):
            catalog.read_catalog(self.root)

    def test_path_traversal_is_rejected(self):
        self.change(lambda patterns: patterns["HasSlug"].update(files={"patterns/HasSlug/pattern.yml": "../escape"}))
        with self.assertRaisesRegex(ValueError, "Unsafe path"):
            catalog.copy_pattern(self.root, self.app, "HasSlug")

    def test_symlink_destination_is_rejected(self):
        self.app.mkdir()
        elsewhere = self.app.parent / "elsewhere"
        elsewhere.mkdir()
        (self.app / "spec").symlink_to(elsewhere, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "Symlink"):
            catalog.copy_pattern(self.root, self.app, "HasSlug")
        self.assertEqual([], list(elsewhere.iterdir()))

    def test_reinstall_does_not_overwrite(self):
        catalog.copy_pattern(self.root, self.app, "HasSlug")
        before = (self.app / catalog.LOCK).read_bytes()
        with self.assertRaisesRegex(ValueError, "already installed"):
            catalog.copy_pattern(self.root, self.app, "HasSlug")
        self.assertEqual(before, (self.app / catalog.LOCK).read_bytes())


if __name__ == "__main__":
    unittest.main()
