/**
 * `users` field — a reusable, type-ahead, chip-style multiselect of users
 * (admin-next custom field).
 *
 * Drop it into any blueprint to pick accounts filtered by access or group:
 *
 *   header.permissions.authors:
 *     type: users
 *     access: api.pages.write      # min permission (string or list, any-of)
 *     # groups: [editors]          # group membership (string or list, any-of)
 *
 * The candidate list is resolved server-side from that config and handed in as
 * `field.options` [{ value, label }] (value = username). The component is
 * config-agnostic — it just renders whatever options it's given.
 *
 * Value shape — a plain list of usernames, e.g. ["admin", "claire.danes"] —
 * so it's a drop-in for the username text-arrays it replaces.
 */

const TAG = window.__GRAV_FIELD_TAG;

class UsersField extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = null;
        this._selected = [];     // string[]
        this._lastEmitted = null;
        this._open = false;
        this._filter = '';
        this._onDocDown = this._onDocDown.bind(this);
    }

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }

    set value(v) {
        const serialized = JSON.stringify(v ?? []);
        if (serialized === this._lastEmitted) return;
        this._value = v;
        this._selected = Array.isArray(v) ? v.map(String) : [];
        this._render();
    }
    get value() { return this._value; }

    connectedCallback() {
        document.addEventListener('mousedown', this._onDocDown, true);
        this._render();
    }

    disconnectedCallback() {
        document.removeEventListener('mousedown', this._onDocDown, true);
    }

    // ─── State ──────────────────────────────────────────────────────────

    _options() {
        const opts = this._field?.options;
        return Array.isArray(opts) ? opts : [];
    }

    _label(value) {
        const o = this._options().find((x) => String(x.value) === String(value));
        return o ? String(o.label ?? o.value) : String(value);
    }

    _commit() {
        const out = [...this._selected];
        this._value = out;
        this._lastEmitted = JSON.stringify(out);
        this.dispatchEvent(new CustomEvent('change', { detail: out, bubbles: true }));
    }

    _toggle(username) {
        const i = this._selected.indexOf(username);
        if (i >= 0) this._selected.splice(i, 1);
        else this._selected.push(username);
        this._commit();
        this._renderControl();
        this._renderResults();
    }

    _remove(username) {
        const i = this._selected.indexOf(username);
        if (i < 0) return;
        this._selected.splice(i, 1);
        this._commit();
        this._renderControl();
        if (this._open) this._renderResults();
    }

    // ─── Render ─────────────────────────────────────────────────────────

    _render() {
        if (!this.shadowRoot || !this.isConnected) return;
        this.shadowRoot.innerHTML = `<style>${STYLE}</style>
            <div class="wrap">
                <div class="control"></div>
                ${this._open ? `
                    <div class="popover">
                        <div class="search">${SEARCH}
                            <input type="text" placeholder="Filter users…" />
                            <button type="button" class="clear" hidden>${CLOSE}</button>
                        </div>
                        <div class="results"></div>
                    </div>` : ''}
            </div>`;

        this._controlEl = this.shadowRoot.querySelector('.control');
        this._renderControl();

        if (this._open) {
            this._popoverEl = this.shadowRoot.querySelector('.popover');
            this._resultsEl = this.shadowRoot.querySelector('.results');
            this._searchInput = this.shadowRoot.querySelector('.search input');
            const clearBtn = this.shadowRoot.querySelector('.clear');
            this._searchInput.value = this._filter;
            clearBtn.hidden = !this._filter;
            this._searchInput.addEventListener('input', () => {
                this._filter = this._searchInput.value;
                clearBtn.hidden = !this._filter;
                this._renderResults();
            });
            this._searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') this._close();
            });
            clearBtn.addEventListener('click', () => {
                this._filter = '';
                this._searchInput.value = '';
                clearBtn.hidden = true;
                this._renderResults();
                this._searchInput.focus();
            });
            this._renderResults();
            requestAnimationFrame(() => this._searchInput?.focus());
        }
    }

    _renderControl() {
        if (!this._controlEl) return;
        const chips = this._selected.map((u) => `
            <span class="chip">
                <span class="chip-lbl">${esc(this._label(u))}</span>
                <button type="button" class="chip-x" data-user="${esc(u)}" title="Remove">${CLOSE}</button>
            </span>`).join('');
        this._controlEl.innerHTML = `
            ${chips || `<span class="ph">Select authors…</span>`}
            <span class="open-chevron">${UPDOWN}</span>`;

        this._controlEl.addEventListener('click', (e) => {
            if (e.target.closest('.chip-x')) return;
            if (!this._open) this._open = true, this._render();
        });
        this._controlEl.querySelectorAll('.chip-x').forEach((b) =>
            b.addEventListener('click', (e) => { e.stopPropagation(); this._remove(b.dataset.user); }));
    }

    _renderResults() {
        if (!this._resultsEl) return;
        const q = this._filter.trim().toLowerCase();
        const matches = this._options().filter((o) =>
            !q || String(o.label).toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q));

        this._resultsEl.innerHTML = matches.length
            ? matches.map((o) => {
                const v = String(o.value);
                const on = this._selected.includes(v);
                return `
                    <div class="node ${on ? 'sel' : ''}" data-user="${esc(v)}">
                        <span class="box">${on ? CHECK : ''}</span>
                        <span class="node-lbl">${esc(String(o.label ?? v))}</span>
                    </div>`;
            }).join('')
            : `<div class="empty-msg">No matching users</div>`;

        this._resultsEl.querySelectorAll('[data-user]').forEach((el) =>
            el.addEventListener('click', () => this._toggle(el.dataset.user)));
    }

    _close() {
        if (!this._open) return;
        this._open = false;
        this._filter = '';
        this._render();
    }

    _onDocDown(e) {
        if (!this._open) return;
        const path = e.composedPath();
        if ((this._popoverEl && path.includes(this._popoverEl)) ||
            (this._controlEl && path.includes(this._controlEl))) return;
        this._close();
    }
}

// ─── Inline SVG icons ───────────────────────────────────────────────────
const UPDOWN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>';
const SEARCH = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
const CLOSE = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
const CHECK = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const STYLE = `
    :host { display: block; font-family: inherit; }
    .wrap { position: relative; }
    .control {
        display: flex; flex-wrap: wrap; align-items: center; gap: 6px;
        min-height: 40px; padding: 5px 34px 5px 8px; position: relative;
        border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
        background: var(--muted, #f8fafc); color: var(--foreground, #0f172a);
        cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .control:hover { background: var(--accent, #f1f5f9); }
    .ph { color: var(--muted-foreground, #94a3b8); font-size: 14px; padding-inline-start: 4px; }
    .open-chevron { position: absolute; inset-inline-end: 10px; top: 50%; transform: translateY(-50%); color: var(--muted-foreground, #64748b); display: flex; pointer-events: none; }
    .chip {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 3px 4px 3px 9px; border-radius: 6px; font-size: 13px; font-weight: 500;
        background: color-mix(in srgb, var(--primary, #6366f1) 16%, transparent);
        color: var(--primary, #6366f1);
    }
    .chip-x { display: inline-flex; border: 0; background: transparent; color: inherit; cursor: pointer; padding: 1px; border-radius: 4px; opacity: .8; }
    .chip-x:hover { opacity: 1; background: color-mix(in srgb, var(--primary, #6366f1) 25%, transparent); }

    .popover {
        position: absolute; top: calc(100% + 4px); inset-inline-start: 0;
        width: max(100%, 300px); max-width: 92vw; z-index: 60;
        background: var(--popover, var(--background, #fff)); color: var(--foreground, #0f172a);
        border: 1px solid var(--border, #e2e8f0); border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,.18); overflow: hidden;
    }
    .search { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border, #e2e8f0); color: var(--muted-foreground, #64748b); }
    .search input { flex: 1; min-width: 0; border: 0; background: transparent; color: var(--foreground, #0f172a); font-size: 14px; font-family: inherit; outline: none; }
    .search .clear { border: 0; background: transparent; color: var(--muted-foreground, #64748b); cursor: pointer; display: inline-flex; padding: 2px; }
    .search .clear:hover { color: var(--foreground, #0f172a); }
    .results { max-height: 288px; overflow-y: auto; padding: 4px; }

    .node { display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 8px; cursor: pointer; color: var(--foreground, #0f172a); }
    .node:hover { background: var(--accent, #f1f5f9); }
    .node.sel { color: var(--primary, #6366f1); }
    .box { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; flex: none; border-radius: 5px; border: 1.5px solid var(--border, #cbd5e1); background: var(--background, #fff); }
    .node.sel .box { background: var(--primary, #6366f1); border-color: var(--primary, #6366f1); color: #fff; }
    .node-lbl { font-size: 14px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .empty-msg { padding: 14px; text-align: center; font-size: 13px; color: var(--muted-foreground, #64748b); }
`;

customElements.define(TAG, UsersField);
