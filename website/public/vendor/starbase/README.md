# Starbase components

Three components from [Starbase](https://starbase.zweiundeins.gmbh) v0.6.0 (commit `29e58a9`),
as its catalog snapshot `@4cfa9b656272` pins them: the minified files Starbase serves at
`https://starbase.zweiundeins.gmbh/c/<folder>/<file>`, unmodified. Each folder keeps the version
hash in its name, as Starbase's URLs do, so a new version gets a new URL and no browser runs a
cached old file against a new hash.

`src/StarbaseComponents.php` puts them into php-via's import map: each tag maps to its module,
and every file has the hash from the snapshot's `/c/@4cfa9b656272/importmap.json`.
`tests/Feature/WebsiteStarbaseTest.php` checks the hashes against the files. The site serves these
folders as `immutable` for a year, so never change a file inside an existing folder.

| Tag | File | Bytes | Integrity |
|---|---|---|---|
| `sb-copy-button` | `copy-button@9d292216a6f8/copy-button.min.js` | 7147 | `sha384-aMkZmUDhQjFqYLWP9bVqgSU08IrkWqNopw1Svh6yC18xbgsJbTAV9ndgjpW/gj7Q` |
| `sb-odometer` | `odometer@a3e5a90d0ca0/odometer.min.js` | 3450 | `sha384-RBm01x1ojV5PgaOVyhKU9P2L54HRDDa1MK2EEmVGp4fdT/2PYvq6X7NS5j89zU8Q` |
| `sb-qr-code` | `qr-code@ecc5a99c314a/qr-code.min.js` | 1884 | `sha384-xSwmJUzaHfLDWJKbZJhr17n5cxupQxa0YcTvRo9uzTJGoA0uamggorZRgVx84LmG` |
| imported by `sb-qr-code` | `qr-code@ecc5a99c314a/vendor/uqr.min.mjs` | 11641 | `sha384-lg8RyFvkbOp4oa6Ny6ZQLHos0CvIWFIdAQyc0NFGwKwdqeQIJJuF1Xh6tAlkACb3` |

The readable sources are the files of the same name without `.min` in Starbase's
`components/<slug>/` at that commit. The components import `datastar`, which php-via maps to the
Datastar + Rocket build it serves with `withDatastarRocket()`; those are the bytes of Starbase's
`datastar@f602fbe19d74` build.

## Updating

1. Take the current snapshot hash from the Pinned tab of a component page on Starbase.
2. Download each file from `/c/<slug>@<hash>/` into a folder of the same name, and take its
   hash from `/c/@<catalog>/importmap.json`.
3. Update `MODULES` and `INTEGRITY` in `src/StarbaseComponents.php` and this README, and delete
   the old folders.

## Licence

Starbase is MIT licensed, Copyright (c) 2026 zwei und eins gmbh and starbase contributors, see
`LICENSE`. `qr-code@ecc5a99c314a/vendor/uqr.min.mjs` is [uqr](https://github.com/unjs/uqr) 0.1.3
(`dist/index.mjs`), minified by Starbase with esbuild. uqr is MIT licensed, Copyright (c) Project
Nayuki and (c) 2023 Anthony Fu, see `qr-code@ecc5a99c314a/vendor/uqr.LICENSE`.
