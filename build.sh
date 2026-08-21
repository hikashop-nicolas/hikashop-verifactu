#!/usr/bin/env bash
# Builds the installable Joomla package.
#
#   ./build.sh
#
# It installs the Composer dependencies without the development ones, applies
# the patches the plugin needs on top of eseperio/verifactu-php, drops the parts
# of the vendor tree a website never runs (the 9.6 MB of AEAT PDFs, the tests),
# and writes build/plg_hikashop_verifactu_v<version>.zip, version read from the
# manifest so there is only one place to bump it.

set -euo pipefail

cd "$(dirname "$0")"

PLUGIN="plg_hikashop_verifactu"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' verifactu.xml | head -n1)

if [ -z "$VERSION" ]; then
	echo "could not read <version> from verifactu.xml" >&2
	exit 1
fi

command -v composer >/dev/null 2>&1 || { echo "composer is required" >&2; exit 1; }

echo "== dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
php scripts/apply-vendor-patches.php

STAGE="build/$PLUGIN"
rm -rf build
mkdir -p "$STAGE"

echo "== assembling"
cp verifactu.php verifactu.xml script.php composer.json "$STAGE/"
cp -R src sql fields layouts vendor "$STAGE/"

# Documentation and test material of the dependencies: never executed, and the
# AEAT specification PDFs alone are ten times the size of everything else.
rm -rf "$STAGE"/vendor/eseperio/verifactu-php/{docs,tests,Dockerfile,phpunit.xml}
find "$STAGE/vendor" -type d \( -name .git -o -name tests -o -name Tests -o -name .github \) -prune -exec rm -rf {} +

echo "== packing"
ZIP="$PLUGIN"_v"$VERSION".zip
( cd build && zip -qr "$ZIP" "$PLUGIN" -x '*.DS_Store' )

echo "built build/$ZIP ($(du -h "build/$ZIP" | cut -f1))"
