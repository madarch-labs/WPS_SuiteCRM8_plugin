#!/usr/bin/env bash
# Builds the Module Loader zip: dist/wps_integration-<version>-suitecrm8.zip (manifest.php at the root of the archive).
# Upload it in Admin → Module Loader, or install it with bin/install.sh (which does not need the zip).
set -euo pipefail

PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SUFFIX=suitecrm8
VERSION="$(cat "$PKG_DIR/VERSION")"

MANIFEST_VERSION="$(sed -n "s/^ *'version' => '\(.*\)',/\1/p" "$PKG_DIR/package/manifest.php")"
[ "$MANIFEST_VERSION" = "$VERSION" ] || { echo "ERROR: VERSION ($VERSION) != manifest.php version ($MANIFEST_VERSION)" >&2; exit 1; }

mkdir -p "$PKG_DIR/dist"
ZIP="$PKG_DIR/dist/wps_integration-$VERSION-$SUFFIX.zip"
rm -f "$ZIP"
if command -v zip >/dev/null; then
  (cd "$PKG_DIR/package" && zip -qrX "$ZIP" .)
else
  # no zip binary: PHP's ZipArchive (any PHP CLI with ext-zip)
  php -r '
    [$src, $dst] = [$argv[1], $argv[2]];
    $z = new ZipArchive(); $z->open($dst, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { $z->addFile($f->getPathname(), substr($f->getPathname(), strlen($src) + 1)); }
    $z->close();' "$PKG_DIR/package" "$ZIP"
fi
echo "Built $ZIP"
unzip -l "$ZIP" 2>/dev/null | tail -n +4 | head -n -2 || true
