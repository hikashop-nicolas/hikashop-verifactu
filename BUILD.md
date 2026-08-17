# Building the package

The plugin's own code is what lives in this repository. The libraries it runs on
are not committed: they are fetched by Composer and patched at build time, so a
clone is small and every build starts from a known state.

If you only want to install the plugin, you do not need any of this: the built
package is attached to every
[release](https://github.com/hikashop-nicolas/hikashop-verifactu/releases/latest).

## Requirements

PHP 8.1 or newer with the soap, dom, openssl and gd extensions, Composer, and
the zip command.

## Build

```
./build.sh
```

It writes `build/plg_hikashop_verifactu_v<version>.zip`, ready to install
through the Joomla extension manager. The version comes from `verifactu.xml`,
which is the only place where it is written.

## What the build does

1. `composer install --no-dev` fetches the libraries listed in `composer.json`,
   at the exact commits recorded in `composer.lock`.
2. `scripts/apply-vendor-patches.php` applies the three fixes the plugin needs
   on top of `eseperio/verifactu-php`, listed below. Composer also runs it by
   itself after an install or an update.
3. The parts of the vendor tree a website never runs are dropped: the AEAT
   specification PDFs shipped with the library (9.6 MB on their own) and the
   test suites. The package goes from 8 MB to under 400 kB.

## The vendor patches

`eseperio/verifactu-php` is required as `dev-master`, so any install brings the
unpatched files back. The patches fix, in the library:

- the fingerprint being computed from the raw date while the XML carries it as
  DD-MM-YYYY, which the AEAT rejects with error 2000;
- the same date problem in the address behind the QR code;
- that address using the parameter name `num` instead of `numserie`, and
  carrying no `importe` at all.

The script refuses to run silently if a patch no longer matches: an unpatched
build looks fine and is rejected by the AEAT, which is exactly what must not
happen quietly. If the library fixes one of these upstream, drop that patch
from the script.

## Continuous integration

`.github/workflows/build.yml` syntax checks the plugin on every push and builds
the package, which is kept as a run artifact.

Releases are cut by hand, not on every commit: bump `<version>` in
`verifactu.xml`, add the entry to `CHANGELOG.md`, then push the matching tag.

```
git tag v0.31.0
git push origin v0.31.0
```

The workflow then builds that tag and attaches
`plg_hikashop_verifactu_v0.31.0.zip` to the release, which is where the download
link in the README points.
