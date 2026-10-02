# Bundled Datastar

php-via serves one of these files at `/datastar.js`. Both are unmodified copies of the files named
below; `tests/Unit/Support/DatastarBundleTest.php` checks the hashes.

## datastar.js

- Datastar v1.0.4, the official plain bundle. Served by default.
- Source: https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.4/bundles/datastar.js
  (`bundles/datastar.js` at tag v1.0.4, commit 1efcdc3cb336ec3e9139491604e770e0329657bc).
- sha256: `727844adfc825ee651fb93c544a2a739986f9a21820a94524b35f0cac470cf91`
- 33,553 bytes, 12,183 bytes with brotli -q 11.

## datastar-rocket.js

- Datastar v1.0.4 with Rocket beta.2 and Starbase's Rocket patches. Served instead of the plain
  bundle with `Config::withDatastarRocket()`.
- Source: Starbase (https://github.com/zweiundeins/starbase), `static/vendor/datastar-rocket.js`
  at commit be49eed631c25538f7c8b9e30c2d5cc7c90fb404.
- sha256: `f602fbe19d74e4c16c2f28b3a92c4934072400337372787561089c26a8b3e1db`
- 68,224 bytes, 21,958 bytes with brotli -q 11.
- Built from the v1.0.4 tag's `library/src/bundles/datastar-rocket.ts` with these patches from
  Starbase's `patches/rocket/` (upstream issue in brackets):
  - 0001 Rocket: finish the teardown when deleting the signals throws (#1217)
  - 0002 Rocket: keep the element as it is on an atomic move (#1218)
  - 0003 Rocket: form-associated components and focus delegation (#1220)
  - 0004 Rocket: bool props follow HTML boolean attributes (#1219)
  - 0005 Rocket: clean up the shadow tree's attributes on disconnect (#1221)
  - 0006 Rocket: instances share one constructed stylesheet per CSS text (#1222)
  - 0007 Rocket: observers hear an attribute write whose value decodes the same (#1223)
  - 0008 Morph: a morph that starts inside another keeps the outer one's pantry and id maps (#1209)
  - 0009 Rocket: a light component renders inside a data-ignore-morph ancestor (#1224)
  - 0010 Engine: a removed Rocket element's mount root leaves the observed roots (#1225)
  - 0011 Rocket: a queued definition applies the shadow host's children that Datastar's first
    pass skipped (#1226)
  - 0012 Rocket: the pending-host observer scans a parent once per batch, not once per moved
    child (#1227)

  The official `datastar-rocket.js` of v1.0.4 crashes the morph when it reorders keyed elements
  that contain a Rocket element; 0002 and 0008 fix that.

## Updating

1. Plain bundle: download `bundles/datastar.js` of the new tag to `public/datastar.js`.
2. Rocket bundle: in Starbase, run `DATASTAR_VERSION=<tag> sh scripts/vendor-rocket.sh` (it
   downloads the official Rocket bundle for the banner, clones the tag, applies
   `patches/rocket/*.patch` and builds with esbuild), then copy `static/vendor/datastar-rocket.js`
   to `public/datastar-rocket.js`. Rebuilding v1.0.4 with esbuild v0.28.2 and the patches at
   Starbase commit 29e58a96558735001903614d06f22929d18e63b8 gives the same bytes as the file here.
3. Update the versions, hashes and sizes in this file and in `DatastarBundleTest`.

The bundles end with a `sourceMappingURL` comment; php-via does not serve the maps.

## Licences

Datastar, including Rocket, is MIT licensed:

    Copyright © Star Federation

Starbase, including its patches, is MIT licensed:

    Copyright (c) 2026 zwei und eins gmbh and starbase contributors

Both under these terms:

    Permission is hereby granted, free of charge, to any person obtaining a copy of this software
    and associated documentation files (the "Software"), to deal in the Software without
    restriction, including without limitation the rights to use, copy, modify, merge, publish,
    distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the
    Software is furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all copies or
    substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING
    BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
    NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
    DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
    OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
