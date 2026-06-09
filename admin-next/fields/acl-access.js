/**
 * Page Access ACL picker (admin-next custom field).
 *
 * Mirrors admin-classic's `acl_picker` with `data_type: access`: a list of
 * rows, each pairing an access action (e.g. `admin.login`) with an
 * Allowed / Denied choice.
 *
 * Value shape (what grav-core reads from `header.access`):
 *   { "admin.login": true, "site.login": false }
 *   true = Allowed, false = Denied. Absent keys are unset.
 *
 * The action dropdown is a type-ahead popover with a searchable, expandable
 * tree (the action names are hierarchical: admin > admin.pages >
 * admin.pages.create), modelled on admin-next's page-parent picker. Options
 * are baked into `field.options` server-side as [{ value, label }] where value
 * is the dotted action name and label is the short action label.
 */

const TAG = window.__GRAV_FIELD_TAG;

class AclAccessField extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = null;
        this._rows = null;          // [{ key, allowed }]
        this._lastEmitted = null;
        this._openRow = -1;         // index of the row whose popover is open
        this._filter = '';
        this._expanded = new Set(); // expanded tree nodes (by action name)
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
            for (const [key, val] of Object.entries(v)) {
                rows.push({ key, allowed: val !== false });
            }
        }
        // Show a single blank starter row only when nothing is set yet, so the
        // controls are visible. Once there's an entry, the "+" button adds the
        // next blank row — we don't auto-trail one.
        if (rows.length === 0) rows.push({ key: '', allowed: true });
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
            if (row.key) out[row.key] = !!row.allowed;
        }
        this._value = out;
        this._lastEmitted = JSON.stringify(out);
        this.dispatchEvent(new CustomEvent('change', { detail: out, bubbles: true }));
    }

    _ensureTrailingBlank() {
        const last = this._rows[this._rows.length - 1];
        if (!last || last.key !== '') this._rows.push({ key: '', allowed: true });
    }

    // ─── Render ─────────────────────────────────────────────────────────

    _render() {
        if (!this.shadowRoot || !this.isConnected) return;
        if (!this._rows) this._rows = this._rowsFromValue(this._value);

        const rowsHtml = this._rows.map((row, i) => `
            <div class="row" data-i="${i}">
                <button type="button" class="icon-btn del" title="Remove" data-act="del">${TRASH}</button>
                <div class="select-wrap">
                    <button type="button" class="combo-trigger ${row.key ? '' : 'empty'}" data-act="open">
                        ${row.key
                            ? `<span class="lbl">${esc(this._optionLabel(row.key) || row.key)}</span><span class="muted">${esc(row.key)}</span>`
                            : `<span class="ph">Select access…</span>`}
                        ${UPDOWN}
                    </button>
                </div>
                <div class="toggle" role="group">
                    <button type="button" class="seg allow ${row.allowed ? 'on' : ''}" data-act="allow">${CHECK}<span>Allowed</span></button>
                    <button type="button" class="seg deny ${row.allowed ? '' : 'on'}" data-act="deny">${BAN}<span>Denied</span></button>
                </div>
                <button type="button" class="icon-btn add" title="Add" data-act="add">${PLUS}</button>
            </div>
        `).join('');

        this.shadowRoot.innerHTML = `<style>${STYLE}</style><div class="wrap">${rowsHtml}</div>`;

        this.shadowRoot.querySelectorAll('.row').forEach((el) => {
            const i = Number(el.dataset.i);
            el.querySelector('[data-act="open"]')?.addEventListener('click', () => this._toggleOpen(i));
            el.querySelector('[data-act="allow"]')?.addEventListener('click', () => this._onToggle(i, true));
            el.querySelector('[data-act="deny"]')?.addEventListener('click', () => this._onToggle(i, false));
            el.querySelector('[data-act="del"]')?.addEventListener('click', () => this._onDelete(i));
            el.querySelector('[data-act="add"]')?.addEventListener('click', () => this._onAdd());
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
                <input type="text" placeholder="Filter access…" />
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

    _buildTree() {
        const nodes = new Map();
        for (const o of this._options()) {
            const v = String(o.value);
            nodes.set(v, { value: v, label: String(o.label ?? v), children: [] });
        }
        const roots = [];
        for (const o of this._options()) {
            const v = String(o.value);
            const node = nodes.get(v);
            const pi = v.lastIndexOf('.');
            const parent = pi >= 0 ? nodes.get(v.slice(0, pi)) : null;
            if (parent) parent.children.push(node); else roots.push(node);
        }
        return roots;
    }

    _renderResults(rowIndex) {
        if (!this._resultsEl) return;
        const selected = this._rows[rowIndex]?.key || '';
        const q = this._filter.trim().toLowerCase();
        let html = '';

        if (q) {
            // Flat, depth-indented list of every matching action.
            const matches = this._options().filter((o) =>
                String(o.label).toLowerCase().includes(q) || String(o.value).toLowerCase().includes(q));
            html = matches.map((o) => this._nodeRow(String(o.value), String(o.label ?? o.value),
                depthOf(String(o.value)), false, false, String(o.value) === selected)).join('');
            if (!matches.length) html = `<div class="empty-msg">No matching access</div>`;
        } else {
            const walk = (node, depth) => {
                const hasKids = node.children.length > 0;
                const isExp = this._expanded.has(node.value);
                let out = this._nodeRow(node.value, node.label, depth, hasKids, isExp, node.value === selected);
                if (hasKids && isExp) out += node.children.map((c) => walk(c, depth + 1)).join('');
                return out;
            };
            html = this._buildTree().map((n) => walk(n, 0)).join('');
        }

        this._resultsEl.innerHTML = html;

        this._resultsEl.querySelectorAll('[data-value]').forEach((el) => {
            el.querySelector('.exp')?.addEventListener('click', (e) => {
                e.stopPropagation();
                const v = el.dataset.value;
                if (this._expanded.has(v)) this._expanded.delete(v); else this._expanded.add(v);
                this._renderResults(rowIndex);
            });
            el.addEventListener('click', () => this._select(rowIndex, el.dataset.value));
        });
    }

    _nodeRow(value, label, depth, hasKids, isExpanded, selected) {
        const chevron = hasKids
            ? `<span class="exp">${isExpanded ? CHEVRON_DOWN : CHEVRON_RIGHT}</span>`
            : `<span class="exp spacer"></span>`;
        return `
            <div class="node ${selected ? 'sel' : ''}" data-value="${esc(value)}" style="padding-inline-start:${depth * 14 + 6}px">
                ${chevron}
                <span class="node-lbl"><span class="lbl">${esc(label)}</span><span class="muted">${esc(value)}</span></span>
                ${selected ? `<span class="tick">${CHECK}</span>` : ''}
            </div>`;
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
        // Clicking the same trigger is handled by its own click toggler.
        const trigger = this.shadowRoot.querySelector(`.row[data-i="${this._openRow}"] [data-act="open"]`);
        if (trigger && e.composedPath().includes(trigger)) return;
        this._openRow = -1;
        this._render();
    }

    // ─── Row actions ────────────────────────────────────────────────────

    _onToggle(i, allowed) {
        if (!this._rows[i]) return;
        this._rows[i].allowed = allowed;
        this._commit();
        this._render();
    }

    _onDelete(i) {
        this._rows.splice(i, 1);
        // Keep one blank starter row when the list is emptied entirely.
        if (!this._rows.length) this._rows.push({ key: '', allowed: true });
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

function depthOf(value) { return value.split('.').length - 1; }

// ─── Inline SVG icons (Lucide-style, currentColor) ──────────────────────
const UPDOWN = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="updown"><polyline points="7 15 12 20 17 15"/><polyline points="7 9 12 4 17 9"/></svg>';
const CHEVRON_DOWN = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>';
const CHEVRON_RIGHT = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
const SEARCH = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
const CLOSE = '<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
const CHECK = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
const BAN = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="5.6" y1="5.6" x2="18.4" y2="18.4"/></svg>';
const PLUS = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';
const TRASH = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>';

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
    .combo-trigger .lbl { font-weight: 500; white-space: nowrap; }
    .combo-trigger .muted { color: var(--muted-foreground, #64748b); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .combo-trigger .ph { color: var(--muted-foreground, #94a3b8); }
    .combo-trigger .updown { margin-inline-start: auto; flex: none; color: var(--muted-foreground, #64748b); }

    .popover {
        position: absolute; top: calc(100% + 4px); inset-inline-start: 0;
        width: max(100%, 340px); max-width: 92vw; z-index: 60;
        background: var(--popover, var(--background, #fff)); color: var(--foreground, #0f172a);
        border: 1px solid var(--border, #e2e8f0); border-radius: 12px;
        box-shadow: 0 10px 30px rgba(0,0,0,.18); overflow: hidden;
    }
    .search { display: flex; align-items: center; gap: 8px; padding: 8px 10px; border-bottom: 1px solid var(--border, #e2e8f0); color: var(--muted-foreground, #64748b); }
    .search input { flex: 1; min-width: 0; border: 0; background: transparent; color: var(--foreground, #0f172a); font-size: 14px; font-family: inherit; outline: none; }
    .search .clear { border: 0; background: transparent; color: var(--muted-foreground, #64748b); cursor: pointer; display: inline-flex; padding: 2px; }
    .search .clear:hover { color: var(--foreground, #0f172a); }
    .results { max-height: 288px; overflow-y: auto; padding: 4px; }

    .node {
        display: flex; align-items: center; gap: 6px;
        padding: 6px 8px; border-radius: 8px; cursor: pointer;
        color: var(--foreground, #0f172a);
    }
    .node:hover { background: var(--accent, #f1f5f9); }
    .node.sel { background: color-mix(in srgb, var(--primary, #6366f1) 14%, transparent); color: var(--primary, #6366f1); }
    .node .exp { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 4px; color: var(--muted-foreground, #64748b); flex: none; }
    .node .exp:not(.spacer):hover { background: color-mix(in srgb, var(--foreground, #000) 8%, transparent); }
    .node-lbl { display: flex; align-items: baseline; gap: 8px; min-width: 0; }
    .node-lbl .lbl { font-size: 14px; white-space: nowrap; }
    .node-lbl .muted { font-size: 12px; color: var(--muted-foreground, #64748b); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .node .tick { margin-inline-start: auto; flex: none; display: inline-flex; color: var(--primary, #6366f1); }
    .empty-msg { padding: 14px; text-align: center; font-size: 13px; color: var(--muted-foreground, #64748b); }

    .toggle { display: inline-flex; border: 1px solid var(--border, #e2e8f0); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .seg { display: inline-flex; align-items: center; gap: 6px; height: 40px; padding: 0 14px; border: 0; cursor: pointer; background: var(--background, #fff); color: var(--muted-foreground, #64748b); font-size: 14px; font-family: inherit; font-weight: 500; }
    .seg + .seg { border-inline-start: 1px solid var(--border, #e2e8f0); }
    .seg.allow.on { background: #16a34a; color: #fff; }
    .seg.deny.on { background: var(--muted, #f1f5f9); color: var(--foreground, #0f172a); }
    .seg svg { flex: none; }

    .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border: 0; border-radius: 8px; cursor: pointer; background: transparent; color: var(--muted-foreground, #64748b); }
    .icon-btn:hover { background: var(--accent, #f1f5f9); color: var(--foreground, #0f172a); }
    .icon-btn.add { background: var(--primary, #6366f1); color: #fff; }
    .icon-btn.add:hover { filter: brightness(1.05); }
    .icon-btn.del:hover { color: #dc2626; }
`;

customElements.define(TAG, AclAccessField);
