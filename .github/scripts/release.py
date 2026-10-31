"""Validate release inputs; never interpolate PR data into shell source."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys


def eligible_pr(pr):
    if not pr.get("merged") or pr.get("base", {}).get("ref") != "main":
        raise ValueError("Release requires a PR merged into main")
    if "release" not in [label["name"] for label in pr.get("labels", [])]:
        raise ValueError("Merged PR must have the release label")
    sha = pr.get("merge_commit_sha", "")
    if not re.fullmatch(r"[0-9a-f]{40}", sha):
        raise ValueError("Missing valid merge commit")
    return sha


def metadata(root):
    text = (root / "wpbono-rsvp-reminders.php").read_text()
    header = re.search(r"^\s*\* Version:\s*(\S+)", text, re.M)
    constant = re.search(r"define\('WPBONO_RSVP_REMINDERS_VERSION', '([^']+)'\)", text)
    if not header or not constant or header[1] != constant[1]:
        raise ValueError("Plugin header and version constant must match")
    version = header[1]
    if not re.fullmatch(r"(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)", version):
        raise ValueError("Release version must be X.Y.Z")
    notes = root / "release-notes" / (version + ".md")
    content = notes.read_text()
    for heading in ["## New Features", "## Improvements", "## Bug Fixes"]:
        if heading not in content.splitlines():
            raise ValueError("Release notes missing " + heading)
    return version


def output(key, value):
    with open(os.environ["GITHUB_OUTPUT"], "a") as stream:
        stream.write(f"{key}={value}\n")


if __name__ == "__main__":
    if sys.argv[1] == "resolve":
        number = os.environ["PR_NUMBER"]
        if not re.fullmatch(r"[1-9][0-9]*", number):
            raise ValueError("PR number must be a positive integer")
        pr = json.loads(subprocess.check_output([
            "gh", "api", f"repos/{os.environ['GH_REPO']}/pulls/{number}"
        ], text=True))
        output("sha", eligible_pr(pr))
    elif sys.argv[1] == "metadata":
        output("version", metadata(Path.cwd()))
    else:
        raise ValueError("Unknown command")
