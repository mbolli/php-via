# Bundled Datastar

php-via serves one of these files at `/datastar.js`. Both are unmodified copies of the files named
below; `tests/Unit/Support/DatastarBundleTest.php` checks the hashes.

## datastar.js

- Datastar v1.0.5, the official plain bundle. Served by default.
- Source: https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.5/bundles/datastar.js
  (`bundles/datastar.js` at tag v1.0.5, commit fb03989a5fbb5584d15198e1a4bac151a848ef08).
- sha256: `7380e0344b7f937d80ad70ededa1c28578173157ee4748be1850991491d911fd`
- 35,673 bytes, 12,821 bytes with brotli -q 11.

## datastar-rocket.js

- Datastar v1.0.5 with Rocket beta.3 and one Starbase patch. Served instead of the plain bundle
  with `Config::withDatastarRocket()`.
- Source: Starbase (https://github.com/zweiundeins/starbase), `static/vendor/datastar-rocket.js`
  at commit 7dc235215b85a6487d9ea9ea40c6c963921bf8db, the same bytes Starbase serves to its own
  components as `/c/datastar@dad2261a81d8/datastar-rocket.js`.
- sha256: `dad2261a81d8e38db5d77bf274d9d2905c2ea5b0a30bd2673dc7810254e2b61e`
- 72,805 bytes, 23,439 bytes with brotli -q 11.
- Built from the v1.0.5 tag's `library/src/bundles/datastar-rocket.ts` with the one patch in
  Starbase's `patches/rocket/`: 0002, an observer hears an attribute write whose value decodes the
  same (#1223). Upstream keeps its behaviour there, and Starbase's components work the same on the
  official bundle. php-via serves Starbase's build so that pages run the engine Starbase tests its
  components against.

## Updating

1. Plain bundle: download `bundles/datastar.js` of the new tag to `public/datastar.js`.
2. Rocket bundle: copy `static/vendor/datastar-rocket.js` from Starbase's main branch to
   `public/datastar-rocket.js`, and check that it matches the `/c/datastar@<hash>/` file in the
   current snapshot's `importmap.json` (see `website/public/vendor/starbase/README.md`). Starbase
   builds it with `go tool task vendor`, which applies `patches/rocket/` to the Datastar tag.
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
