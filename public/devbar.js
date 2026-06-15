// Via Dev Bar — a self-contained debug overlay web component.
//
// Renders inside a Shadow DOM so neither the host page's CSS nor its Datastar
// runtime can interfere. Boot data arrives as data-* attributes from the
// server-side Injector; live traces stream over EventSource('/_via/stream').
//
// Tabs: Traces · Signals · SSE/Patches · Request · Scopes · Errors.

const CATEGORY_COLORS = {
  request: '#8b949e',
  render: '#3fb950',
  cache: '#d29922',
  db: '#58a6ff',
  sse: '#a371f7',
  app: '#39c5cf',
};

const STYLES = `
  :host {
    --bg: #0d1117; --bg2: #161b22; --bg3: #21262d;
    --fg: #c9d1d9; --fg-dim: #8b949e; --border: #30363d;
    --accent: #58a6ff; --danger: #f85149;
    color-scheme: dark;
    font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
    font-size: 12px; line-height: 1.5;
  }
  * { box-sizing: border-box; }

  .pill {
    position: fixed; bottom: 12px; right: 12px; z-index: 2147483646;
    display: flex; align-items: center; gap: 8px;
    background: var(--bg2); color: var(--fg); border: 1px solid var(--border);
    border-radius: 6px; padding: 6px 10px; cursor: pointer;
    box-shadow: 0 4px 16px rgba(0,0,0,.4); user-select: none;
  }
  .pill:hover { border-color: var(--accent); }
  .pill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); }
  .pill .dot.err { background: var(--danger); }
  .pill b { color: var(--fg); font-weight: 600; }
  .pill .muted { color: var(--fg-dim); }

  .panel {
    position: fixed; z-index: 2147483647;
    background: var(--bg); color: var(--fg); border: 1px solid var(--border);
    display: flex; flex-direction: column; overflow: hidden;
  }
  :host([data-mode="page"]) .panel,
  .panel.page { inset: 0; border: none; }
  .panel.overlay {
    right: 12px; bottom: 12px; width: min(760px, calc(100vw - 24px));
    height: min(520px, calc(100vh - 24px)); border-radius: 8px;
    box-shadow: 0 8px 40px rgba(0,0,0,.5);
  }

  header {
    display: flex; align-items: center; gap: 4px; padding: 6px 8px;
    background: var(--bg2); border-bottom: 1px solid var(--border); flex: 0 0 auto;
  }
  header .brand { color: var(--fg-dim); padding: 0 6px; font-weight: 600; letter-spacing: .04em; }
  .tabs { display: flex; gap: 2px; flex: 1 1 auto; overflow-x: auto; }
  .tab {
    background: none; border: none; color: var(--fg-dim); cursor: pointer;
    padding: 4px 9px; border-radius: 5px; font: inherit; white-space: nowrap;
  }
  .tab:hover { background: var(--bg3); color: var(--fg); }
  .tab.active { background: var(--bg3); color: var(--fg); }
  .tab .badge {
    margin-left: 5px; background: var(--border); color: var(--fg);
    border-radius: 8px; padding: 0 5px; font-size: 10px;
  }
  .tab.active .badge { background: var(--accent); color: #fff; }
  .x {
    background: none; border: none; color: var(--fg-dim); cursor: pointer;
    font-size: 15px; line-height: 1; padding: 0; margin-left: 2px;
    width: 24px; height: 24px; flex: 0 0 auto;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .x:hover { color: var(--fg); }

  .body { flex: 1 1 auto; overflow: auto; padding: 6px; }
  .empty { color: var(--fg-dim); padding: 24px; text-align: center; }

  /* Traces */
  .trace { border: 1px solid var(--border); border-radius: 6px; margin-bottom: 6px; overflow: hidden; }
  .trace > summary {
    display: flex; align-items: center; gap: 10px; padding: 6px 10px; cursor: pointer;
    list-style: none; background: var(--bg2);
  }
  .trace > summary::-webkit-details-marker { display: none; }
  .trace[open] > summary { border-bottom: 1px solid var(--border); }
  .trace .label { font-weight: 600; flex: 0 0 auto; }
  .trace .label.err { color: var(--danger); }
  .trace .dur { color: var(--accent); }
  .trace .meta { color: var(--fg-dim); margin-left: auto; display: flex; gap: 10px; }
  .spans { padding: 6px 10px; }
  .span { padding: 2px 0; }
  .span-row { display: flex; align-items: center; gap: 8px; }
  .span-name { flex: 0 0 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .span-track { position: relative; flex: 1 1 auto; height: 14px; background: var(--bg3); border-radius: 3px; }
  .span-bar { position: absolute; top: 0; height: 100%; border-radius: 3px; min-width: 2px; }
  .span-ms { flex: 0 0 auto; color: var(--fg-dim); width: 68px; text-align: right; }
  .span-attrs { color: var(--fg-dim); padding-left: 208px; font-size: 11px; word-break: break-all; }
  .span-attrs .k { color: var(--accent); }
  .span.depth1 .span-name { padding-left: 12px; }
  .span.depth2 .span-name { padding-left: 24px; }
  .span.depth3 .span-name { padding-left: 36px; }

  /* Key/value rows (Signals, Request, Scopes) */
  .kv { width: 100%; border-collapse: collapse; }
  .kv td { padding: 4px 8px; border-bottom: 1px solid var(--border); vertical-align: top; }
  .kv td.k { color: var(--accent); white-space: nowrap; width: 1%; }
  .kv td.scope { color: var(--fg-dim); white-space: nowrap; }
  .kv input {
    background: var(--bg3); border: 1px solid var(--border); color: var(--fg);
    font: inherit; padding: 2px 6px; border-radius: 4px; width: 100%;
  }
  .kv input:focus { outline: none; border-color: var(--accent); }
  .tag { font-size: 10px; padding: 0 5px; border-radius: 8px; background: var(--bg3); color: var(--fg-dim); }
  .tag.rw { background: #1f6feb33; color: var(--accent); }

  /* Server/client log records */
  .log { font-size: 11px; }
  .log .row { display: flex; gap: 10px; padding: 3px 6px; border-bottom: 1px solid var(--border); }
  .log .row:hover { background: var(--bg2); }
  .log .t { color: var(--fg-dim); flex: 0 0 92px; }
  .log .lvl { flex: 0 0 40px; text-transform: uppercase; font-size: 10px; padding-top: 1px; color: var(--fg-dim); }
  .log .d { color: var(--fg); word-break: break-all; white-space: pre-wrap; }
  .log .d .src { color: var(--fg-dim); font-size: 10px; }
  .log .row.info .lvl { color: var(--accent); }
  .log .row.warn .lvl, .log .row.warn .d { color: #d29922; }
  .log .row.error .lvl, .log .row.error .d,
  .log .row.fatal .lvl, .log .row.fatal .d { color: var(--danger); }
  .log .row.debug { opacity: .5; }
  .logfilter { display: flex; gap: 4px; padding: 2px 2px 8px; position: sticky; top: 0; background: var(--bg); }
  .logfilter button {
    background: var(--bg3); border: 1px solid var(--border); color: var(--fg-dim);
    border-radius: 5px; padding: 2px 9px; cursor: pointer; font: inherit; font-size: 11px;
    text-transform: capitalize;
  }
  .logfilter button:hover { color: var(--fg); }
  .logfilter button.active { color: var(--fg); border-color: var(--accent); }
  .note { color: var(--fg-dim); padding: 4px 8px; font-size: 11px; font-style: italic; }

  /* SSE patch log (expandable rows) */
  .sse { font-size: 11px; }
  .sse-row { border-bottom: 1px solid var(--border); }
  .sse-row > summary {
    display: flex; align-items: center; gap: 10px; padding: 4px 6px; cursor: pointer;
    list-style: none; white-space: nowrap; overflow: hidden;
  }
  .sse-row > summary::-webkit-details-marker { display: none; }
  .sse-row:hover > summary { background: var(--bg2); }
  .sse-row .t { color: var(--fg-dim); flex: 0 0 92px; }
  .sse-row .ty { flex: 0 0 auto; color: var(--accent); }
  .sse-row.err .ty { color: var(--danger); }
  .sse-row .sz { color: var(--fg-dim); flex: 0 0 auto; }
  .sse-row .d { color: var(--fg-dim); overflow: hidden; text-overflow: ellipsis; }
  .sse-detail { padding: 2px 6px 8px; background: var(--bg2); }
  .sse-kv { display: flex; gap: 8px; margin-top: 6px; }
  .sse-kv .k { color: var(--accent); flex: 0 0 110px; padding-top: 6px; }
  .sse-kv pre {
    margin: 0; flex: 1 1 auto; white-space: pre-wrap; word-break: break-all;
    background: var(--bg); border: 1px solid var(--border); border-radius: 4px;
    padding: 6px; max-height: 260px; overflow: auto; color: var(--fg);
  }
  /* Payload-less lifecycle markers (started/finished): muted, non-expandable. */
  .sse-row.meta { display: flex; align-items: center; gap: 10px; padding: 2px 6px; opacity: .45; }
  .sse-row.meta .ty { color: var(--fg-dim); }
  .sse-row.meta:hover { opacity: .8; background: var(--bg2); }
  .sse-row.meta.err { opacity: .8; }
`;

class ViaDevBar extends HTMLElement {
  connectedCallback() {
    // Boot config arrives in a single non-`data-` attribute so Datastar (which
    // only scans data-* attributes) never treats it as page signals.
    const cfg = safeJSON(this.getAttribute('via-config'), {});
    this.base = cfg.base || '/';
    this.mode = cfg.mode || 'overlay';
    this.writes = !!cfg.writes;
    this.contextId = cfg.context || '';
    this.route = cfg.route || '';
    this.signals = Array.isArray(cfg.signals) ? cfg.signals : [];
    this.traces = (Array.isArray(window.__VIA_TRACES__) ? window.__VIA_TRACES__.slice() : []);
    this.sseEvents = [];
    this.logs = [];
    this.logFilter = 'info';
    this.scopes = null;
    this.activeTab = 'traces';
    this.expanded = this.mode === 'page';
    this.scopeTimer = null;

    this.attachShadow({ mode: 'open' });
    this.shadowRoot.innerHTML = `<style>${STYLES}</style><div class="root"></div>`;
    this.root = this.shadowRoot.querySelector('.root');

    this.render();
    this.connectStream();
    this.listenDatastar();
    this.listenErrors();
  }

  disconnectedCallback() {
    this.es?.close();
    if (this.scopeTimer) clearInterval(this.scopeTimer);
  }

  // ── data sources ──────────────────────────────────────────────────────────

  connectStream() {
    try {
      this.es = new EventSource(this.base + '_via/stream');
    } catch (_) { return; }

    // The stream multiplexes two named events: `trace` and `log`.
    this.es.addEventListener('trace', (e) => {
      const trace = safeJSON(e.data, null);
      if (!trace) return;
      this.traces.unshift(trace);
      if (this.traces.length > 200) this.traces.pop();
      if (this.expanded && this.activeTab === 'traces') this.renderBody();
      this.renderPill();
    });

    this.es.addEventListener('log', (e) => {
      const log = safeJSON(e.data, null);
      if (!log) return;
      this.logs.unshift({ t: log.time, level: log.level, message: log.message, source: 'server' });
      if (this.logs.length > 500) this.logs.pop();
      if (this.expanded && this.activeTab === 'logs') this.renderBody();
      this.renderPill();
    });
  }

  listenDatastar() {
    // Datastar dispatches `datastar-fetch` with detail {type, el, argsRaw}.
    // For SSE patches the type is e.g. "datastar-patch-elements" and argsRaw
    // carries the real payload: {elements, selector, mode} or {signals}.
    document.addEventListener('datastar-fetch', (evt) => {
      const d = evt.detail || {};
      this.sseEvents.unshift({ t: Date.now(), type: d.type || 'fetch', args: normalizeArgs(d.argsRaw) });
      if (this.sseEvents.length > 300) this.sseEvents.pop();
      if (this.expanded && this.activeTab === 'sse') this.renderBody();
    });
  }

  listenErrors() {
    window.addEventListener('error', (e) => {
      this.logs.unshift({ t: Date.now(), level: 'error', message: String(e.message || e.error || e), source: 'client' });
      if (this.expanded && this.activeTab === 'logs') this.renderBody();
      this.renderPill();
    });
  }

  errorCount() {
    return this.logs.reduce((n, l) => n + (l.level === 'error' || l.level === 'fatal' ? 1 : 0), 0);
  }

  async pollScopes() {
    const load = async () => {
      try {
        const r = await fetch(this.base + '_via/scopes', { headers: { Accept: 'application/json' } });
        this.scopes = await r.json();
        if (this.expanded && this.activeTab === 'scopes') this.renderBody();
      } catch (_) { /* ignore */ }
    };
    await load();
    if (!this.scopeTimer) this.scopeTimer = setInterval(load, 2000);
  }

  // ── rendering ───────────────────────────────────────────────────────────────

  render() {
    if (!this.expanded) { this.renderCollapsed(); return; }

    const tabs = [
      ['traces', 'Traces', this.traces.length],
      ['signals', 'Signals', this.signals.length],
      ['sse', 'SSE', this.sseEvents.length],
      ['request', 'Request', null],
      ['scopes', 'Scopes', null],
      ['logs', 'Logs', this.errorCount() || null],
    ];
    const cls = this.mode === 'page' ? 'page' : 'overlay';
    this.root.innerHTML = `
      <div class="panel ${cls}">
        <header>
          <span class="brand">via · lens</span>
          <div class="tabs">
            ${tabs.map(([id, label, n]) => `
              <button class="tab ${id === this.activeTab ? 'active' : ''}" data-tab="${id}">
                ${label}${n != null ? `<span class="badge">${n}</span>` : ''}
              </button>`).join('')}
          </div>
          <button class="x" data-act="reset" title="Clear traces & SSE">⟲</button>
          ${this.mode === 'page' ? '' : '<button class="x" data-act="close" title="Close">✕</button>'}
        </header>
        <div class="body"></div>
      </div>`;

    this.root.querySelectorAll('.tab').forEach((b) =>
      b.addEventListener('click', () => this.selectTab(b.dataset.tab)));
    this.root.querySelector('[data-act="reset"]')?.addEventListener('click', () => this.reset());
    this.root.querySelector('[data-act="close"]')?.addEventListener('click', () => this.toggle(false));
    this.renderBody();
  }

  renderCollapsed() {
    const last = this.traces[0];
    const hasErr = this.errorCount() > 0;
    this.root.innerHTML = `
      <div class="pill" data-act="open">
        <span class="dot ${hasErr ? 'err' : ''}"></span>
        <b>via</b>
        <span class="muted">${this.traces.length} traces</span>
        ${last ? `<span class="muted">· ${fmtMs(last.totalDurationMs)}</span>` : ''}
      </div>`;
    this.root.querySelector('[data-act="open"]').addEventListener('click', () => this.toggle(true));
  }

  renderPill() {
    if (this.expanded) return;
    this.renderCollapsed();
  }

  toggle(on) {
    this.expanded = on;
    this.render();
  }

  // Clear the Traces and SSE logs. Also empties the server-side trace buffer so
  // a reload / the standalone console don't replay the cleared traces. New
  // traces and events keep streaming in after.
  reset() {
    this.traces = [];
    this.sseEvents = [];
    this.render();
    fetch(this.base + '_via/reset', { method: 'POST' }).catch(() => {});
  }

  selectTab(id) {
    this.activeTab = id;
    if (id === 'scopes' && !this.scopeTimer) this.pollScopes();
    this.render();
  }

  renderBody() {
    const body = this.root.querySelector('.body');
    if (!body) return;
    body.innerHTML = ({
      traces: () => this.renderTraces(),
      signals: () => this.renderSignals(),
      sse: () => this.renderSse(),
      request: () => this.renderRequest(),
      scopes: () => this.renderScopes(),
      logs: () => this.renderLogs(),
    }[this.activeTab] || (() => ''))();

    if (this.activeTab === 'signals' && this.writes) {
      body.querySelectorAll('input[data-signal]').forEach((inp) =>
        inp.addEventListener('change', () => this.writeSignal(inp.dataset.context, inp.dataset.signal, inp.value)));
    }
    if (this.activeTab === 'logs') {
      body.querySelectorAll('.logfilter button').forEach((b) =>
        b.addEventListener('click', () => { this.logFilter = b.dataset.lvl; this.renderBody(); }));
    }
  }

  renderTraces() {
    if (!this.traces.length) return `<div class="empty">No traces yet. Interact with the page.</div>`;
    return this.traces.map((tr) => {
      const idById = {};
      tr.spans.forEach((s) => { idById[s.id] = s; });
      const depth = (s) => { let d = 0, p = s.parentId; while (p && idById[p]) { d++; p = idById[p].parentId; } return Math.min(d, 3); };
      const rows = tr.spans.map((s) => {
        const left = tr.totalDurationMs ? (s.offsetMs / tr.totalDurationMs) * 100 : 0;
        const width = tr.totalDurationMs ? Math.max((s.durationMs / tr.totalDurationMs) * 100, 0.5) : 100;
        const color = CATEGORY_COLORS[s.category] || CATEGORY_COLORS.app;
        const attrs = Object.entries(s.attributes || {});
        return `
          <div class="span depth${depth(s)}">
            <div class="span-row">
              <span class="span-name" title="${esc(s.name)}">${esc(s.name)}</span>
              <span class="span-track">
                <span class="span-bar" style="left:${left}%;width:${width}%;background:${color}"></span>
              </span>
              <span class="span-ms">${fmtMs(s.durationMs)}</span>
            </div>
            ${attrs.length ? `<div class="span-attrs">${attrs.map(([k, v]) =>
              `<span class="k">${esc(k)}</span>=${esc(fmtVal(v))}`).join(' · ')}</div>` : ''}
          </div>`;
      }).join('');
      return `
        <details class="trace" ${this.traces.length === 1 ? 'open' : ''}>
          <summary>
            <span class="label ${tr.status === 'error' ? 'err' : ''}">${esc(tr.label)}</span>
            <span class="dur">${fmtMs(tr.totalDurationMs)}</span>
            <span class="meta">
              <span>${tr.spanCount} spans</span>
              <span>${clock(tr.wallClockStartMs)}</span>
              <span>${esc((tr.traceId || '').slice(0, 8))}</span>
            </span>
          </summary>
          <div class="spans">${rows}</div>
        </details>`;
    }).join('');
  }

  renderSignals() {
    if (!this.signals.length) return `<div class="empty">No named signals on this context.</div>`;
    const note = this.writes
      ? `<div class="note">Editing enabled — values write back to the server.</div>`
      : `<div class="note">Read-only. Enable Config::withTracingWrites() in devMode to edit.</div>`;
    const rows = this.signals.map((s) => {
      const editable = this.writes && s.clientWritable;
      const val = editable
        ? `<input data-signal="${esc(s.id)}" data-context="${esc(this.contextId)}" value="${esc(fmtVal(s.value))}">`
        : esc(fmtVal(s.value));
      return `
        <tr>
          <td class="k">${esc(s.name)}</td>
          <td class="scope">${esc(s.scope)} ${s.clientWritable ? '<span class="tag rw">rw</span>' : '<span class="tag">ro</span>'}</td>
          <td>${val}</td>
        </tr>`;
    }).join('');
    return `${note}<table class="kv"><tbody>${rows}</tbody></table>`;
  }

  renderSse() {
    if (!this.sseEvents.length) return `<div class="empty">No SSE events captured yet.</div>`;
    return `<div class="sse">${this.sseEvents.map((e) => {
      const entries = Object.entries(e.args || {});
      const isErr = e.type === 'error';

      // Lifecycle markers (started/finished) carry no payload — render muted and
      // non-expandable so the content-bearing patch events stand out.
      if (!entries.length) {
        return `
          <div class="sse-row meta ${isErr ? 'err' : ''}">
            <span class="t">${clock(e.t)}</span>
            <span class="ty">${esc(sseLabel(e.type))}</span>
          </div>`;
      }

      const size = sseSize(e);
      const isPatch = e.type.startsWith('datastar-patch');
      return `
        <details class="sse-row ${isErr ? 'err' : ''}" ${isPatch ? 'open' : ''}>
          <summary>
            <span class="t">${clock(e.t)}</span>
            <span class="ty">${esc(sseLabel(e.type))}</span>
            ${size ? `<span class="sz">${size}B</span>` : ''}
            <span class="d">${esc(ssePreview(e))}</span>
          </summary>
          <div class="sse-detail">${entries.map(([k, v]) =>
            `<div class="sse-kv"><span class="k">${esc(k)}</span><pre>${esc(v)}</pre></div>`).join('')}</div>
        </details>`;
    }).join('')}</div>`;
  }

  renderRequest() {
    const lastReq = this.traces.find((t) => t.spans?.[0]?.category === 'request');
    const attrs = lastReq ? (lastReq.spans[0].attributes || {}) : {};
    const rows = [
      ['route', this.route],
      ['context', this.contextId],
      ...Object.entries(attrs),
      ['last duration', lastReq ? fmtMs(lastReq.totalDurationMs) : '—'],
    ];
    return `<table class="kv"><tbody>${rows.map(([k, v]) =>
      `<tr><td class="k">${esc(k)}</td><td>${esc(fmtVal(v))}</td></tr>`).join('')}</tbody></table>`;
  }

  renderScopes() {
    if (!this.scopes) return `<div class="empty">Loading scope snapshot…</div>`;
    const sc = this.scopes;
    const summary = `<table class="kv"><tbody>
      <tr><td class="k">contexts</td><td>${sc.totalContexts}</td></tr>
      <tr><td class="k">active SSE</td><td>${sc.activeSse}</td></tr>
      <tr><td class="k">clients</td><td>${sc.clients}</td></tr>
    </tbody></table>`;
    if (!sc.scopes.length) return summary + `<div class="empty">No registered scopes.</div>`;
    const rows = sc.scopes.map((s) =>
      `<tr><td class="k">${esc(s.scope)}</td><td>${s.contextCount} ctx</td></tr>`).join('');
    return summary + `<table class="kv"><tbody>${rows}</tbody></table>`;
  }

  renderLogs() {
    const order = { debug: 0, info: 1, warn: 2, error: 3, fatal: 3 };
    const min = order[this.logFilter] ?? 1;
    const chips = ['debug', 'info', 'warn', 'error'].map((lv) =>
      `<button class="${this.logFilter === lv ? 'active' : ''}" data-lvl="${lv}">${lv}</button>`).join('');
    const bar = `<div class="logfilter">${chips}</div>`;

    const filtered = this.logs.filter((l) => (order[l.level] ?? 1) >= min);
    if (!filtered.length) return bar + `<div class="empty">No log records at this level. 🎉</div>`;

    const rows = filtered.map((l) => `
      <div class="row ${esc(l.level)}">
        <span class="t">${clock(l.t)}</span>
        <span class="lvl">${esc(l.level)}</span>
        <span class="d">${esc(l.message || '')}${l.source === 'client' ? ' <span class="src">client</span>' : ''}</span>
      </div>`).join('');
    return bar + `<div class="log">${rows}</div>`;
  }

  async writeSignal(contextId, signalId, raw) {
    let value = raw;
    try { value = JSON.parse(raw); } catch (_) { /* keep as string */ }
    try {
      const r = await fetch(this.base + '_via/signal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ contextId, signalId, value }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({}));
        this.logs.unshift({ t: Date.now(), level: 'error', message: `signal write ${r.status}: ${err.error || ''}`, source: 'devbar' });
        this.renderPill();
      } else {
        const sig = this.signals.find((s) => s.id === signalId);
        if (sig) sig.value = value;
      }
    } catch (e) {
      this.logs.unshift({ t: Date.now(), level: 'error', message: 'signal write failed: ' + String(e), source: 'devbar' });
    }
  }
}

// ── helpers ───────────────────────────────────────────────────────────────────

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function safeJSON(s, fallback) { try { return JSON.parse(s); } catch (_) { return fallback; } }
function fmtMs(ms) { if (ms == null) return '—'; return ms >= 100 ? ms.toFixed(0) + 'ms' : ms.toFixed(2) + 'ms'; }
function fmtVal(v) {
  if (v === null || v === undefined) return String(v);
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}
function clock(ms) {
  const d = new Date(ms);
  const p = (n, l = 2) => String(n).padStart(l, '0');
  return `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}.${p(d.getMilliseconds(), 3)}`;
}
// Datastar's argsRaw values are already strings (joined SSE data lines), but
// lifecycle events ({status}, {message}) carry small objects. Stringify and cap
// each value so a large element fragment can't bloat memory.
function normalizeArgs(raw) {
  if (raw == null) return {};
  if (typeof raw !== 'object') return { value: String(raw) };
  const out = {};
  for (const [k, v] of Object.entries(raw)) {
    let s = typeof v === 'string' ? v : (() => { try { return JSON.stringify(v); } catch (_) { return String(v); } })();
    if (s != null && s.length > 20000) s = s.slice(0, 20000) + `…(${s.length}B)`;
    out[k] = s == null ? '' : s;
  }
  return out;
}
// Short tab label: "datastar-patch-elements" → "patch-elements".
function sseLabel(type) { return type.replace(/^datastar-/, ''); }
// Total byte size of the payload values (the wire size that matters).
function sseSize(e) {
  return Object.values(e.args || {}).reduce((n, v) => n + (v ? v.length : 0), 0) || 0;
}
// One-line preview: the main content (elements/signals/value), whitespace-collapsed.
function ssePreview(e) {
  const a = e.args || {};
  const main = a.elements ?? a.signals ?? a.value ?? Object.values(a)[0] ?? '';
  return String(main).replace(/\s+/g, ' ').trim().slice(0, 160);
}
customElements.define('via-dev-bar', ViaDevBar);
