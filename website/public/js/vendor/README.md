# Vendored scripts

`uqr.js` is `dist/index.mjs` from the npm package [uqr](https://github.com/unjs/uqr) 0.1.3,
unmodified, renamed to `.js` so the static file handler serves it as JavaScript. It is the
file Starbase v0.6.0 vendors in `components/qr-code/vendor/uqr.mjs`.

- Tarball integrity: `sha512-0rjE8iEJe4YmT9TOhwsZtqCMRLc5DXZUI2UEYUUg63ikBkqqE5EYWaI0etFe/5KUcmcYwLih2RND1kq+hrUJXA==`
- Licence: MIT, Copyright (c) Project Nayuki and (c) 2023 Anthony Fu, see `uqr.LICENSE`

`../px-qr.js` loads it to draw the pairing QR code on the homepage.
