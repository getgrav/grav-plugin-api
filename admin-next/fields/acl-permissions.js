/**
 * Page Groups ACL picker (admin-next custom field).
 *
 * Mirrors admin-classic's `acl_picker` with `data_type: permissions`: a list
 * of rows, each pairing a group (or special ACL target) with per-action
 * Create / Read / Update / Delete states, each tri-state:
 *   unset → allow → deny → unset.
 *
 * Value shape (what grav-core reads from `header.permissions.groups`):
 *   { "Registered": "cru", "limited": "-c-r-u-d" }
 * Each value is a compact ACL string: letters c/r/u/d (and any others such as
 * `p`); a leading "-" denies the letters that follow it, "+" (or no sign)
 * allows them. Letters absent from the string are unset. Non-CRUD letters in
 * an existing value (e.g. publish `p`) are preserved on round-trip.
 *
 * The group dropdown is a type-ahead popover (searchable list), modelled on
 * admin-next's page-parent picker. Options are baked into `field.options`
 * server-side as [{ value, label }].
 */

const TAG = window.__GRAV_FIELD_TAG;

const CRUD = ['c', 'r', 'u', 'd'];
const CRUD_LABEL = { c: 'C', r: 'R', u: 'U', d: 'D' };

class AclPermissionsField extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = null;
        this._rows = null;       // [{ key, states: {letter:'allow'|'deny'} }]
        this._lastEmitted = null;
        this._openRow = -1;
        this._filter = '';
        this._onDocDown = this._onDocDown.bind(this);
    }

    set field(v) { this._field = v; this._render(); }
    get field() { return this._field; }

    set value(v) {
        const serialized = JSON.stringify(v ?? {});
        if (serialized === this._lastEmitted) return;
        this._value = v;
        this._rows = this._rowsFromValue(v);
        this._render();
    }
    get value() { return this._value; }

    connectedCallback() {
        if (!this._rows) this._rows = this._rowsFromValue(this._value);
        document.addEventListener('mousedown', this._onDocDown, true);
        this._render();
    }

    disconnectedCallback() {
        document.removeEventListener('mousedown', this._onDocDown, true);
    }

    // ─── State ──────────────────────────────────────────────────────────

    _rowsFromValue(v) {
        const rows = [];
        if (v && typeof v === 'object' && !Array.isArray(v)) {
            for (const [key, raw] of Object.entries(v)) {
                rows.push({ key, states: parseAcl(raw) });
            }
        }
        // Show a single blank starter row only when nothing is set yet, so the
        // controls are visible. Once there's an entry, the "+" button adds the
        // next blank row — we don't auto-trail one.
        if (rows.length === 0) rows.push({ key: '', states: {} });
        return rows;
    }

    _options() {
        const opts = this._field?.options;
        return Array.isArray(opts) ? opts : [];
    }

    _optionLabel(value) {
        const o = this._options().find((x) => String(x.value) === String(value));
        return o ? String(o.label ?? o.value) : '';
    }

    _commit() {
        const out = {};
        for (const row of this._rows) {
            if (row.key) out[row.key] = serializeAcl(row.states);
        }
        this._value = out;
        this._lastEmitted = JSON.stringify(out);
        this.dispatchEvent(new CustomEvent('change', { detail: out, bubbles: true }));
    }

    _ensureTrailingBlank() {
        const last = this._rows[this._rows.length - 1];
        if (!last || last.key !== '') this._rows.push({ key: '', states: {} });
    }

    // ─── Render ─────────────────────────────────────────────────────────

    _render() {
        if (!this.shadowRoot || !this.isConnected) return;
        if (!this._rows) this._rows = this._rowsFromValue(this._value);

        const crudHtml = (states) => CRUD.map((letter) => {
            const st = states[letter] || 'unset';
            const icon = st === 'allow' ? LOCK_OPEN : LOCK;
            return `<button type="button" class="crud ${st}" data-letter="${letter}" title="${CRUD_LABEL[letter]}">${icon}<span>${CRUD_LABEL[letter]}</span></button>`;
        }).join('');

        const rowsHtml = this._rows.map((row, i) => `
            <div class="row" data-i="${i}">
                <button type="button" class="icon-btn del" title="Remove" data-act="del">${TRASH}</button>
                <div class="select-wrap">
                    <button type="button" class="combo-trigger ${row.key ? '' : 'empty'}" data-act="open">
                        ${row.key
                            ? `<span class="lbl">${esc(this._optionLabel(row.key) || row.key)}</span>`
                            : `<span class="ph">Select group…</span>`}
                        ${UPDOWN}
                    </button>
                </div>
                <div class="crud-group" role="group">${crudHtml(row.states)}</div>
                <button type="button" class="icon-btn add" title="Add" data-act="add">${PLUS}</button>
            </div>
        `).join('');

        this.shadowRoot.innerHTML = `<style>${STYLE}</style><div class="wrap">${rowsHtml}</div>`;

        this.shadowRoot.querySelectorAll('.row').forEach((el) => {
            const i = Number(el.dataset.i);
            el.querySelector('[data-act="open"]')?.addEventListener('click', () => this._toggleOpen(i));
            el.querySelector('[data-act="del"]')?.addEventListener('click', () => this._onDelete(i));
            el.querySelector('[data-act="add"]')?.addEventListener('click', () => this._onAdd());
            el.querySelectorAll('.crud').forEach((btn) =>
                btn.addEventListener('click', () => this._onCycle(i, btn.dataset.letter)));
        });

        if (this._openRow >= 0 && this._openRow < this._rows.length) {
            this._mountPopover(this._openRow);
        }
    }

    // ─── Type-ahead popover ─────────────────────────────────────────────

    _toggleOpen(i) {
        this._openRow = this._openRow === i ? -1 : i;
        this._filter = '';
        this._render();
    }

    _mountPopover(i) {
        const wrap = this.shadowRoot.querySelector(`.row[data-i="${i}"] .select-wrap`);
        if (!wrap) return;

        const pop = document.createElement('div');
        pop.className = 'popover';
        pop.innerHTML = `
            <div class="search">${SEARCH}
                <input type="text" placeholder="Filter groups…" />
                <button type="button" class="clear" hidden>${CLOSE}</button>
            </div>
            <div class="results"></div>`;
        wrap.appendChild(pop);
        this._popoverEl = pop;
        this._resultsEl = pop.querySelector('.results');
        this._searchInput = pop.querySelector('input');
        const clearBtn = pop.querySelector('.clear');

        this._searchInput.addEventListener('input', () => {
            this._filter = this._searchInput.value;
            clearBtn.hidden = !this._filter;
            this._renderResults(i);
        });
        this._searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { this._openRow = -1; this._render(); }
        });
        clearBtn.addEventListener('click', () => {
            this._filter = '';
            this._searchInput.value = '';
            clearBtn.hidden = true;
            this._renderResults(i);
            this._searchInput.focus();
        });

        this._renderResults(i);
        requestAnimationFrame(() => this._searchInput?.focus());
    }

    _renderResults(rowIndex) {
        if (!this._resultsEl) return;
        const selected = this._rows[rowIndex]?.key || '';
        const q = this._filter.trim().toLowerCase();
        const matches = this._options().filter((o) =>
            !q || String(o.label).toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q));

        this._resultsEl.innerHTML = matches.length
            ? matches.map((o) => {
                const v = String(o.value);
                const lbl = String(o.label ?? v);
                const sel = v === selected;
                return `
                    <div class="node ${sel ? 'sel' : ''}" data-value="${esc(v)}">
                        <span class="node-lbl"><span class="lbl">${esc(lbl)}</span></span>
                        ${sel ? `<span class="tick">${CHECK}</span>` : ''}
                    </div>`;
            }).join('')
            : `<div class="empty-msg">No matching groups</div>`;

        this._resultsEl.querySelectorAll('[data-value]').forEach((el) =>
            el.addEventListener('click', () => this._select(rowIndex, el.dataset.value)));
    }

    _select(i, value) {
        if (!this._rows[i]) return;
        this._rows[i].key = value;
        this._openRow = -1;
        this._commit();
        this._render();
    }

    _onDocDown(e) {
        if (this._openRow < 0) return;
        if (this._popoverEl && e.composedPath().includes(this._popoverEl)) return;
        const trigger = this.shadowRoot.querySelector(`.row[data-i="${this._openRow}"] [data-act="open"]`);
        if (trigger && e.composedPath().includes(trigger)) return;
        this._openRow = -1;
        this._render();
    }

    // ─── Row actions ────────────────────────────────────────────────────

    _onCycle(i, letter) {
        const row = this._rows[i];
        if (!row) return;
        const current = row.states[letter] || 'unset';
        const next = current === 'unset' ? 'allow' : current === 'allow' ? 'deny' : 'unset';
        if (next === 'unset') delete row.states[letter];
        else row.states[letter] = next;
        this._commit();
        this._render();
    }

    _onDelete(i) {
        this._rows.splice(i, 1);
        // Keep one blank starter row when the list is emptied entirely.
        if (!this._rows.length) this._rows.push({ key: '', states: {} });
        if (this._openRow === i) this._openRow = -1;
        this._commit();
        this._render();
    }

    _onAdd() {
        // Add a blank row to type into — but never stack multiple blanks.
        this._ensureTrailingBlank();
        this._render();
    }
}

// ─── Compact ACL string <-> states map ──────────────────────────────────

function parseAcl(raw) {
    const states = {};
    if (raw && typeof raw === 'object' && !Array.isArray(raw)) {
        for (const [letter, val] of Object.entries(raw)) {
            if (val === true || val === 1 || val === '1') states[letter] = 'allow';
            else if (val === false || val === 0 || val === '0') states[letter] = 'deny';
        }
        return states;
    }
    let sign = '+';
    for (const ch of String(raw ?? '')) {
        if (ch === '+' || ch === '-') { sign = ch; continue; }
        if (/\s/.test(ch)) continue;
        states[ch] = sign === '-' ? 'deny' : 'allow';
    }
    return states;
}

function serializeAcl(states) {
    const order = [...CRUD, ...Object.keys(states).filter((l) => !CRUD.includes(l))];
    let out = '';
    let cur = '+';
    for (const letter of order) {
        const st = states[letter];
        if (!st) continue;
        const sign = st === 'deny' ? '-' : '+';
        if (sign !== cur) { out += sign; cur = sign; }
        out += letter;
    }
    return out;
}

// ─── Inline SVG icons ───────────────────────────────────────────────────
const UPDOWN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="updown"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>';
const SEARCH = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
const CLOSE = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
const CHECK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const PLUS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
const TRASH = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';
const LOCK = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
const LOCK_OPEN = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';

function esc(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const STYLE = `
    :host { display: block; font-family: inherit; }
    .wrap { display: flex; flex-direction: column; gap: 8px; }
    .row { display: flex; align-items: center; gap: 8px; }
    .select-wrap { position: relative; flex: 1; min-width: 0; }
    .combo-trigger {
        display: flex; align-items: center; gap: 8px;
        width: 100%; height: 40px; padding: 0 10px 0 12px;
        border: 1px solid var(--border, #e2e8f0); border-radius: 8px;
        background: var(--muted, #f8fafc); color: var(--foreground, #0f172a);
        font-size: 14px; font-family: inherit; cursor: pointer; text-align: start;
        box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    .combo-trigger:hover { background: var(--accent, #f1f5f9); }
    .combo-trigger .lbl { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .combo-trigger .ph { color: var(--muted-foreground, #94a3b8); }
    .combo-trigger .updown { margin-inline-start: auto; flex: none; color: var(--muted-foreground, #64748b); }

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

    .node { display: flex; align-items: center; gap: 6px; padding: 7px 10px; border-radius: 8px; cursor: pointer; color: var(--foreground, #0f172a); }
    .node:hover { background: var(--accent, #f1f5f9); }
    .node.sel { background: color-mix(in srgb, var(--primary, #6366f1) 14%, transparent); color: var(--primary, #6366f1); }
    .node-lbl { min-width: 0; }
    .node-lbl .lbl { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .node .tick { margin-inline-start: auto; flex: none; display: inline-flex; color: var(--primary, #6366f1); }
    .empty-msg { padding: 14px; text-align: center; font-size: 13px; color: var(--muted-foreground, #64748b); }

    .crud-group { display: inline-flex; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .crud { display: inline-flex; align-items: center; gap: 5px; height: 40px; padding: 0 12px; border: 0; cursor: pointer; background: var(--background, #fff); color: var(--muted-foreground, #94a3b8); font-size: 13px; font-weight: 600; font-family: inherit; }
    .crud + .crud { border-inline-start: 1px solid var(--border, #e2e8f0); }
    .crud svg { flex: none; opacity: .85; }
    .crud.unset { color: var(--muted-foreground, #94a3b8); }
    .crud.allow { background: #16a34a; color: #fff; }
    .crud.deny  { background: #dc2626; color: #fff; }

    .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border: 0; border-radius: 8px; cursor: pointer; background: transparent; color: var(--muted-foreground, #64748b); }
    .icon-btn:hover { background: var(--accent, #f1f5f9); color: var(--foreground, #0f172a); }
    .icon-btn.add { background: var(--primary, #6366f1); color: #fff; }
    .icon-btn.add:hover { filter: brightness(1.05); }
    .icon-btn.del:hover { color: #dc2626; }
`;

customElements.define(TAG, AclPermissionsField);
