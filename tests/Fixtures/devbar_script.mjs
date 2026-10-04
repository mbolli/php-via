// Fixture for DevBarScriptTest: runs public/devbar.js against a stub DOM in node and prints what it saw as JSON.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const listeners = {};
const streams = [];

class EventSourceStub {
  constructor(url) { this.url = url; this.closed = false; streams.push(this); }
  addEventListener() {}
  close() { this.closed = true; }
}

const stubElement = () => ({ innerHTML: '', addEventListener() {}, querySelector: () => stubElement(), querySelectorAll: () => [] });

class CSSStyleSheetStub {
  replaceSync(text) { this.text = text; }
}

class HTMLElementStub {
  getAttribute() { return '{}'; }
  attachShadow() { this.shadowRoot = { ...stubElement(), adoptedStyleSheets: [] }; return this.shadowRoot; }
}

const elements = {};
const window = {
  addEventListener(type, fn) { (listeners[type] ??= []).push(fn); },
  removeEventListener(type, fn) { listeners[type] = (listeners[type] ?? []).filter((f) => f !== fn); },
};

vm.runInNewContext(readFileSync(new URL('../../public/devbar.js', import.meta.url), 'utf8'), {
  window,
  document: { addEventListener() {} },
  HTMLElement: HTMLElementStub,
  EventSource: EventSourceStub,
  CSSStyleSheet: CSSStyleSheetStub,
  customElements: { define: (name, cls) => { elements[name] = cls; } },
  fetch: async () => ({ ok: true, json: async () => ({}) }),
  setInterval: () => 1,
  clearInterval() {},
  console,
});

const fire = (type, event) => (listeners[type] ?? []).forEach((fn) => fn(event));
const bar = new elements['via-dev-bar']();
bar.connectedCallback();

const out = {
  streamsAtStart: streams.length,
  // A nonce CSP without 'unsafe-inline' refuses a <style> element and style attributes, not a constructed sheet.
  stylesAdopted: bar.shadowRoot.adoptedStyleSheets.length === 1 && bar.shadowRoot.adoptedStyleSheets[0].text.includes(':host')
    && !bar.shadowRoot.innerHTML.includes('<style'),
};
fire('pagehide', { persisted: true });
out.closedOnHide = streams[0].closed;
fire('pageshow', { persisted: true });
out.streamsAfterReturn = streams.length;
out.newStreamOpen = streams.length === 2 && !streams[1].closed;
fire('pageshow', { persisted: false });
out.streamsAfterFirstShow = streams.length;

bar.scopes = { worker: 3, scopes: [], totalContexts: 0, activeSse: 0, clients: 0 };
out.scopesNameWorker = bar.renderScopes().includes('worker 3');

bar.traces = [{ spans: [{ id: 1, name: 'render', category: 'app', offsetMs: 0, durationMs: 2, attributes: {} }], totalDurationMs: 2, label: 'GET /', status: 'ok', spanCount: 1, wallClockStartMs: 0, traceId: 'abc' }];
out.tracesWithoutStyleAttribute = !bar.renderTraces().includes('style=');

bar.disconnectedCallback();
out.pageListenersLeft = (listeners.pagehide ?? []).length + (listeners.pageshow ?? []).length;

console.log(JSON.stringify(out));
