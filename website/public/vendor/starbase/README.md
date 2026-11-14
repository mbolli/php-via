# Starbase components

Three components from [Starbase](https://starbase.zweiundeins.gmbh) v0.8.0 (commit `4d32e31`),
as its catalog snapshot `@a6cffca9d15a` pins them: the minified files Starbase serves at
`https://starbase.zweiundeins.gmbh/c/<folder>/<file>`, unmodified. Each folder keeps the version
hash in its name, as Starbase's URLs do, so a new version gets a new URL and no browser runs a
cached old file against a new hash.

`src/StarbaseComponents.php` puts them into php-via's import map: each tag maps to its module,
and every file has the hash from the snapshot's `/c/@a6cffca9d15a/importmap.json`.
`tests/Feature/WebsiteStarbaseTest.php` checks the hashes against the files. The site serves these
folders as `immutable` for a year, so never change a file inside an existing folder.

| Tag | File | Bytes | Integrity |
|---|---|---|---|
| `sb-copy-button` | `copy-button@2a9e7e7646d2/copy-button.min.js` | 7147 | `sha384-aMkZmUDhQjFqYLWP9bVqgSU08IrkWqNopw1Svh6yC18xbgsJbTAV9ndgjpW/gj7Q` |
| `sb-odometer` | `odometer@51fcd47a02c6/odometer.min.js` | 3453 | `sha384-ON7wS6L5jW+lSU5TEOmFR0X/l+MunP/mNPyssll3wm9JSgCtqOZoWPkQlJohcvM3` |
| `sb-qr-code` | `qr-code@0d8b2096aa94/qr-code.min.js` | 1965 | `sha384-lbjsew2dJTUODW2J/zRCPydPl1j6LcSeJ0H0fH4JlaxZr8Xmf/tIoHEtmuaXikbz` |
| imported by `sb-qr-code` | `qr-code@0d8b2096aa94/vendor/uqr.min.mjs` | 11641 | `sha384-lg8RyFvkbOp4oa6Ny6ZQLHos0CvIWFIdAQyc0NFGwKwdqeQIJJuF1Xh6tAlkACb3` |

The readable sources are the files of the same name without `.min` in Starbase's
`components/<slug>/` at that commit. The components import `datastar`, which php-via maps to the
Datastar + Rocket build it serves with `withDatastarRocket()`; those are the bytes of Starbase's
`datastar@dad2261a81d8` build.

## Updating

1. Take the current snapshot hash from the Pinned tab of a component page on Starbase.
2. Download each file from `/c/<slug>@<hash>/` into a folder of the same name, and take its
   hash from `/c/@<catalog>/importmap.json`.
3. Update `MODULES` and `INTEGRITY` in `src/StarbaseComponents.php` and this README, and delete
   the old folders.

## Licence

Starbase is MIT licensed, Copyright (c) 2026 zwei und eins gmbh and starbase contributors, see
`LICENSE`. `qr-code@0d8b2096aa94/vendor/uqr.min.mjs` is [uqr](https://github.com/unjs/uqr) 0.1.3
(`dist/index.mjs`), minified by Starbase with esbuild. uqr is MIT licensed, Copyright (c) Project
Nayuki and (c) 2023 Anthony Fu, see `qr-code@0d8b2096aa94/vendor/uqr.LICENSE`.
