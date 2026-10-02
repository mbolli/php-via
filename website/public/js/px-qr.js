// <px-qr value="https://..."> draws the pairing QR code with uqr (vendor/uqr.js, MIT): square navy modules and
// violet finder rings on a light ground in both colour schemes, because some phone cameras do not read inverted codes.

// The same breakpoint as site.css, which hides the code on phones: nothing to scan there.
const narrow = matchMedia('(max-width: 640px)')
// Version 4 at least, which the live origin needs anyway: 41 modules, 5 px each in site.css. An origin longer than
// about 30 characters needs version 5, which the same box draws at 4.6 px per module.
const OPTIONS = { ecc: 'M', border: 4, minVersion: 4 }
const MODULE_PX = 5
const STYLE = '<style>:host{display:block;line-height:0}:host([hidden]){display:none}'
  + 'svg{display:block;width:100%;height:100%}.paper{fill:#f3f4fa}.data{fill:#10122e}.ring{fill:#5a3fd6}</style>'

let uqr
let failures = 0
// The browser caches a failed module fetch, so a retry needs another URL
const load = () => (uqr ??= import(`./vendor/uqr.js${failures ? `?retry=${failures}` : ''}`))

// 1 for the dark ring of a finder square (uqr type 2, "Position", 3 modules from its centre); its centre dot goes with the data
const ring = ({ types, size }, x, y) => {
  const centre = (v) => (v < size / 2 ? OPTIONS.border + 3 : size - OPTIONS.border - 4)
  return +(types[y][x] === 2 && Math.max(Math.abs(x - centre(x)), Math.abs(y - centre(y))) === 3)
}

// Dark modules as horizontal runs, [data, rings]: the loop of Starbase's sb-qr-code (MIT)
const paths = (qr) => {
  const d = ['', '']
  qr.data.forEach((row, y) => {
    for (let x = 0, s, k; x < row.length; ) {
      if (!row[x]) { x++; continue }
      k = ring(qr, x, y)
      for (s = x; row[x] && ring(qr, x, y) === k; ) x++
      d[k] += `M${s} ${y}h${x - s}v1h-${x - s}z`
    }
  })
  return d
}

customElements.define('px-qr', class extends HTMLElement {
  static observedAttributes = ['value']
  #drawn = null
  #render = () => this.render()

  constructor() {
    super()
    this.attachShadow({ mode: 'open' }).innerHTML = STYLE
  }

  connectedCallback() {
    narrow.addEventListener('change', this.#render)
    this.render()
  }

  disconnectedCallback() {
    narrow.removeEventListener('change', this.#render)
  }

  attributeChangedCallback(name, old, value) {
    if (old !== value && this.isConnected) this.render()
  }

  async render() {
    const value = this.getAttribute('value')
    if (value === this.#drawn || (value && narrow.matches)) return
    let encode
    try {
      ({ encode } = await load())
    } catch {
      uqr = undefined
      if (++failures < 4) setTimeout(this.#render, 2000 * failures)
      return
    }
    // Another call may have drawn it, or a morph brought another URL, while uqr loaded
    if (this.getAttribute('value') !== value || value === this.#drawn) return
    this.#drawn = value
    if (!value) {
      this.shadowRoot.innerHTML = STYLE
      return
    }
    let qr
    try {
      qr = encode(value, OPTIONS)
    } catch {
      this.shadowRoot.innerHTML = STYLE
      return
    }
    const n = qr.size
    const [data, rings] = paths(qr)
    this.shadowRoot.innerHTML = `${STYLE}<svg viewBox="0 0 ${n} ${n}" width="${n * MODULE_PX}" height="${n * MODULE_PX}" shape-rendering="crispEdges" aria-hidden="true">`
      + `<rect class="paper" width="${n}" height="${n}"/><path class="data" d="${data}"/><path class="ring" d="${rings}"/></svg>`
  }
})
