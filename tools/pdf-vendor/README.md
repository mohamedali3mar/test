# PDF engine: vendored mPDF and Cairo fonts

The app generates PDFs on the server with [mPDF](https://github.com/mpdf/mpdf) (`public_html/app/lib/pdf.php`).
Hostinger shared hosting has no shell or Composer, so the library is committed ready to upload:

| Path | Content | Size |
|---|---|---|
| `public_html/app/vendor/` | mPDF 8.3.1 and its dependencies, pruned (no tests, docs, VCS data, unused fonts) | about 7.1 MB, 578 files |
| `public_html/app/fonts/` | `Cairo-Regular.ttf`, `Cairo-Bold.ttf` (static, built from the variable font) and `OFL.txt` | about 330 KB |
| `public_html/app/storage/pdf-cache/` | mPDF font metrics cache, created on first use (protected by `.htaccess`, ignored by git) | empty in the repository |

Everything under `public_html/app/` is denied to HTTP by `app/.htaccess`; the vendor and fonts
folders also carry the usual `index.php` guard.

This folder (`tools/pdf-vendor/`) is build-only and is never uploaded.

## Rebuild

Requirements on the development machine: PHP 8.1+, Composer, curl, Python 3 with fontTools
(`pip install fonttools`).

```sh
tools/pdf-vendor/build.sh vendor   # composer install + prune + copy to public_html/app/vendor
tools/pdf-vendor/build.sh fonts    # download Cairo, build static instances into public_html/app/fonts
tools/pdf-vendor/build.sh          # both
```

Then run the tests (they must pass) and look at the PNGs:

```sh
WOOD_TEST_DB=wood_pdf_test php tests/pdf.php        # writes tests/output/pdf/*.pdf and *.png
WOOD_TEST_DB=wood_pdf_sample php tools/pdf-sample.php --rows=5000   # manual samples
```

### What `build.sh vendor` does

1. `composer install --no-dev --optimize-autoloader --classmap-authoritative` in this folder.
   `composer.lock` pins the exact versions; `composer.json` targets PHP 8.1 (`config.platform`),
   the minimum the app supports. Upgrade on purpose with `composer update mpdf/mpdf`, then rebuild
   and re-test. `pdf_text_measurer()` reads mPDF's private `otl` property to measure shaped text
   for report column widths. If an upgrade renames it, measurement falls back to unshaped widths:
   still safe (wider), but reports wrap more, so check the report PNGs after upgrading.
2. Removes VCS folders, CI files, tests, docs, fixtures and dev tooling from every package,
   mPDF's `tmp/` folder (the app uses `app/storage/pdf-cache`), and every bundled font in
   `mpdf/mpdf/ttfonts/` except `DejaVuSans.ttf`, `DejaVuSans-Bold.ttf` and `DejaVuinfo.txt`.
   DejaVu Sans is the fallback for characters Cairo does not have (for example Cyrillic or symbols).
3. Checks that all license files are still present, then replaces `public_html/app/vendor`.

### What `build.sh fonts` does

Downloads `Cairo[slnt,wght].ttf` and `OFL.txt` from
`https://raw.githubusercontent.com/google/fonts/main/ofl/cairo/` and runs `cairo_instance.py`,
which uses `fontTools.varLib.instancer` to make static instances at wght 400 (Regular) and
700 (Bold), slnt 0. mPDF cannot use variable fonts or woff2. The script also makes three
compatibility changes, all needed for correct output in mPDF:

1. **U+202F narrow no-break space.** Cairo lacks it, but `digits()` in `app/lib/format.php` uses it
   as the thousands separator for Arabic-Indic digits. The script adds an empty glyph 160 units wide
   (the normal space is 220), so amounts like `١٢ ٣٤٥٫٥٠` keep a narrow separator instead of a
   missing-glyph box.
2. **Mark filtering sets.** mPDF refuses fonts whose lookups use GDEF mark filtering sets
   ("contains MarkGlyphSets - Not tested yet"). Cairo uses them in two mark-to-mark lookups.
   Each set is converted to an equivalent GDEF mark attachment class. The behaviour is the same
   because the sets are disjoint and the font had no attachment classes; the script asserts both.
3. **Unmapped required ligatures.** Cairo's `rlig` feature (always applied by mPDF) contains
   48 designer ligatures whose glyphs have no Unicode value, such as seen+noon (`حسن`) and
   sheen+yeh-hamza (`أنشئ`). mPDF maps such glyphs to Private Use Area codes, so those words could
   not be copied or searched in the PDF. These rules are removed, and the letters use the normal
   joined forms. Ligatures with a Unicode value, such as lam-alef, are kept.

The Cairo license (SIL Open Font License 1.1) declares no Reserved Font Name, so the modified
instances may keep the name "Cairo". `OFL.txt` is shipped next to the fonts.

## Licenses

- **mPDF is GPL-2.0-only** (`vendor/mpdf/mpdf/LICENSE.txt`). This is a private internal system
  delivered to the client with its full source code, which is compatible with the GPL. If the
  system is ever distributed to third parties, it must be under GPL-compatible terms with source.
- setasign/fpdi, myclabs/deep-copy, paragonie/random_compat, psr/log, psr/http-message,
  mpdf/psr-log-aware-trait and mpdf/psr-http-message-shim are MIT. Their license files, or the
  `license` field in their `composer.json` for the two mPDF helper packages that ship none, are kept.
- DejaVu Sans: Bitstream Vera license plus public-domain changes (`ttfonts/DejaVuinfo.txt`).
- Cairo: SIL Open Font License 1.1 (`public_html/app/fonts/OFL.txt`).

## Security notes (implemented in `app/lib/pdf.php`)

- All user data placed in HTML for mPDF is escaped with `h()`. Report rows are drawn as plain text
  with `WriteCell`, which never parses HTML.
- mPDF's HTTP client and local file loader are replaced with services that refuse every request.
  Images, stylesheets and other resources are never fetched, from the network or from disk.
  `whitelistStreamWrappers` is empty, `curlAllowUnsafeSslRequests` is false, and imports and
  annotation files are disabled.
