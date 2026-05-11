/**
 * <docs-toc> — internal navigation web component for php-via docs.
 *
 * Collects h2 + h3 headings from a content container, renders a sticky
 * "On this page" sidebar (desktop) and a FAB + progress bar (mobile).
 *
 * Attributes:
 *   data-content  — CSS selector for the content container  (default: "article")
 *   data-title    — TOC heading label                       (default: "On this page")
 *   data-intro    — Label for the top-of-page intro link    (default: "Introduction")
 */
class DocsToc extends HTMLElement {
    #items = [];          // { id, text, level, element }
    #activeId = null;
    #progress = 0;
    #isOpen = false;
    #observer = null;
    #contentEl = null;
    #progressBarEl = null;
    #fabCounterEl = null;
    #menuEl = null;
    #backdropEl = null;
    #sidebarEl = null;    // the outer <aside class="docs-toc-sidebar">
    #bound = {
        scroll: null,
        keydown: null,
    };

    #mobileEl = null;   // cached reference to the .docs-toc-mobile div inside this element

    /* ── lifecycle ───────────────────────────────────────── */

    connectedCallback() {
        this.#bound.scroll = this.#onScroll.bind(this);
        this.#bound.keydown = this.#onKeydown.bind(this);

        // Defer until DOM is painted so heading positions are correct
        requestAnimationFrame(() => {
            const selector = this.dataset.content || 'article';
            this.#contentEl = document.querySelector(selector);

            if (!this.#contentEl) {
                console.warn(`docs-toc: content container "${selector}" not found`);
                return;
            }

            this.#collectHeadings();

            if (this.#items.length === 0) return;

            this.#renderAll();
            this.#setupObserver();
            this.#setupProgress();
            document.addEventListener('keydown', this.#bound.keydown);

            // Trigger entrance animation on the parent sidebar
            this.#sidebarEl = this.closest('.docs-toc-sidebar');
            if (this.#sidebarEl) {
                // Small delay so the transition actually plays
                requestAnimationFrame(() => this.#sidebarEl.classList.add('is-ready'));
            }
        });
    }

    disconnectedCallback() {
        this.#observer?.disconnect();
        window.removeEventListener('scroll', this.#bound.scroll);
        document.removeEventListener('keydown', this.#bound.keydown);
    }

    /* ── heading collection ──────────────────────────────── */

    #collectHeadings() {
        const headings = this.#contentEl.querySelectorAll('h2, h3');
        this.#items = [];

        headings.forEach((el, index) => {
            if (!el.id) {
                el.id = this.#slugify(el.textContent || `section-${index}`);
            }
            this.#items.push({
                id: el.id,
                text: el.textContent?.trim() || '',
                level: el.tagName === 'H3' ? 3 : 2,
                element: el,
            });
        });
    }

    #slugify(text) {
        return text
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    }

    /* ── rendering ───────────────────────────────────────── */

    #renderAll() {
        const title = this.dataset.title || 'On this page';
        const intro = this.dataset.intro || 'Introduction';

        this.innerHTML = `
            ${this.#renderDesktop(title, intro)}
            ${this.#renderMobile(title, intro)}
        `;

        // Cache the mobile wrapper and hot-path elements
        this.#mobileEl    = this.querySelector('.docs-toc-mobile');
        this.#progressBarEl = this.querySelector('.docs-toc-progress__bar');
        this.#fabCounterEl  = this.querySelector('.docs-toc-fab__counter');
        this.#menuEl        = this.querySelector('.docs-toc-mobile__menu');
        this.#backdropEl    = this.querySelector('.docs-toc-mobile__backdrop');

        this.#attachEvents();
    }

    #renderDesktop(title, intro) {
        const items = this.#items.map(item => `
            <li class="docs-toc__item${item.level === 3 ? ' docs-toc__item--h3' : ''}" data-toc-id="${item.id}">
                <a href="#${item.id}" class="docs-toc__link">${item.text}</a>
            </li>
        `).join('');

        return `
            <nav class="docs-toc" aria-label="${title}">
                <p class="docs-toc__heading">${title}</p>
                <ul class="docs-toc__list" role="list">
                    <li class="docs-toc__item is-intro" data-toc-id="__intro">
                        <a href="#" class="docs-toc__link">${intro}</a>
                    </li>
                    ${items}
                </ul>
            </nav>
        `;
    }

    #renderMobile(title, intro) {
        const totalSections = this.#items.length + 1;
        const listItems = this.#items.map((item, i) => `
            <li class="docs-toc-mobile__item${item.level === 3 ? ' docs-toc-mobile__item--h3' : ''}" data-toc-id="${item.id}">
                <a href="#${item.id}" class="docs-toc-mobile__link">
                    <span class="docs-toc-mobile__num">${i + 2}</span>
                    <span>${item.text}</span>
                </a>
            </li>
        `).join('');

        return `
            <div class="docs-toc-mobile" aria-hidden="true" data-ignore-morph>
                <!-- Progress bar -->
                <div class="docs-toc-progress" role="progressbar" aria-label="Reading progress" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                    <div class="docs-toc-progress__bar"></div>
                </div>

                <!-- Floating action button -->
                <button class="docs-toc-fab" aria-label="${title}" aria-expanded="false" aria-haspopup="dialog">
                    <span class="docs-toc-fab__counter">1/${totalSections}</span>
                </button>

                <!-- Overlay backdrop -->
                <div class="docs-toc-mobile__backdrop" aria-hidden="true"></div>

                <!-- Slide-up menu panel -->
                <div class="docs-toc-mobile__menu" role="dialog" aria-modal="true" aria-label="${title}">
                    <div class="docs-toc-mobile__menu-header">
                        <span class="docs-toc-mobile__menu-title">${title}</span>
                        <button class="docs-toc-mobile__close" aria-label="Close table of contents">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                    <ul class="docs-toc-mobile__list" role="list">
                        <li class="docs-toc-mobile__item is-intro" data-toc-id="__intro">
                            <a href="#" class="docs-toc-mobile__link">
                                <span class="docs-toc-mobile__num">1</span>
                                <span>${intro}</span>
                            </a>
                        </li>
                        ${listItems}
                    </ul>
                </div>
            </div>
        `;
    }

    /* ── targeted DOM updaters (no re-render) ────────────── */

    #updateActive(id) {
        if (id === this.#activeId) return;
        this.#activeId = id;

        const key = id === null ? '__intro' : id;

        // Desktop
        this.querySelectorAll('.docs-toc__item').forEach(li => {
            li.classList.toggle('is-active', li.dataset.tocId === key);
        });

        // Mobile list
        this.querySelectorAll('.docs-toc-mobile__item').forEach(li => {
            li.classList.toggle('is-active', li.dataset.tocId === key);
        });

        // FAB counter
        const idx = id === null ? 0 : this.#items.findIndex(i => i.id === id);
        const activeIdx = idx === -1 ? 0 : idx + 1; // +1 because intro is 0
        const total = this.#items.length + 1;
        if (this.#fabCounterEl) {
            this.#fabCounterEl.textContent = `${activeIdx + 1}/${total}`;
        }
    }

    #updateProgress(pct) {
        if (!this.#progressBarEl) return;
        this.#progressBarEl.style.width = `${pct}%`;
        const progressWrap = this.querySelector('.docs-toc-progress');
        if (progressWrap) progressWrap.setAttribute('aria-valuenow', Math.round(pct));
    }

    #setMenuOpen(open) {
        this.#isOpen = open;
        const fab = this.querySelector('.docs-toc-fab');
        const mobile = this.querySelector('.docs-toc-mobile');

        if (open) {
            this.#menuEl?.classList.add('is-open');
            this.#backdropEl?.classList.add('is-open');
            fab?.setAttribute('aria-expanded', 'true');
            mobile?.removeAttribute('aria-hidden');
            document.body.style.overflow = 'hidden';
        } else {
            this.#menuEl?.classList.remove('is-open');
            this.#backdropEl?.classList.remove('is-open');
            fab?.setAttribute('aria-expanded', 'false');
            mobile?.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    }

    /* ── event wiring ────────────────────────────────────── */

    #attachEvents() {
        // Desktop TOC links
        this.querySelector('.docs-toc__list')?.addEventListener('click', e => {
            const a = e.target.closest('a');
            if (!a) return;
            e.preventDefault();
            const li = a.closest('[data-toc-id]');
            if (li?.dataset.tocId === '__intro') {
                this.#scrollToTop();
            } else {
                this.#scrollToId(a.getAttribute('href')?.slice(1));
            }
        });

        // Mobile list links
        this.#menuEl?.addEventListener('click', e => {
            const a = e.target.closest('a');
            if (!a) return;
            e.preventDefault();
            this.#setMenuOpen(false);
            const li = a.closest('[data-toc-id]');
            if (li?.dataset.tocId === '__intro') {
                this.#scrollToTop();
            } else {
                this.#scrollToId(a.getAttribute('href')?.slice(1));
            }
        });

        // FAB toggle
        this.querySelector('.docs-toc-fab')?.addEventListener('click', () => {
            this.#setMenuOpen(!this.#isOpen);
        });

        // Backdrop close
        this.#backdropEl?.addEventListener('click', () => this.#setMenuOpen(false));

        // Close button
        this.querySelector('.docs-toc-mobile__close')?.addEventListener('click', () => {
            this.#setMenuOpen(false);
        });
    }

    #onKeydown(e) {
        if (e.key === 'Escape' && this.#isOpen) {
            this.#setMenuOpen(false);
        }
    }

    #scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
        history.replaceState(null, '', window.location.pathname);
        this.#updateActive(null);
    }

    #scrollToId(id) {
        if (!id) return;
        const el = document.getElementById(id);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            history.replaceState(null, '', `#${id}`);
        }
    }

    /* ── IntersectionObserver ────────────────────────────── */

    #setupObserver() {
        this.#observer = new IntersectionObserver(entries => {
            const visible = entries.filter(e => e.isIntersecting);

            if (visible.length > 0) {
                const topmost = visible.reduce((prev, curr) =>
                    prev.boundingClientRect.top < curr.boundingClientRect.top ? prev : curr
                );
                this.#updateActive(topmost.target.id);
            } else {
                // Find last heading that has scrolled above the threshold
                const threshold = window.innerHeight * 0.10;
                let lastAbove = null;
                for (const item of this.#items) {
                    if (item.element.getBoundingClientRect().top < threshold) {
                        lastAbove = item;
                    }
                }
                this.#updateActive(lastAbove ? lastAbove.id : null);
            }
        }, {
            rootMargin: '-10% 0px -60% 0px',
            threshold: 0,
        });

        for (const item of this.#items) {
            this.#observer.observe(item.element);
        }
    }

    /* ── scroll progress ─────────────────────────────────── */

    #setupProgress() {
        window.addEventListener('scroll', this.#bound.scroll, { passive: true });
        this.#onScroll();
    }

    #onScroll() {
        if (!this.#contentEl) return;

        const rect = this.#contentEl.getBoundingClientRect();
        const contentTop = rect.top + window.scrollY;
        const contentHeight = rect.height;
        const scrollY = window.scrollY;
        const vpHeight = window.innerHeight;

        const start = contentTop;
        const end = contentTop + contentHeight - vpHeight;

        let pct = 0;
        if (scrollY <= start)  pct = 0;
        else if (scrollY >= end) pct = 100;
        else pct = ((scrollY - start) / (end - start)) * 100;

        this.#updateProgress(pct);
    }
}

customElements.define('docs-toc', DocsToc);
