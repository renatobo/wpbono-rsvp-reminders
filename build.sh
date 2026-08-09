#!/bin/bash
#
# Package the plugin for a GitHub release asset.
#
# The zip contains a single top-level directory named for the plugin slug.
# That name is the key WordPress uses to recognise an installed copy, so it
# must stay wpbono-rsvp-reminders: a different one installs a second plugin
# beside the first and orphans its settings.
#
# Only the files listed below ship. Development notes (CLAUDE.md, AGENTS.md,
# ui.md, LOCAL-DEV.md) and this script stay out of the package.

set -euo pipefail

PLUGIN_SLUG="wpbono-rsvp-reminders"
PLUGIN_FILE="wpbono-rsvp-reminders.php"

if [[ ! -f "$PLUGIN_FILE" ]]; then
  echo "Expected plugin bootstrap file '$PLUGIN_FILE' in $PWD"
  exit 1
fi

VERSION="$(
  sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1
)"

if [[ -z "$VERSION" ]]; then
  echo "Could not determine plugin version from $PLUGIN_FILE"
  exit 1
fi

# The header Version and the constant are read from different places at
# runtime; a mismatch ships an update that reports the wrong version.
CONST_VERSION="$(
  sed -n "s/^define('WPBONO_RSVP_REMINDERS_VERSION', '\([^']*\)').*/\1/p" "$PLUGIN_FILE" | head -n 1
)"

if [[ "$VERSION" != "$CONST_VERSION" ]]; then
  echo "Version mismatch: header says '$VERSION', WPBONO_RSVP_REMINDERS_VERSION says '$CONST_VERSION'"
  exit 1
fi

OUTPUT_NAME="${PLUGIN_SLUG}-v${VERSION}.zip"
OUTPUT_PATH="$PWD/$OUTPUT_NAME"
STAGING_DIR="$(mktemp -d)"
PACKAGE_DIR="$STAGING_DIR/$PLUGIN_SLUG"
PACKAGE_PATHS=(
  "README.md"
  "wpbono-rsvp-reminders.php"
  "uninstall.php"
  "includes/settings.php"
  "includes/event-meta.php"
  "includes/scheduler.php"
  "includes/mailer.php"
  "assets/admin.css"
  "assets/icon.svg"
  "assets/icon-128x128.png"
  "assets/icon-256x256.png"
  "assets/wpbono-rsvp-reminders-settings-banner.svg"
)

cleanup() {
  rm -rf "$STAGING_DIR"
}

trap cleanup EXIT

if [[ -f "$OUTPUT_PATH" ]]; then
  rm -f "$OUTPUT_PATH"
fi

mkdir -p "$PACKAGE_DIR"

for relative_path in "${PACKAGE_PATHS[@]}"; do
  if [[ ! -e "$relative_path" ]]; then
    echo "Missing package path: $relative_path"
    exit 1
  fi

  destination_dir="$PACKAGE_DIR/$(dirname "$relative_path")"
  mkdir -p "$destination_dir"
  cp -pR "$relative_path" "$destination_dir/"
done

(
  cd "$STAGING_DIR"
  zip -rq "$OUTPUT_PATH" "$PLUGIN_SLUG"
)

echo "Created $OUTPUT_PATH"
