/*
 * DB Admin UI. Plain Alpine.js, no build step.
 *
 * Cells from the API are null, a string, {"$b64": "..."} for binary data, or
 * {"$t" | "$b64": "...", "len": n} for the start of a longer value.
 */
document.addEventListener('alpine:init', () => {
    const HISTORY_KEY = 'db-admin:history';
    const HISTORY_SIZE = 30;

    const isObject = (value) => value !== null && typeof value === 'object';

    const quoteIdentifier = (name) => '`' + String(name).replace(/`/g, '``') + '`';

    const readHistory = () => {
        try {
            const stored = JSON.parse(window.sessionStorage.getItem(HISTORY_KEY) || '[]');
            return Array.isArray(stored) ? stored.filter((entry) => typeof entry === 'string') : [];
        } catch {
            return [];
        }
    };

    const emptyBrowse = () => ({ columns: [], rows: [], key: null, offset: 0, hasMore: false, total: null, exact: true, error: '' });

    Alpine.data('dbAdmin', () => ({
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        isMac: /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent),
        session: { label: '', readonly: false, operators: [], limits: { pageSize: 50 } },
        sessionReady: false,
        databases: [],
        db: '',
        tables: [],
        tablesLoading: false,
        tableFilter: '',
        table: null,
        tab: 'tables',

        // Browse: what is being edited, and what the grid is showing.
        search: '',
        appliedSearch: '',
        filters: [],
        appliedFilters: [],
        filterOpen: false,
        sort: null,
        dir: 'asc',
        offset: 0,
        limit: 50,
        browse: emptyBrowse(),
        rowsLoading: false,
        rowsRequest: 0,

        structure: null,
        structureLoading: false,

        sql: '',
        sqlResults: [],
        sqlTotal: 0,
        history: readHistory(),
        confirmation: [],

        modal: null,
        modalTitle: '',
        cell: { loading: false, value: null, format: null, pretty: null, view: 'raw' },

        urlReady: false,
        pending: 0,
        action: null,
        toasts: [],
        signedOut: false,

        get busy() {
            return this.pending > 0 || this.action !== null;
        },

        async init() {
            const url = new URLSearchParams(window.location.search);

            try {
                this.session = await this.api('session');
                this.limit = this.session.limits.pageSize;
                this.databases = await this.api('databases');

                const names = this.databases.map((database) => database.name);
                const wanted = url.get('db');

                this.db = names.includes(wanted) ? wanted : (names.includes(this.session.database) ? this.session.database : (names[0] || ''));
                this.sessionReady = true;

                if (this.db) {
                    await this.loadTables();
                    await this.restore(url);
                }
            } catch (error) {
                this.fail(error);
            } finally {
                this.sessionReady = true;
                this.urlReady = true;
            }
        },

        /**
         * Reopen the table, tab, page, sort and filters the URL names.
         */
        async restore(url) {
            const table = url.get('table');
            const tab = url.get('tab');

            if (!table || !this.tables.some((item) => item.name === table)) {
                if (tab === 'sql') {
                    this.openSql(null);
                }

                return;
            }

            this.search = this.appliedSearch = url.get('q') || '';
            this.sort = url.get('sort') || null;
            this.dir = url.get('dir') === 'desc' ? 'desc' : 'asc';

            const offset = Number.parseInt(url.get('offset') || '', 10);
            this.offset = Number.isInteger(offset) && offset > 0 ? offset : 0;

            const limit = Number.parseInt(url.get('limit') || '', 10);
            if ([25, 50, 100, 250, 500].includes(limit)) {
                this.limit = limit;
            }

            try {
                const filters = JSON.parse(url.get('f') || '[]');
                if (Array.isArray(filters)) {
                    this.filters = filters.filter((f) => isObject(f) && typeof f.column === 'string' && typeof f.operator === 'string')
                        .map((f) => ({ column: f.column, operator: f.operator, value: String(f.value ?? '') }));
                    this.appliedFilters = this.filters.map((f) => ({ ...f }));
                }
            } catch {
                // A mangled filter in the URL is dropped, not an error.
            }

            await this.openTable(table, { tab: ['browse', 'structure', 'sql'].includes(tab) ? tab : 'browse', keepState: true });
        },

        /**
         * Write what the page is showing into its URL, replacing the history
         * entry, so a reload lands on the same view. Run from x-effect.
         */
        syncUrl() {
            if (!this.urlReady) {
                return;
            }

            const params = new URLSearchParams();

            if (this.db) {
                params.set('db', this.db);
            }

            if (this.table) {
                params.set('table', this.table);
            }

            if (this.tab !== (this.table ? 'browse' : 'tables')) {
                params.set('tab', this.tab);
            }

            if (this.table && this.tab === 'browse') {
                if (this.appliedSearch) params.set('q', this.appliedSearch);
                if (this.appliedFilters.length) params.set('f', JSON.stringify(this.appliedFilters));
                if (this.sort) params.set('sort', this.sort);
                if (this.sort && this.dir === 'desc') params.set('dir', 'desc');
                if (this.offset > 0) params.set('offset', String(this.offset));
                if (this.limit !== this.session.limits.pageSize) params.set('limit', String(this.limit));
            }

            const search = params.toString();
            const next = window.location.pathname + (search === '' ? '' : '?' + search);

            if (next !== window.location.pathname + window.location.search) {
                window.history.replaceState(window.history.state, '', next);
            }
        },

        // ---- API ---------------------------------------------------------

        async api(action, { query = {}, body = null } = {}) {
            const params = new URLSearchParams({ action });
            Object.entries(query).forEach(([name, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    params.set(name, value);
                }
            });

            const options = body === null
                ? { headers: { Accept: 'application/json' } }
                : {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify(body),
                };

            this.pending++;

            try {
                const response = await fetch('api.php?' + params.toString(), { credentials: 'same-origin', ...options });
                return await this.unwrap(response);
            } finally {
                this.pending--;
            }
        },

        async unwrap(response) {
            let payload = null;

            try {
                payload = await response.json();
            } catch {
                throw new Error(`Unexpected response from the server (${response.status}).`);
            }

            if (response.status === 401) {
                this.signedOut = true;
            }

            if (!response.ok) {
                throw new Error(payload.error || `Request failed (${response.status}).`);
            }

            return payload.data;
        },

        /**
         * Run an action made of one or more requests, with its button showing
         * a spinner and every other control disabled until it is done.
         */
        async run(callback, name) {
            if (this.action !== null) {
                return undefined;
            }

            this.action = name;

            try {
                return await callback();
            } catch (error) {
                this.fail(error);
                return undefined;
            } finally {
                this.action = null;
            }
        },

        // ---- Databases and tables -----------------------------------------

        async selectDatabase() {
            this.table = null;
            this.tab = 'tables';
            this.tableFilter = '';
            this.sqlResults = [];
            this.resetBrowse();
            await this.loadTables();
        },

        async loadTables() {
            if (!this.db) {
                return;
            }

            const db = this.db;
            this.tablesLoading = true;

            try {
                const tables = await this.api('tables', { query: { db } });

                if (db === this.db) {
                    this.tables = tables;
                }
            } catch (error) {
                this.fail(error);
            } finally {
                this.tablesLoading = false;
            }
        },

        filteredTables() {
            const needle = this.tableFilter.trim().toLowerCase();
            return needle === '' ? this.tables : this.tables.filter((item) => item.name.toLowerCase().includes(needle));
        },

        currentTable() {
            return this.tables.find((item) => item.name === this.table) || null;
        },

        tablesStatus() {
            if (!this.db) {
                return '';
            }

            const shown = this.filteredTables().length;
            const count = this.tables.length;
            const size = this.bytes(this.totalOf('dataLength') + this.totalOf('indexLength'));

            return (shown === count ? `${count} table${count === 1 ? '' : 's'}` : `${shown} of ${count} tables`) + ` · ${size}`;
        },

        totalOf(field) {
            return this.tables.reduce((sum, item) => sum + (item.view ? 0 : Number(item[field] || 0)), 0);
        },

        totalRows() {
            return this.tables.reduce((sum, item) => sum + (item.view ? 0 : Number(item.rows || 0)), 0);
        },

        openOverview() {
            this.table = null;
            this.tab = 'tables';
            this.structure = null;
            this.loadTables();
        },

        closeTable() {
            this.table = null;
            this.tab = 'tables';
        },

        async openTable(name, { tab = 'browse', keepState = false } = {}) {
            if (!keepState || this.table !== name) {
                if (!keepState) {
                    this.resetBrowse();
                }

                this.structure = null;
                this.sqlResults = [];
                this.sql = `SELECT * FROM ${quoteIdentifier(name)} LIMIT 100;`;
            }

            this.table = name;
            await this.setTab(tab);
        },

        async setTab(tab) {
            this.tab = tab;

            if (tab === 'browse') {
                await this.loadRows();
            } else if (tab === 'structure' && !this.structure) {
                await this.loadStructure();
            } else if (tab === 'sql') {
                this.$nextTick(() => this.$refs.sqlEditor.focus());
            }
        },

        openSql(table) {
            if (table === null && this.table !== null) {
                this.table = null;
                this.sqlResults = [];
            }

            this.tab = 'sql';
            this.$nextTick(() => this.$refs.sqlEditor.focus());
        },

        // ---- Browse -------------------------------------------------------

        resetBrowse() {
            this.browse = emptyBrowse();
            this.search = '';
            this.appliedSearch = '';
            this.filters = [];
            this.appliedFilters = [];
            this.filterOpen = false;
            this.sort = null;
            this.dir = 'asc';
            this.offset = 0;
        },

        async loadRows() {
            if (!this.table) {
                return;
            }

            const request = ++this.rowsRequest;
            this.rowsLoading = true;

            try {
                const data = await this.api('rows', {
                    query: {
                        db: this.db,
                        table: this.table,
                        offset: this.offset,
                        limit: this.limit,
                        sort: this.sort,
                        dir: this.dir,
                        q: this.appliedSearch,
                        filters: this.appliedFilters.length ? JSON.stringify(this.appliedFilters) : null,
                    },
                });

                // A response to an older request never lands over a newer one.
                if (request === this.rowsRequest) {
                    this.browse = { ...data, error: '' };
                }
            } catch (error) {
                if (request === this.rowsRequest) {
                    this.browse = { ...this.browse, rows: [], error: error.message };
                }
            } finally {
                if (request === this.rowsRequest) {
                    this.rowsLoading = false;
                }
            }
        },

        applySearch() {
            this.appliedSearch = this.search.trim();
            this.offset = 0;
            this.loadRows();
        },

        addFilter() {
            const column = this.browse.columns[0];
            this.filters.push({ column: column ? column.name : '', operator: '=', value: '' });
        },

        applyFilters() {
            this.appliedFilters = this.filters.filter((f) => f.column).map((f) => ({ ...f }));
            this.offset = 0;
            this.loadRows();
        },

        clearFilters() {
            this.filters = [];
            this.appliedFilters = [];
            this.offset = 0;
            this.loadRows();
        },

        toggleSort(column) {
            if (this.sort !== column) {
                this.sort = column;
                this.dir = 'asc';
            } else if (this.dir === 'asc') {
                this.dir = 'desc';
            } else {
                this.sort = null;
                this.dir = 'asc';
            }

            this.offset = 0;
            this.loadRows();
        },

        page(step) {
            this.offset = Math.max(0, this.offset + step * this.limit);
            this.loadRows();
        },

        pageLabel() {
            const count = this.browse.rows.length;

            if (count === 0) {
                return this.browse.total === 0 || this.offset === 0 ? 'No rows' : 'No rows on this page';
            }

            const from = (this.offset + 1).toLocaleString();
            const to = (this.offset + count).toLocaleString();

            if (this.browse.total === null) {
                return `Rows ${from}–${to}`;
            }

            return `Rows ${from}–${to} of ${this.browse.exact ? '' : '~'}${this.browse.total.toLocaleString()}`;
        },

        // ---- Cells --------------------------------------------------------

        async openCell(r, c) {
            const cell = this.browse.rows[r][c];
            const column = this.browse.columns[c];
            const key = this.rowKey(this.browse.rows[r]);

            this.showCell(cell, column.name);

            // With a row key, the whole value is fetched, and decoded when it
            // holds JSON or serialized PHP.
            if (key === null || cell === null) {
                return;
            }

            this.cell.loading = true;

            try {
                const data = await this.api('value', {
                    query: { db: this.db, table: this.table, column: column.name, key: JSON.stringify(key) },
                });

                if (this.modal === 'cell') {
                    this.cell = { ...this.cell, ...data, view: data.pretty ? 'pretty' : 'raw', loading: false };
                }
            } catch (error) {
                this.cell.loading = false;
                this.fail(error);
            }
        },

        showCell(cell, name) {
            this.cell = { loading: false, value: cell, format: null, pretty: null, view: 'raw' };
            this.modalTitle = name;
            this.modal = 'cell';
        },

        /**
         * The row key's values for a row, or null when the table has none or
         * one of them was cut off.
         */
        rowKey(row) {
            if (!this.browse.key) {
                return null;
            }

            const key = {};

            for (const name of this.browse.key) {
                const index = this.browse.columns.findIndex((column) => column.name === name);
                const value = row[index];

                if (index < 0 || (isObject(value) && 'len' in value)) {
                    return null;
                }

                key[name] = value;
            }

            return key;
        },

        cellText(cell) {
            if (cell === null) {
                return 'NULL';
            }

            if (typeof cell === 'string') {
                return cell.length > 300 ? cell.slice(0, 300) + '…' : cell;
            }

            if ('$t' in cell) {
                return cell.$t.slice(0, 300) + '…';
            }

            return `[binary, ${this.bytes('len' in cell ? cell.len : Math.floor(cell.$b64.length * 3 / 4))}]`;
        },

        cellFullText(cell) {
            if (cell === null) {
                return 'NULL';
            }

            if (typeof cell === 'string') {
                return cell;
            }

            if ('$t' in cell) {
                return cell.$t;
            }

            return cell.$b64;
        },

        cellTruncated(cell) {
            return isObject(cell) && 'len' in cell;
        },

        cellClass(cell, column) {
            return {
                cell: true,
                num: column && column.numeric,
                null: cell === null,
                binary: isObject(cell) && '$b64' in cell,
            };
        },

        cellMeta() {
            const value = this.cell.value;

            if (value === null) {
                return 'NULL';
            }

            const parts = [];

            if (isObject(value) && '$b64' in value) {
                parts.push('binary, shown as base64');
            } else if (this.cell.format) {
                parts.push(this.cell.format);
            }

            if (this.cellTruncated(value)) {
                parts.push(this.bytes(value.len));
            }

            return parts.join(' · ');
        },

        // ---- Structure ----------------------------------------------------

        async loadStructure() {
            this.structureLoading = true;

            try {
                this.structure = await this.api('structure', { query: { db: this.db, table: this.table } });
            } catch (error) {
                this.fail(error);
            } finally {
                this.structureLoading = false;
            }
        },

        // ---- SQL ----------------------------------------------------------

        sqlKeydown(event) {
            if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                this.runSql(false);
            }

            // Tab indents rather than leaving the editor.
            if (event.key === 'Tab' && !event.shiftKey && !event.metaKey && !event.ctrlKey && !event.altKey) {
                event.preventDefault();
                const editor = event.target;
                const start = editor.selectionStart;
                this.sql = this.sql.slice(0, start) + '    ' + this.sql.slice(editor.selectionEnd);
                this.$nextTick(() => { editor.selectionStart = editor.selectionEnd = start + 4; });
            }
        },

        explainSql() {
            const text = this.sql.trim().replace(/;\s*$/, '');

            if (text.includes(';')) {
                this.notify('Explain works on one statement at a time.', 'error');
                return;
            }

            this.execute('EXPLAIN ' + text, false);
        },

        runSql(confirmed) {
            this.execute(this.sql, confirmed);
        },

        async execute(sql, confirmed) {
            await this.run(async () => {
                const data = await this.api('query', { query: { db: this.db }, body: { sql, confirmed } });

                if (data.confirm) {
                    this.confirmation = data.confirm;
                    this.modalTitle = 'Run these statements?';
                    this.modal = 'confirm';
                    return;
                }

                this.remember(this.sql);
                this.sqlResults = data.results;
                this.sqlTotal = data.total;

                // A USE among the statements, or a table created or dropped.
                if (data.database && data.database !== this.db && this.databases.some((d) => d.name === data.database)) {
                    this.db = data.database;
                    this.table = null;
                }

                this.loadTables();
            }, 'sql');
        },

        remember(sql) {
            const entry = sql.trim();

            if (entry === '') {
                return;
            }

            this.history = [entry, ...this.history.filter((item) => item !== entry)].slice(0, HISTORY_SIZE);

            try {
                window.sessionStorage.setItem(HISTORY_KEY, JSON.stringify(this.history));
            } catch {
                // History is a convenience; a full or blocked store is fine.
            }
        },

        sqlStopped() {
            const last = this.sqlResults[this.sqlResults.length - 1];

            if (!last || !last.error) {
                return '';
            }

            const ran = this.sqlResults.length - 1;
            const skipped = this.sqlTotal - this.sqlResults.length;

            return `Stopped at statement ${last.index + 1}.`
                + (ran > 0 ? ` The ${ran === 1 ? 'statement' : ran + ' statements'} before it did run.` : '')
                + (skipped > 0 ? ` ${skipped} after it did not.` : '');
        },

        affectedLabel(set) {
            const parts = [`${set.affected.toLocaleString()} row${set.affected === 1 ? '' : 's'} affected`];

            if (set.insertId) {
                parts.push(`insert id ${set.insertId}`);
            }

            if (set.info) {
                parts.push(set.info);
            }

            return parts.join(' · ');
        },

        rowCountLabel(set) {
            if (set.truncated) {
                return `Showing ${set.rows.length.toLocaleString()} rows; the result has more.`;
            }

            return `${set.rowCount.toLocaleString()} row${set.rowCount === 1 ? '' : 's'}`;
        },

        // ---- Modals and notices --------------------------------------------

        closeModal() {
            this.modal = null;
        },

        async copy(text) {
            try {
                await navigator.clipboard.writeText(text ?? '');
                this.notify('Copied.');
            } catch {
                this.notify('Copying is blocked in this browser.', 'error');
            }
        },

        notify(message, kind = 'success') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, kind });
            setTimeout(() => this.dismiss(id), kind === 'error' ? 7000 : 3000);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },

        fail(error) {
            this.notify(error && error.message ? error.message : String(error), 'error');
        },

        shortcut(event) {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);

            if (typing || this.modal || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                this.$refs.tableFilter.focus();
            }
        },

        // ---- Formatting ----------------------------------------------------

        rowsShort(item) {
            if (item.rows === null) {
                return '';
            }

            const n = item.rows;
            const prefix = item.rowsExact ? '' : '~';

            if (n < 1000) return prefix + n;
            if (n < 1e6) return prefix + (n / 1e3).toFixed(n < 1e4 ? 1 : 0) + 'k';
            return prefix + (n / 1e6).toFixed(n < 1e7 ? 1 : 0) + 'M';
        },

        rowsLong(item) {
            if (item.rows === null) {
                return '';
            }

            return `${item.rowsExact ? '' : '~'}${item.rows.toLocaleString()} rows`;
        },

        bytes(size) {
            if (size === null || size === undefined) {
                return '—';
            }

            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let value = Number(size);
            let unit = 0;

            while (value >= 1024 && unit < units.length - 1) {
                value /= 1024;
                unit++;
            }

            return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
        },
    }));
});
