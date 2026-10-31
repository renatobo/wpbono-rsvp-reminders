"""Check the generated installable ZIP without extracting it."""
from pathlib import Path, PurePosixPath
import zipfile
import re

slug = "wpbono-rsvp-reminders"
version = re.search(r"^\s*\*\s*Version:\s*(\S+)", Path(f"{slug}.php").read_text(), re.M).group(1)
filename = f"{slug}-v{version}.zip"
package = Path("dist") / filename
with zipfile.ZipFile(package) as archive:
    assert archive.testzip() is None, "Corrupt ZIP member"
    names = archive.namelist()
    assert names, "Empty ZIP"
    for name in names:
        path = PurePosixPath(name)
        assert not path.is_absolute() and ".." not in path.parts, name
        assert path.parts[0] == slug, name
        assert not any(part.startswith(".") or part in {"vendor", "tests", "node_modules"} for part in path.parts), name
        assert path.name not in {"composer.json", "composer.lock", "build.sh", "CLAUDE.md", "AGENTS.md", "LOCAL-DEV.md"}, name
    assert f"{slug}/{slug}.php" in names
    assert f"{slug}/uninstall.php" in names
print(f"Validated {package}")
