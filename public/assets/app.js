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

    const IMPORT_CHUNK = 8 * 1024 * 1024;

    const sleep = (ms) => new Promise((resolve) => { setTimeout(resolve, ms); });

    const emptyEditor = () => ({ mode: 'edit', loading: false, fields: [], texts: {}, modes: {}, locked: {}, original: { texts: {}, modes: {} }, key: null });

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

        selectedRows: [],
        tableSelection: [],
        editor: emptyEditor(),
        danger: { message: '', phrase: '', typed: '', label: '', run: null },
        renameTo: '',
        messages: [],
        exportOptions: { tables: [], structure: true, data: true, gzip: false },
        importJob: null,
        importCancelled: false,
        lastSql: '',

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
            this.tableSelection = [];
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
                    this.tableSelection = this.tableSelection.filter((name) => tables.some((item) => item.name === name));
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
                    this.selectedRows = [];
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
                this.lastSql = sql;
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

        // ---- Editing rows -------------------------------------------------

        canWrite() {
            return !this.session.readonly;
        },

        isView() {
            const current = this.currentTable();
            return Boolean(current && current.view);
        },

        rowsEditable() {
            return this.canWrite() && Array.isArray(this.browse.key) && !this.isView();
        },

        fieldModes(field) {
            const modes = ['value'];

            if (field.nullable) {
                modes.push('null');
            }

            if (this.editor.mode === 'insert') {
                modes.push('default');
            }

            return modes;
        },

        async openEditor(r) {
            const key = this.rowKey(this.browse.rows[r]);

            if (key === null) {
                this.notify('This row cannot be picked out on its own, so it cannot be edited here.', 'error');
                return;
            }

            this.editor = { ...emptyEditor(), loading: true, key };
            this.modalTitle = `Edit row in ${this.table}`;
            this.modal = 'row';

            try {
                const data = await this.api('row', { query: { db: this.db, table: this.table, key: JSON.stringify(key) } });
                const texts = {};
                const modes = {};
                const locked = {};

                data.fields.forEach((field) => {
                    const value = data.values[field.name];
                    locked[field.name] = isObject(value) && 'len' in value;
                    modes[field.name] = value === null ? 'null' : 'value';
                    texts[field.name] = value === null ? '' : (typeof value === 'string' ? value : (value.$b64 ?? value.$t));
                });

                this.editor = { ...this.editor, loading: false, fields: data.fields, texts, modes, locked, original: { texts: { ...texts }, modes: { ...modes } } };
            } catch (error) {
                this.modal = null;
                this.fail(error);
            }
        },

        async openInsert() {
            this.editor = { ...emptyEditor(), mode: 'insert', loading: true };
            this.modalTitle = `Insert a row into ${this.table}`;
            this.modal = 'row';

            try {
                const fields = await this.api('fields', { query: { db: this.db, table: this.table } });
                const texts = {};
                const modes = {};

                fields.forEach((field) => {
                    texts[field.name] = field.options && field.dataType === 'enum' && !field.nullable ? field.options[0] : '';
                    modes[field.name] = field.autoIncrement || field.default !== null ? 'default' : (field.nullable ? 'null' : 'value');
                });

                this.editor = { ...this.editor, loading: false, fields, texts, modes };
            } catch (error) {
                this.modal = null;
                this.fail(error);
            }
        },

        /**
         * The value a field sends, or undefined to leave the column out.
         */
        fieldValue(field) {
            const mode = this.editor.modes[field.name];

            if (field.generated || this.editor.locked[field.name] || mode === 'default') {
                return undefined;
            }

            if (mode === 'null') {
                return null;
            }

            const text = this.editor.texts[field.name] ?? '';

            if (field.binary) {
                const b64 = text.replace(/\s+/g, '');

                try {
                    atob(b64);
                } catch {
                    throw new Error(`${field.name} is not valid base64.`);
                }

                return { $b64: b64 };
            }

            return text;
        },

        async saveRow(asNew) {
            await this.run(async () => {
                const values = {};
                const inserting = this.editor.mode === 'insert' || asNew;

                this.editor.fields.forEach((field) => {
                    // A copy gets a new auto-increment number, not the old one.
                    if (asNew && field.autoIncrement) {
                        return;
                    }

                    const changed = this.editor.modes[field.name] !== this.editor.original.modes[field.name]
                        || this.editor.texts[field.name] !== this.editor.original.texts[field.name];

                    if (!inserting && !changed) {
                        return;
                    }

                    const value = this.fieldValue(field);

                    if (value !== undefined) {
                        values[field.name] = value;
                    }
                });

                if (asNew && this.editor.fields.some((field) => this.editor.locked[field.name])) {
                    throw new Error('This row has values too long to copy here.');
                }

                if (inserting) {
                    const data = await this.api('insert', { query: { db: this.db, table: this.table }, body: { values } });
                    this.notify(data.insertId ? `Row inserted (id ${data.insertId}).` : 'Row inserted.');
                } else {
                    if (Object.keys(values).length === 0) {
                        this.notify('Nothing was changed.');
                        this.modal = null;
                        return;
                    }

                    await this.api('update', { query: { db: this.db, table: this.table }, body: { key: this.editor.key, values } });
                    this.notify('Row saved.');
                }

                this.modal = null;
                await Promise.all([this.loadRows(), this.loadTables()]);
            }, asNew ? 'duplicate' : 'save');
        },

        confirmDeleteRow() {
            const key = this.editor.key;

            this.confirmDanger({
                title: 'Delete this row?',
                message: `The row is removed from ${this.table} for good.`,
                label: 'Delete row',
                run: () => this.deleteRows([key]),
            });
        },

        confirmDeleteRows() {
            const keys = this.selectedRows.map((index) => this.rowKey(this.browse.rows[index]));

            if (keys.some((key) => key === null)) {
                this.notify('Some of the selected rows cannot be picked out on their own.', 'error');
                return;
            }

            this.confirmDanger({
                title: `Delete ${keys.length} row${keys.length === 1 ? '' : 's'}?`,
                message: `The selected rows are removed from ${this.table} for good.`,
                label: 'Delete',
                run: () => this.deleteRows(keys),
            });
        },

        async deleteRows(keys) {
            const data = await this.api('delete', { query: { db: this.db, table: this.table }, body: { keys } });
            this.notify(`${data.deleted} row${data.deleted === 1 ? '' : 's'} deleted.`);
            await Promise.all([this.loadRows(), this.loadTables()]);
        },

        confirmDanger(options) {
            this.danger = { phrase: '', typed: '', label: 'Continue', ...options };
            this.modalTitle = options.title;
            this.modal = 'danger';
        },

        async runDanger() {
            if (this.danger.phrase && this.danger.typed !== this.danger.phrase) {
                return;
            }

            await this.run(async () => {
                await this.danger.run();
                this.modal = null;
            }, 'danger');
        },

        // ---- Table operations ---------------------------------------------

        confirmTableOperation(tables, operation) {
            const list = tables.length === 1 ? tables[0] : `${tables.length} tables`;
            const text = {
                empty: [`Empty ${list}?`, 'Every row is deleted, one by one, so triggers run and the auto-increment counter is kept.', 'Empty'],
                truncate: [`Truncate ${list}?`, 'Every row is removed at once and the auto-increment counter starts again from 1.', 'Truncate'],
                drop: [`Drop ${list}?`, 'The table and all of its rows are removed for good.', 'Drop'],
            }[operation];

            this.confirmDanger({
                title: text[0],
                message: text[1] + (tables.length > 1 ? ' Tables: ' + tables.join(', ') + '.' : ''),
                label: text[2],
                phrase: tables.length === 1 ? tables[0] : this.db,
                run: async () => {
                    await this.api('table', { query: { db: this.db }, body: { tables, operation } });
                    this.notify(`${text[2]}: done.`);
                    this.tableSelection = [];

                    if (operation === 'drop' && tables.includes(this.table)) {
                        this.openOverview();
                        return;
                    }

                    await this.loadTables();

                    if (this.table && this.tab === 'browse') {
                        await this.loadRows();
                    }
                },
            });
        },

        async maintain(tables, operation) {
            await this.run(async () => {
                const data = await this.api('table', { query: { db: this.db }, body: { tables, operation } });
                this.messages = data.messages;
                this.modalTitle = operation.charAt(0).toUpperCase() + operation.slice(1);
                this.modal = 'messages';
                await this.loadTables();
            }, 'maintain');
        },

        openRename() {
            this.renameTo = this.table;
            this.modalTitle = `Rename ${this.table}`;
            this.modal = 'rename';
        },

        async rename() {
            await this.run(async () => {
                const name = this.renameTo.trim();
                await this.api('table', { query: { db: this.db }, body: { tables: [this.table], operation: 'rename', name } });
                this.modal = null;
                this.notify(`Renamed to ${name}.`);
                this.table = name;
                this.structure = null;
                await this.loadTables();
                await this.setTab(this.tab);
            }, 'rename');
        },

        // ---- Export ---------------------------------------------------------

        openExport(tables) {
            this.exportOptions = { tables: [...tables], structure: true, data: true, gzip: false };
            this.modalTitle = tables.length === 1 ? `Export ${tables[0]}` : `Export ${this.db}`;
            this.modal = 'export';
        },

        submitExport() {
            this.download({
                format: 'sql',
                db: this.db,
                tables: JSON.stringify(this.exportOptions.tables),
                structure: this.exportOptions.structure ? '1' : '',
                data: this.exportOptions.data ? '1' : '',
                gzip: this.exportOptions.gzip ? '1' : '',
            });
            this.modal = null;
        },

        exportCsv() {
            this.download({
                format: 'csv',
                db: this.db,
                table: this.table,
                filters: JSON.stringify(this.appliedFilters),
                q: this.appliedSearch,
            });
        },

        exportQueryCsv() {
            this.download({ format: 'csv', db: this.db, sql: this.lastSql });
        },

        /**
         * Downloads are a plain form post: the browser streams the file to
         * disk itself, however large it is.
         */
        download(fields) {
            const form = document.createElement('form');
            form.method = 'post';
            form.action = 'export.php';
            form.hidden = true;

            Object.entries({ csrf: this.csrf, ...fields }).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value ?? '';
                form.appendChild(input);
            });

            document.body.appendChild(form);
            form.submit();
            form.remove();
            this.notify('The download will start in a moment.');
        },

        // ---- Import ---------------------------------------------------------

        openImport() {
            this.importJob = null;
            this.modalTitle = `Import into ${this.db}`;
            this.modal = 'import';
        },

        async importRequest(action, { id = null, offset = null, json = null, body = null } = {}) {
            const params = new URLSearchParams({ action });

            if (id !== null) params.set('id', id);
            if (offset !== null) params.set('offset', String(offset));

            const headers = { Accept: 'application/json', 'X-CSRF-Token': this.csrf };

            if (json !== null) {
                headers['Content-Type'] = 'application/json';
            }

            this.pending++;

            try {
                const response = await fetch('import.php?' + params.toString(), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers,
                    body: json !== null ? JSON.stringify(json) : body,
                });

                return await this.unwrap(response);
            } finally {
                this.pending--;
            }
        },

        async startImport() {
            const file = this.$refs.importFile && this.$refs.importFile.files[0];

            if (!file) {
                return;
            }

            this.importCancelled = false;

            await this.importing(async () => {
                let job = await this.importRequest('start', { json: { name: file.name, size: file.size, db: this.db } });
                this.importJob = job;

                while (job.received < job.size && !this.importCancelled) {
                    const chunk = file.slice(job.received, job.received + IMPORT_CHUNK);
                    job = await this.importRequest('chunk', { id: job.id, offset: job.received, body: chunk });
                    this.importJob = job;
                }

                await this.runImport(job);
            });
        },

        async skipImport() {
            await this.importing(async () => {
                const job = await this.importRequest('skip', { id: this.importJob.id });
                this.importJob = job;
                await this.runImport(job);
            });
        },

        async runImport(job) {
            while (['ready', 'running'].includes(job.state) && !this.importCancelled) {
                job = await this.importRequest('run', { id: job.id });

                if (!this.importCancelled) {
                    this.importJob = job;
                }

                if (job.busy) {
                    await sleep(1000);
                }
            }

            if (job.state === 'done' && !this.importCancelled) {
                this.notify(`Import finished: ${job.statements.toLocaleString()} statements.`);
            }
        },

        /**
         * Run an import step, turning a failed request into a failed job
         * rather than a lost one, and refreshing what it may have changed.
         */
        async importing(step) {
            try {
                await step();
            } catch (error) {
                if (!this.importCancelled) {
                    this.importJob = { ...(this.importJob || { name: '', statements: 0 }), state: 'failed', canSkip: false, error: { line: '?', message: error.message, sql: '' } };
                }
            } finally {
                this.loadTables();
            }
        },

        async cancelImport() {
            this.importCancelled = true;
            const job = this.importJob;
            this.importJob = { ...job, state: 'cancelled' };

            if (job && job.id) {
                try {
                    await this.importRequest('cancel', { id: job.id });
                } catch {
                    // Already finished or gone: there is nothing left to stop.
                }
            }
        },

        /**
         * Close the dialog. A failed import that is not going to be carried
         * on is removed now rather than waiting on the server for a day.
         */
        async closeImport() {
            const job = this.importJob;
            this.importJob = null;
            this.modal = null;

            if (job && job.id && job.state === 'failed') {
                this.importCancelled = true;

                try {
                    await this.importRequest('cancel', { id: job.id });
                } catch {
                    // Gone already.
                }
            }
        },

        importPercent() {
            const job = this.importJob;

            if (!job) return 0;
            if (job.state === 'done') return 100;
            if (job.state === 'uploading') return job.size ? Math.floor((job.received / job.size) * 100) : 0;

            return job.sqlSize ? Math.floor((job.offset / job.sqlSize) * 100) : 0;
        },

        importStatus() {
            const job = this.importJob;
            const statements = `${(job.statements || 0).toLocaleString()} statement${job.statements === 1 ? '' : 's'}`;

            return {
                uploading: `Uploading… ${this.bytes(job.received)} of ${this.bytes(job.size)}`,
                ready: 'Preparing…',
                running: `Running… ${statements} so far` + (job.sqlSize ? `, ${this.bytes(job.offset)} of ${this.bytes(job.sqlSize)}` : ''),
                done: `Finished: ${statements} ran.`,
                failed: `Stopped at an error after ${statements}. Those statements stay applied.`,
                cancelled: `Stopped after ${statements}. Those statements stay applied.`,
            }[job.state] || '';
        },

        // ---- Modals and notices --------------------------------------------

        closeModal() {
            if (this.modalLocked()) {
                return;
            }

            this.modal = null;
        },

        /**
         * A dialog whose work is running cannot be walked away from; the
         * import has a Stop button of its own for that.
         */
        modalLocked() {
            return this.action === 'danger'
                || (this.modal === 'import' && this.importJob !== null && ['uploading', 'ready', 'running'].includes(this.importJob.state));
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
