# Changelog

Versions are cut here, not on every commit: the version in `verifactu.xml` is
bumped when a package is worth publishing, a `v<version>` tag is pushed, and the
build workflow attaches the installable zip to the matching
[release](https://github.com/hikashop-nicolas/hikashop-verifactu/releases).

Every commit on `main` is built and published too, on the
[latest-build](https://github.com/hikashop-nicolas/hikashop-verifactu/releases/tag/latest-build)
pre-release, so a fix can be tested the moment it lands.

## 0.31.1

- **Uninstalling no longer deletes the HikaShop media folder.** The invoice
  layout override was shipped by a `<media destination="com_hikashop">` tag.
  Joomla deletes the whole destination folder of such a tag when the extension
  is uninstalled, so removing the plugin took `media/com_hikashop` with it:
  HikaShop's css, js, images, mail templates and the files uploaded to
  `upload/safe`. The front end and the back end were left unstyled. The layout
  is now copied into `media/com_hikashop/plugins/invoice.php` by the install
  script and that single file is removed on uninstall. A layout already present
  and not written by this plugin is kept, with a warning.

  Sites which already uninstalled a previous version get their media folder
  back by reinstalling HikaShop over the existing installation. Files served
  from `media/com_hikashop/upload/safe` have to come from a backup.

## 0.31.0

First package built from this repository. It carries every fix listed below on
top of nicobraam's 0.30.32.

- **Installs on MySQL.** The install SQL created the two order columns with
  `ADD COLUMN IF NOT EXISTS`, which only MariaDB accepts. On MySQL the statement
  is a syntax error, the installer stopped there, and the custom fields which
  follow it were never created. The columns are created by an install script
  guarded by an `INFORMATION_SCHEMA` lookup, on install and on update.
- **Runs without the Joomla backward compatibility plugin.** The plugin used
  `JPlugin`, `JFactory` and `JLog`, so on Joomla 5 and 6 it stopped loading the
  day that plugin was disabled. All of them are now the namespaced classes.
- **No more log readable over the web.** Every operation was appended to
  `debug.log` inside the plugin folder, order numbers and NIFs included. Logging
  goes through Joomla, into its own log folder.
- **A wrong tax breakdown is no longer declared.** When an order carried no
  usable tax information, the plugin invented a line at 21 percent and sent it.
  It now refuses the submission and says so in the log.
- **The taxable base matches the invoice total.** With shipping or payment fees,
  the base taken from HikaShop was inflated by twice the fee tax, so base plus
  cuota did not equal the total declared. The base is derived from the cuota
  itself.
- **One version number.** The manifest, the responsible declaration and the
  `SistemaInformatico` block sent to the AEAT all read the version from
  `verifactu.xml`. The block identifies the system as "HikaShop VeriFactu" at
  its real version, instead of "HikaShop" version 1.0.
- **Smaller package, reproducible build.** The libraries are fetched by Composer
  at pinned commits and patched at build time instead of being committed, and
  the AEAT PDFs and test suites bundled with them are left out. The zip goes
  from 8 MB to under 400 kB.

## 0.30.32

Last version published by nicobraam (Locker25) on the HikaShop forum, GPLv2:
https://www.hikashop.com/forum/install-update/910753-verifactu.html#373060
