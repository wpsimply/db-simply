<?php

declare(strict_types=1);

use DbSimply\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

db_simply_headers();
header('Content-Type: text/html; charset=utf-8');

$session = new Session($config);
$grant = $session->grant();
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = (string) $config->get('title');
$version = trim((string) @file_get_contents(dirname(__DIR__).'/VERSION')) ?: 'dev';
$asset = static fn (string $path): string => $path.'?v='.rawurlencode($version);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($grant !== null ? $grant['label'].' · '.$title : $title) ?></title>
    <link rel="icon" href="<?= $e($asset('assets/icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($asset('assets/app.css')) ?>">
<?php if ($grant !== null) { ?>
    <meta name="csrf-token" content="<?= $e($session->csrf()) ?>">
    <script src="<?= $e($asset('assets/app.js')) ?>" defer></script>
    <script src="<?= $e($asset('assets/vendor/alpine.min.js')) ?>" defer></script>
<?php } ?>
</head>
<body>
<?php if ($grant === null) { ?>
    <main class="signed-out">
        <div class="card">
            <h1><?= $e($title) ?></h1>
            <p>You are not signed in, or your session has ended.</p>
            <p class="muted">Open <?= $e($title) ?> again from your control panel to start a new session.</p>
            <?php if (is_string($config->get('panel_url')) && $config->get('panel_url') !== '') { ?>
                <a class="button primary" href="<?= $e($config->get('panel_url')) ?>" rel="noopener">Go to the control panel</a>
            <?php } ?>
        </div>
    </main>
<?php } else { ?>
<div class="app" x-data="dbSimply" x-cloak x-effect="syncUrl()" @keydown.window="shortcut($event)">
    <header class="topbar">
        <div class="brand">
            <img src="<?= $e($asset('assets/icon.svg')) ?>" alt="" width="22" height="22">
            <strong><?= $e($title) ?></strong>
            <span class="target" x-text="session.label"></span>
            <div class="menu db-picker" x-data="dbPicker" @click.outside="close()" @keydown.escape.stop="close(true)" x-show="databases.length">
                <button type="button" class="db-crumb" x-ref="trigger" @click="toggle()" :disabled="busy" aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'" title="Change database">
                    <span x-text="db || 'Choose a database'"></span>
                    <span class="caret" aria-hidden="true">▾</span>
                </button>
                <div class="menu-items db-menu" x-show="open" x-transition.opacity>
                    <input type="search" x-ref="filter" x-model="query" @input="active = 0" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="choose(matches()[active])"
                        placeholder="Filter databases" aria-label="Filter databases" autocomplete="off" spellcheck="false">
                    <ul role="listbox" x-ref="list" aria-label="Databases">
                        <template x-for="(database, i) in matches()" :key="database.name">
                            <li role="option" :aria-selected="database.name === db ? 'true' : 'false'" :class="{ active: i === active, current: database.name === db }"
                                @click="choose(database)" @mousemove="active = i" x-text="database.name"></li>
                        </template>
                    </ul>
                    <p class="empty" x-show="matches().length === 0">No databases match.</p>
                </div>
            </div>
            <span class="pill" x-show="session.readonly">Read-only</span>
        </div>
        <div class="topbar-actions">
            <button type="button" class="button ghost" @click="openProcesses()" :disabled="busy" title="Your queries running on the server">Processes</button>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" :value="csrf">
                <button type="submit" class="button ghost" :disabled="busy">Sign out</button>
            </form>
        </div>
    </header>

    <main class="layout" :class="{ 'has-detail': table || tab === 'sql' }">
        <aside class="sidebar">
            <form class="search" @submit.prevent>
                <input type="search" x-ref="tableFilter" x-model="tableFilter" placeholder="Filter tables" aria-label="Filter tables" autocomplete="off" spellcheck="false">
            </form>

            <div class="toolbar">
                <button type="button" class="button small" :class="{ active: !table && tab === 'tables' }" @click="openOverview()" :disabled="!db">Overview</button>
                <button type="button" class="button small" :class="{ active: !table && tab === 'sql' }" @click="openSql(null)" :disabled="!db">SQL</button>
                <button type="button" class="button small" @click="loadTables()" :disabled="busy || !db" :class="{ 'is-loading': tablesLoading }" title="Reload">Refresh</button>
            </div>

            <div class="list-status">
                <span x-text="tablesStatus()"></span>
            </div>

            <ul class="tables" aria-label="Tables" :aria-busy="tablesLoading ? 'true' : 'false'">
                <template x-for="item in filteredTables()" :key="item.name">
                    <li :class="{ active: table === item.name }">
                        <button type="button" class="table-row" @click="openTable(item.name)" :title="item.name">
                            <span class="badge" x-show="item.view">view</span>
                            <span class="table-name" x-text="item.name"></span>
                            <span class="table-rows" x-show="!item.view" x-text="rowsShort(item)"></span>
                        </button>
                    </li>
                </template>
            </ul>

            <div class="list-footer">
                <p class="empty" x-show="!tablesLoading && db && filteredTables().length === 0" x-text="tables.length ? 'No tables match.' : 'This database has no tables.'"></p>
                <p class="empty" x-show="!db && sessionReady">You have no databases yet.</p>
                <span class="spinner" x-show="tablesLoading" aria-label="Loading"></span>
            </div>
        </aside>

        <section class="detail" aria-live="polite">
            <div class="detail-head" x-show="db">
                <button type="button" class="button ghost small back" @click="closeTable()">← Tables</button>
                <h2 class="crumbs">
                    <button type="button" class="link crumb" @click="openOverview()" x-text="db"></button>
                    <template x-if="table">
                        <span><span class="sep">/</span><span class="crumb current" x-text="table"></span></span>
                    </template>
                </h2>
                <div class="meta" x-show="table && currentTable()">
                    <template x-if="currentTable()">
                        <span class="meta-items">
                            <span x-show="currentTable().view">View</span>
                            <span x-show="!currentTable().view" x-text="rowsLong(currentTable())"></span>
                            <span x-show="!currentTable().view" x-text="bytes(currentTable().dataLength + currentTable().indexLength)"></span>
                            <span x-text="currentTable().engine"></span>
                            <span x-text="currentTable().collation"></span>
                        </span>
                    </template>
                </div>
                <div class="head-row" x-show="table">
                    <div class="tabs" role="tablist">
                        <button type="button" role="tab" :aria-selected="tab === 'browse'" @click="setTab('browse')">Browse</button>
                        <button type="button" role="tab" :aria-selected="tab === 'structure'" @click="setTab('structure')">Structure</button>
                        <button type="button" role="tab" :aria-selected="tab === 'sql'" @click="setTab('sql')">SQL</button>
                    </div>
                    <span class="spacer"></span>
                    <button type="button" class="button small primary" x-show="canWrite() && !isView()" @click="openInsert()" :disabled="busy">Insert row</button>
                    <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" class="button small" @click="open = !open" :disabled="busy">Export</button>
                        <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                            <button type="button" @click="openExport([table])">SQL dump of this table…</button>
                            <button type="button" @click="exportCsv()" x-text="appliedFilters.length || appliedSearch ? 'CSV of the matching rows' : 'CSV of all rows'"></button>
                        </div>
                    </div>
                    <div class="menu" x-data="{ open: false }" @click.outside="open = false" x-show="canWrite()">
                        <button type="button" class="button small" @click="open = !open" :disabled="busy">Operations</button>
                        <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                            <button type="button" @click="openRename()">Rename…</button>
                            <template x-if="!isView()">
                                <div>
                                    <hr>
                                    <button type="button" @click="maintain([table], 'optimize')">Optimize</button>
                                    <button type="button" @click="maintain([table], 'analyze')">Analyze</button>
                                    <button type="button" @click="maintain([table], 'check')">Check</button>
                                    <button type="button" @click="maintain([table], 'repair')">Repair</button>
                                    <hr>
                                    <button type="button" class="danger" @click="confirmTableOperation([table], 'empty')">Empty (delete all rows)…</button>
                                    <button type="button" class="danger" @click="confirmTableOperation([table], 'truncate')">Truncate…</button>
                                </div>
                            </template>
                            <hr>
                            <button type="button" class="danger" @click="confirmTableOperation([table], 'drop')" x-text="isView() ? 'Drop view…' : 'Drop table…'"></button>
                        </div>
                    </div>
                </div>
                <div class="head-row" x-show="!table">
                    <div class="tabs" role="tablist">
                        <button type="button" role="tab" :aria-selected="tab === 'tables'" @click="openOverview()">Tables</button>
                        <button type="button" role="tab" :aria-selected="tab === 'objects'" @click="openObjects()">Routines &amp; events</button>
                        <button type="button" role="tab" :aria-selected="tab === 'search'" @click="openSearch()">Search</button>
                        <button type="button" role="tab" :aria-selected="tab === 'sql'" @click="openSql(null)">SQL</button>
                    </div>
                </div>
                <div class="head-row" x-show="!table && tab === 'tables'">
                    <span class="muted small" x-text="tableSelection.length ? tableSelection.length + ' selected' : 'Select tables to act on several at once.'"></span>
                    <span class="spacer"></span>
                    <button type="button" class="button small" @click="openCreateTable()" x-show="canWrite()" :disabled="busy">New table…</button>
                    <button type="button" class="button small" @click="openImport()" x-show="canWrite()" :disabled="busy">Import…</button>
                    <button type="button" class="button small" @click="openExport(tableSelection)" :disabled="busy || tables.length === 0" x-text="tableSelection.length ? 'Export selected…' : 'Export…'"></button>
                    <div class="menu" x-data="{ open: false }" @click.outside="open = false" x-show="canWrite()">
                        <button type="button" class="button small" @click="open = !open" :disabled="busy || tableSelection.length === 0">With selected</button>
                        <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                            <button type="button" @click="maintain(tableSelection, 'optimize')">Optimize</button>
                            <button type="button" @click="maintain(tableSelection, 'analyze')">Analyze</button>
                            <button type="button" @click="maintain(tableSelection, 'check')">Check</button>
                            <button type="button" @click="maintain(tableSelection, 'repair')">Repair</button>
                            <hr>
                            <button type="button" class="danger" @click="confirmTableOperation(tableSelection, 'empty')">Empty…</button>
                            <button type="button" class="danger" @click="confirmTableOperation(tableSelection, 'drop')">Drop…</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="placeholder" x-show="!db && sessionReady">
                <p>There is no database to show.</p>
                <p class="muted small">Create one from your control panel, then open DB Simply again.</p>
            </div>

            <!-- Overview -->
            <div x-show="db && !table && tab === 'tables'">
                <div class="table-wrap">
                    <table class="grid">
                        <thead>
                            <tr>
                                <th class="check-col"><input type="checkbox" :checked="tables.length > 0 && tableSelection.length === filteredTables().length" @change="tableSelection = $event.target.checked ? filteredTables().map((t) => t.name) : []" aria-label="Select all tables"></th>
                                <th>Table</th>
                                <th class="num">Rows</th>
                                <th class="num">Data</th>
                                <th class="num">Index</th>
                                <th class="num">Overhead</th>
                                <th>Engine</th>
                                <th>Collation</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="item in filteredTables()" :key="item.name">
                                <tr class="clickable" :class="{ selected: tableSelection.includes(item.name) }" @click="openTable(item.name)">
                                    <td class="check-col" @click.stop><input type="checkbox" :value="item.name" x-model="tableSelection" :aria-label="'Select ' + item.name"></td>
                                    <td><button type="button" class="link" x-text="item.name"></button> <span class="badge" x-show="item.view">view</span></td>
                                    <td class="num" x-text="item.view || item.rows === null ? '' : (item.rowsExact ? '' : '~') + item.rows.toLocaleString()"></td>
                                    <td class="num" x-text="item.view ? '' : bytes(item.dataLength)"></td>
                                    <td class="num" x-text="item.view ? '' : bytes(item.indexLength)"></td>
                                    <td class="num" x-text="item.dataFree ? bytes(item.dataFree) : ''"></td>
                                    <td x-text="item.engine || ''"></td>
                                    <td x-text="item.collation || ''"></td>
                                    <td class="nowrap" x-text="item.updated || ''"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot x-show="tables.length">
                            <tr>
                                <th></th>
                                <th x-text="tables.length + ' tables'"></th>
                                <th class="num" x-text="'~' + totalRows().toLocaleString()"></th>
                                <th class="num" x-text="bytes(totalOf('dataLength'))"></th>
                                <th class="num" x-text="bytes(totalOf('indexLength'))"></th>
                                <th class="num" x-text="totalOf('dataFree') ? bytes(totalOf('dataFree')) : ''"></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Browse -->
            <div x-show="table && tab === 'browse'" class="browse">
                <form class="browse-bar" @submit.prevent="applySearch()">
                    <input type="search" x-model="search" placeholder="Search this table" aria-label="Search this table" autocomplete="off" spellcheck="false">
                    <button type="button" class="button small" @click="filterOpen = !filterOpen" :class="{ active: appliedFilters.length }" x-text="appliedFilters.length ? 'Filters (' + appliedFilters.length + ')' : 'Filter'"></button>
                    <button type="button" class="button small" @click="loadRows()" :disabled="busy" :class="{ 'is-loading': rowsLoading }">Refresh</button>
                </form>

                <div class="filters" x-show="filterOpen" x-transition>
                    <template x-for="(filter, i) in filters" :key="i">
                        <div class="filter-row">
                            <select x-model="filter.column" aria-label="Column">
                                <template x-for="col in browse.columns" :key="col.name">
                                    <option :value="col.name" x-text="col.name" :selected="col.name === filter.column"></option>
                                </template>
                            </select>
                            <select x-model="filter.operator" aria-label="Operator">
                                <template x-for="operator in session.operators" :key="operator">
                                    <option :value="operator" x-text="operator" :selected="operator === filter.operator"></option>
                                </template>
                            </select>
                            <input type="text" x-model="filter.value" x-show="!['is null', 'is not null', 'is empty', 'is not empty'].includes(filter.operator)" :placeholder="['in', 'not in'].includes(filter.operator) ? 'a, b, c' : 'value'" aria-label="Value" spellcheck="false" @keydown.enter.prevent="applyFilters()">
                            <button type="button" class="link danger" @click="filters.splice(i, 1)" aria-label="Remove condition">✕</button>
                        </div>
                    </template>
                    <div class="filter-actions">
                        <button type="button" class="link" @click="addFilter()">+ Add condition</button>
                        <span class="spacer"></span>
                        <button type="button" class="button small" @click="clearFilters()" :disabled="busy">Clear</button>
                        <button type="button" class="button small primary" @click="applyFilters()" :disabled="busy">Apply</button>
                    </div>
                </div>

                <p class="notice" x-show="browse.error" x-text="browse.error"></p>

                <div class="selection" x-show="selectedRows.length" x-transition>
                    <span x-text="selectedRows.length + ' selected'"></span>
                    <button type="button" class="link danger" @click="confirmDeleteRows()" :disabled="busy">Delete</button>
                    <button type="button" class="link" @click="selectedRows = []">Clear</button>
                </div>

                <div class="table-wrap grid-wrap" :class="{ 'is-loading': rowsLoading }" x-show="browse.columns.length">
                    <table class="grid data">
                        <thead>
                            <tr>
                                <th class="row-actions" x-show="rowsEditable()">
                                    <input type="checkbox" :checked="browse.rows.length > 0 && selectedRows.length === browse.rows.length" @change="selectedRows = $event.target.checked ? browse.rows.map((_, i) => i) : []" aria-label="Select all rows on this page">
                                </th>
                                <template x-for="col in browse.columns" :key="col.name">
                                    <th :class="{ num: col.numeric }">
                                        <button type="button" class="sort" @click="toggleSort(col.name)" :title="col.type">
                                            <span x-text="col.name"></span>
                                            <span class="key-mark" x-show="col.key" title="Identifies the row">key</span>
                                            <span class="sort-mark" x-text="sort === col.name ? (dir === 'desc' ? '▼' : '▲') : ''"></span>
                                        </button>
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, r) in browse.rows" :key="browse.offset + ':' + r">
                                <tr :class="{ selected: selectedRows.includes(r) }">
                                    <td class="row-actions" x-show="rowsEditable()">
                                        <input type="checkbox" :value="r" x-model.number="selectedRows" aria-label="Select row">
                                        <button type="button" class="link" @click="openEditor(r)" :disabled="busy">Edit</button>
                                    </td>
                                    <template x-for="(cell, c) in row" :key="c">
                                        <td :class="cellClass(cell, browse.columns[c])" @click="rowsEditable() ? openEditor(r, browse.columns[c].name) : openCell(r, c)" x-text="cellText(cell)"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="empty" x-show="!rowsLoading && browse.rows.length === 0">No rows.</p>
                </div>

                <div class="pager" x-show="browse.columns.length">
                    <span class="muted small" x-text="pageLabel()"></span>
                    <span class="spacer"></span>
                    <label class="small page-size">Rows
                        <select x-model.number="limit" @change="offset = 0; loadRows()" :disabled="busy">
                            <template x-for="size in [25, 50, 100, 250, 500]" :key="size">
                                <option :value="size" x-text="size" :selected="size === limit"></option>
                            </template>
                        </select>
                    </label>
                    <button type="button" class="button small" @click="page(-1)" :disabled="busy || offset === 0">Previous</button>
                    <button type="button" class="button small" @click="page(1)" :disabled="busy || !browse.hasMore">Next</button>
                </div>
            </div>

            <!-- Routines & events -->
            <div x-show="db && !table && tab === 'objects'" class="structure objects">
                <p class="loading-line" x-show="objectsLoading && !objects"><span class="spinner small"></span> Loading…</p>
                <template x-if="objects">
                    <div>
                        <div class="head-row objects-bar" x-show="canWrite()">
                            <span class="muted small">Definitions open in the SQL editor to be changed and run again.</span>
                            <span class="spacer"></span>
                            <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                                <button type="button" class="button small primary" @click="open = !open" :disabled="busy">New…</button>
                                <div class="menu-items right" x-show="open" x-transition.opacity @click="open = false">
                                    <template x-for="kind in ['view', 'procedure', 'function', 'trigger', 'event']" :key="kind">
                                        <button type="button" @click="newObject(kind)" x-text="kind.charAt(0).toUpperCase() + kind.slice(1)"></button>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <template x-for="group in objectGroups()" :key="group.type">
                            <div>
                                <h3 x-text="group.title"></h3>
                                <p class="muted small" x-show="group.items.length === 0" x-text="'No ' + group.title.toLowerCase() + '.'"></p>
                                <div class="table-wrap" x-show="group.items.length">
                                    <table class="grid">
                                        <tbody>
                                            <template x-for="item in group.items" :key="item.name">
                                                <tr>
                                                    <td class="mono strong" x-text="item.name"></td>
                                                    <td class="small muted" x-text="objectSummary(group.type, item)"></td>
                                                    <td class="nowrap actions-cell">
                                                        <button type="button" class="link" @click="showDefinition(group.type, item.name)" :disabled="busy">Show</button>
                                                        <button type="button" class="link" x-show="canWrite()" @click="editDefinition(group.type, item.name)" :disabled="busy">Edit in SQL</button>
                                                        <button type="button" class="link danger" x-show="canWrite()" @click="confirmDropObject(group.type, item.name)" :disabled="busy">Drop</button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <!-- Search -->
            <div x-show="db && !table && tab === 'search'" class="search-db">
                <div class="tabs mode-tabs" role="tablist" x-show="canWrite()">
                    <button type="button" role="tab" :aria-selected="searchMode === 'find'" @click="searchMode = 'find'">Find</button>
                    <button type="button" role="tab" :aria-selected="searchMode === 'replace'" @click="searchMode = 'replace'">Find and replace</button>
                </div>

                <template x-if="searchMode === 'find'">
                    <div>
                <form class="browse-bar" @submit.prevent="searchDatabase()">
                    <input type="search" x-ref="databaseSearch" x-model="dbSearch.term" placeholder="Find a value in every table, e.g. an old domain" aria-label="Search the database" autocomplete="off" spellcheck="false">
                    <button type="submit" class="button small primary" :disabled="busy || !dbSearch.term.trim()" :class="{ 'is-loading': action === 'search' }">Search</button>
                </form>
                <p class="muted small">Text columns are searched for the value anywhere in them, number columns for the exact number.</p>
                <template x-if="dbSearch.result">
                    <div>
                        <p class="small" x-text="searchSummary()"></p>
                        <div class="table-wrap" x-show="dbSearch.result.results.length">
                            <table class="grid">
                                <thead><tr><th>Table</th><th class="num">Matching rows</th></tr></thead>
                                <tbody>
                                    <template x-for="hit in dbSearch.result.results" :key="hit.table">
                                        <tr class="clickable" @click="openSearchHit(hit.table)">
                                            <td><button type="button" class="link" x-text="hit.table"></button></td>
                                            <td class="num" x-text="hit.matches.toLocaleString()"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <p class="notice small" x-show="dbSearch.result.skipped.length" x-text="'Time ran out before these tables were searched: ' + dbSearch.result.skipped.join(', ') + '. Search them from their own Browse tab.'"></p>
                    </div>
                </template>
                    </div>
                </template>

                <template x-if="searchMode === 'replace'">
                    <div class="replace">
                        <form class="replace-form" @submit.prevent="previewReplace()">
                            <label class="stack">Find <input type="text" x-model="replacer.search" placeholder="https://old.example.com" autocomplete="off" spellcheck="false" :disabled="replacer.running"></label>
                            <label class="stack">Replace with <input type="text" x-model="replacer.replace" placeholder="https://www.new-example.com" autocomplete="off" spellcheck="false" :disabled="replacer.running"></label>
                            <button type="submit" class="button primary" :disabled="busy || !replacer.search" :class="{ 'is-loading': action === 'replace-preview' }">Preview</button>
                        </form>
                        <p class="muted small">Matches exactly, capitals included, in every text column. Inside serialized PHP values (WordPress options, meta and widgets) the text is replaced without breaking them.</p>

                        <template x-if="replacer.preview">
                            <div>
                                <p class="small" x-text="replaceSummary()"></p>
                                <template x-for="hit in replacer.preview.tables" :key="hit.table">
                                    <div class="replace-table">
                                        <div class="replace-head">
                                            <strong class="mono" x-text="hit.table"></strong>
                                            <span class="muted small" x-text="hit.rows.toLocaleString() + ' row' + (hit.rows === 1 ? '' : 's')"></span>
                                            <span class="pill warn" x-show="!hit.editable">No row key: left alone</span>
                                            <span class="muted small" x-show="replacer.changed[hit.table] !== undefined" x-text="'· ' + (replacer.changed[hit.table] || 0).toLocaleString() + ' changed'"></span>
                                        </div>
                                        <template x-for="(sample, i) in hit.samples" :key="i">
                                            <div class="sample">
                                                <span class="muted small mono" x-text="sample.column + (sample.serialized ? ' · serialized' : '')"></span>
                                                <code class="before" x-text="sample.before"></code>
                                                <code class="after" x-text="sample.after"></code>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <p class="notice small" x-show="replacer.preview.skipped.length" x-text="'Time ran out before these tables were checked: ' + replacer.preview.skipped.join(', ') + '. They are still included when you replace.'"></p>
                                <div class="replace-actions" x-show="replacer.preview.tables.length || replacer.preview.skipped.length">
                                    <button type="button" class="button" @click="openExport([])" :disabled="replacer.running">Download a backup first…</button>
                                    <span class="spacer"></span>
                                    <span class="muted small" x-show="replacer.running" x-text="'Replacing… ' + replacedTotal().toLocaleString() + ' rows changed so far'"></span>
                                    <span class="muted small" x-show="replacer.done" x-text="'Done: ' + replacedTotal().toLocaleString() + ' rows changed.'"></span>
                                    <button type="button" class="button danger" x-show="replacer.running" @click="replacer.stop = true">Stop</button>
                                    <button type="button" class="button danger-solid" x-show="!replacer.running && !replacer.done" @click="confirmReplace()" :disabled="busy">Replace everywhere</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <!-- Structure -->
            <div x-show="table && tab === 'structure'" class="structure" :aria-busy="structureLoading ? 'true' : 'false'">
                <p class="loading-line" x-show="structureLoading && !structure"><span class="spinner small"></span> Loading structure…</p>
                <template x-if="structure">
                    <div>
                        <h3 class="with-action">
                            <span>Columns</span>
                            <span class="actions" x-show="structureEditable()">
                                <button type="button" class="button small" @click="openTableOptions()" :disabled="busy">Table options…</button>
                                <button type="button" class="button small primary" @click="openColumn(null)" :disabled="busy">Add column</button>
                            </span>
                        </h3>
                        <div class="table-wrap">
                            <table class="grid">
                                <thead><tr><th class="num">#</th><th x-show="structureEditable()"><span class="sr-only">Actions</span></th><th>Name</th><th>Type</th><th>Null</th><th>Default</th><th>Key</th><th>Extra</th><th>Collation</th><th>Comment</th></tr></thead>
                                <tbody>
                                    <template x-for="(col, i) in structure.columns" :key="col.name">
                                        <tr>
                                            <td class="num" x-text="i + 1"></td>
                                            <td class="nowrap" x-show="structureEditable()">
                                                <button type="button" class="link" @click="openColumn(col.name)" :disabled="busy">Edit</button>
                                                <button type="button" class="link danger" @click="reviewSchema({ operation: 'drop-column', name: col.name }, 'Drop column ' + col.name, true)" :disabled="busy || structure.columns.length === 1">Drop</button>
                                            </td>
                                            <td class="mono strong" x-text="col.name"></td>
                                            <td class="mono" x-text="col.type"></td>
                                            <td x-text="col.nullable ? 'Yes' : 'No'"></td>
                                            <td class="mono" :class="{ muted: col.default === null }" x-text="col.default === null ? (col.nullable ? 'NULL' : '—') : col.default"></td>
                                            <td x-text="{ PRI: 'Primary', UNI: 'Unique', MUL: 'Index' }[col.key] || ''"></td>
                                            <td class="small" x-text="col.extra"></td>
                                            <td class="small" x-text="col.collation || ''"></td>
                                            <td class="small" x-text="col.comment"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <h3 class="with-action">
                            <span>Indexes</span>
                            <span class="actions" x-show="structureEditable()">
                                <button type="button" class="button small" @click="openIndex()" :disabled="busy">Add index</button>
                            </span>
                        </h3>
                        <p class="muted small" x-show="structure.indexes.length === 0">No indexes. Without a primary or unique key, rows of this table cannot be edited one by one.</p>
                        <div class="table-wrap" x-show="structure.indexes.length">
                            <table class="grid">
                                <thead><tr><th>Name</th><th>Kind</th><th>Columns</th><th>Type</th><th x-show="structureEditable()"><span class="sr-only">Actions</span></th></tr></thead>
                                <tbody>
                                    <template x-for="index in structure.indexes" :key="index.name">
                                        <tr>
                                            <td class="mono strong" x-text="index.name"></td>
                                            <td x-text="index.primary ? 'Primary' : (index.unique ? 'Unique' : 'Index')"></td>
                                            <td class="mono" x-text="index.columns.map((c) => c.name + (c.subPart ? '(' + c.subPart + ')' : '')).join(', ')"></td>
                                            <td x-text="index.type"></td>
                                            <td class="nowrap" x-show="structureEditable()">
                                                <button type="button" class="link danger" @click="reviewSchema({ operation: 'drop-index', name: index.name }, 'Drop index ' + index.name, true)" :disabled="busy">Drop</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <template x-if="structure.foreignKeys.length || structureEditable()">
                            <div>
                                <h3 class="with-action">
                                    <span>Foreign keys</span>
                                    <span class="actions" x-show="structureEditable()">
                                        <button type="button" class="button small" @click="openForeignKey()" :disabled="busy">Add foreign key</button>
                                    </span>
                                </h3>
                                <p class="muted small" x-show="structure.foreignKeys.length === 0">No foreign keys.</p>
                                <div class="table-wrap" x-show="structure.foreignKeys.length">
                                    <table class="grid">
                                        <thead><tr><th>Name</th><th>Columns</th><th>References</th><th>On update</th><th>On delete</th><th x-show="structureEditable()"><span class="sr-only">Actions</span></th></tr></thead>
                                        <tbody>
                                            <template x-for="key in structure.foreignKeys" :key="key.name">
                                                <tr>
                                                    <td class="mono strong" x-text="key.name"></td>
                                                    <td class="mono" x-text="key.columns.join(', ')"></td>
                                                    <td class="mono" x-text="(key.referencedDatabase !== db ? key.referencedDatabase + '.' : '') + key.referencedTable + ' (' + key.referencedColumns.join(', ') + ')'"></td>
                                                    <td x-text="key.onUpdate"></td>
                                                    <td x-text="key.onDelete"></td>
                                                    <td class="nowrap" x-show="structureEditable()">
                                                        <button type="button" class="link danger" @click="reviewSchema({ operation: 'drop-foreign-key', name: key.name }, 'Drop foreign key ' + key.name, true)" :disabled="busy">Drop</button>
                                                    </td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>

                        <template x-if="structure.triggers.length">
                            <div>
                                <h3>Triggers</h3>
                                <div class="table-wrap">
                                    <table class="grid">
                                        <thead><tr><th>Name</th><th>When</th><th>Statement</th></tr></thead>
                                        <tbody>
                                            <template x-for="trigger in structure.triggers" :key="trigger.name">
                                                <tr>
                                                    <td class="mono strong" x-text="trigger.name"></td>
                                                    <td class="nowrap" x-text="trigger.timing + ' ' + trigger.event"></td>
                                                    <td class="mono pre" x-text="trigger.statement"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </template>

                        <h3 class="with-action">
                            <span x-text="structure.table.view ? 'CREATE VIEW' : 'CREATE TABLE'"></span>
                            <button type="button" class="button small" @click="copy(structure.create)">Copy</button>
                        </h3>
                        <pre class="code" x-text="structure.create"></pre>
                    </div>
                </template>
            </div>

            <!-- SQL -->
            <div x-show="db && tab === 'sql'" class="sql">
                <div class="sql-editor-wrap">
                    <pre class="sql-highlight" x-ref="sqlHighlight" aria-hidden="true"></pre>
                    <textarea class="sql-editor" x-ref="sqlEditor" x-model="sql" spellcheck="false" autocomplete="off" aria-label="SQL"
                              placeholder="SELECT * FROM …" @keydown="sqlKeydown($event)" @scroll="syncSqlScroll()" x-effect="paintSql(sql)"></textarea>
                </div>
                <div class="sql-bar">
                    <button type="button" class="button primary" @click="runSql(false)" :disabled="busy || !sql.trim()" :class="{ 'is-loading': action === 'sql' }">Run</button>
                    <button type="button" class="button" @click="explainSql()" :disabled="busy || !sql.trim()">Explain</button>
                    <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                        <button type="button" class="button" @click="open = !open" :disabled="history.length === 0">History</button>
                        <div class="menu-items wide" x-show="open" x-transition.opacity @click="open = false">
                            <template x-for="(entry, i) in history" :key="i">
                                <button type="button" class="mono" @click="sql = entry" x-text="entry.length > 120 ? entry.slice(0, 120) + '…' : entry"></button>
                            </template>
                        </div>
                    </div>
                    <span class="muted small hint">Run with <kbd x-text="isMac ? '⌘' : 'Ctrl'"></kbd> <kbd>Enter</kbd></span>
                </div>

                <template x-for="result in sqlResults" :key="result.index">
                    <div class="result" :class="{ failed: result.error }">
                        <div class="result-head">
                            <span class="badge" x-text="'#' + (result.index + 1)"></span>
                            <code class="result-sql" x-text="result.sql"></code>
                            <span class="muted small nowrap" x-text="'line ' + result.line + (result.ms !== undefined ? ' · ' + result.ms + ' ms' : '')"></span>
                            <button type="button" class="button small" x-show="sqlResults.length === 1 && result.sets && result.sets.length === 1 && result.sets[0].columns" @click="exportQueryCsv()">Export CSV</button>
                        </div>
                        <p class="error-text" x-show="result.error" x-text="result.error"></p>
                        <template x-for="(set, s) in (result.sets || [])" :key="s">
                            <div class="result-set">
                                <template x-if="!set.columns">
                                    <p class="muted small" x-text="affectedLabel(set)"></p>
                                </template>
                                <template x-if="set.columns">
                                    <div>
                                        <div class="table-wrap grid-wrap">
                                            <table class="grid data">
                                                <thead><tr><template x-for="(col, c) in set.columns" :key="c"><th :class="{ num: col.numeric }" :title="col.type" x-text="col.name"></th></template></tr></thead>
                                                <tbody>
                                                    <template x-for="(row, r) in set.rows" :key="r">
                                                        <tr><template x-for="(cell, c) in row" :key="c"><td :class="cellClass(cell, set.columns[c])" @click="showCell(cell, set.columns[c].name)" x-text="cellText(cell)"></td></template></tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                        <p class="muted small" x-text="rowCountLabel(set)"></p>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <ul class="warnings" x-show="result.warnings && result.warnings.length">
                            <template x-for="(warning, w) in (result.warnings || [])" :key="w">
                                <li x-text="warning.level + ' ' + warning.code + ': ' + warning.message"></li>
                            </template>
                        </ul>
                    </div>
                </template>
                <p class="notice" x-show="sqlStopped()" x-text="sqlStopped()"></p>
            </div>
        </section>
    </main>

    <!-- Modal -->
    <div class="modal-backdrop" x-show="modal" x-transition.opacity @click.self="closeModal()" @keydown.escape.window="closeModal()">
        <div class="modal" :class="{ wide: ['cell', 'row', 'create-table', 'review', 'definition', 'processes'].includes(modal) }" role="dialog" aria-modal="true" :aria-label="modalTitle" x-show="modal">
            <header>
                <h3 x-text="modalTitle"></h3>
                <button type="button" class="link" @click="closeModal()" :disabled="modalLocked()" aria-label="Close">✕</button>
            </header>

            <!-- Cell -->
            <div x-show="modal === 'cell'">
                <p class="loading-line" x-show="cell.loading"><span class="spinner small"></span> Loading the full value…</p>
                <div class="value-bar" x-show="!cell.loading">
                    <div class="tabs" role="tablist">
                        <button type="button" role="tab" :aria-selected="cell.view === 'raw'" @click="cell.view = 'raw'">Value</button>
                        <button type="button" role="tab" x-show="cell.pretty" :aria-selected="cell.view === 'pretty'" @click="cell.view = 'pretty'" x-text="cell.format === 'serialized' ? 'PHP (decoded)' : 'JSON (formatted)'"></button>
                    </div>
                    <span class="format" x-text="cellMeta()"></span>
                    <button type="button" class="button small" @click="copy(cell.view === 'pretty' ? cell.pretty : cellFullText(cell.value))" x-show="cell.value !== null">Copy</button>
                </div>
                <p class="notice" x-show="!cell.loading && cellTruncated(cell.value)">This value is longer than can be shown here, and is cut off.</p>
                <pre class="code value" x-show="!cell.loading" :class="{ muted: cell.value === null }" x-text="cell.view === 'pretty' ? cell.pretty : cellFullText(cell.value)"></pre>
            </div>

            <!-- Confirm -->
            <div x-show="modal === 'confirm'">
                <p>These statements throw data away. Nothing has run yet.</p>
                <ul class="confirm-list">
                    <template x-for="item in confirmation" :key="item.index">
                        <li>
                            <strong x-text="'Line ' + item.line + ' ' + item.reason"></strong>
                            <code x-text="item.sql"></code>
                        </li>
                    </template>
                </ul>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="button" class="button danger-solid" @click="closeModal(); runSql(true)">Run anyway</button>
                </footer>
            </div>

            <!-- Row editor -->
            <form x-show="modal === 'row'" @submit.prevent="saveRow(false)" class="row-editor">
                <p class="loading-line" x-show="editor.loading"><span class="spinner small"></span> Loading the row…</p>
                <template x-for="field in editor.fields" :key="field.name">
                    <div class="field" :class="{ generated: field.generated }">
                        <div class="field-head">
                            <label class="field-name mono" :for="'field-' + field.name" x-text="field.name"></label>
                            <span class="field-type mono" x-text="field.type"></span>
                            <span class="spacer"></span>
                            <span class="muted small" x-show="field.generated">generated</span>
                            <select class="field-mode" x-show="!field.generated && !editor.locked[field.name] && fieldModes(field).length > 1" x-model="editor.modes[field.name]" :aria-label="'How to set ' + field.name">
                                <template x-for="mode in fieldModes(field)" :key="mode">
                                    <option :value="mode" x-text="{ value: 'Value', null: 'NULL', default: 'Default' }[mode]" :selected="editor.modes[field.name] === mode"></option>
                                </template>
                            </select>
                        </div>
                        <template x-if="field.options && field.dataType === 'enum'">
                            <select :id="'field-' + field.name" x-model="editor.texts[field.name]" x-show="editor.modes[field.name] === 'value'" :disabled="field.generated || editor.locked[field.name]">
                                <template x-for="option in field.options" :key="option">
                                    <option :value="option" x-text="option" :selected="editor.texts[field.name] === option"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="!(field.options && field.dataType === 'enum')">
                            <textarea :id="'field-' + field.name" x-model="editor.texts[field.name]" x-show="editor.modes[field.name] === 'value'"
                                      :rows="field.long || field.binary ? 5 : 1" :class="{ single: !field.long && !field.binary }"
                                      :readonly="field.generated || editor.locked[field.name]" spellcheck="false"
                                      :placeholder="field.binary ? 'base64' : ''"></textarea>
                        </template>
                        <p class="muted small field-note" x-show="editor.modes[field.name] === 'null'">NULL</p>
                        <p class="muted small field-note" x-show="editor.modes[field.name] === 'default'" x-text="field.autoIncrement ? 'Next number (auto increment)' : 'Default' + (field.default !== null ? ': ' + field.default : '')"></p>
                        <p class="muted small field-note" x-show="field.binary && editor.modes[field.name] === 'value' && !editor.locked[field.name]">Binary data, edited as base64.</p>
                        <p class="notice small" x-show="editor.locked[field.name]">This value is too long to edit here, and is left as it is.</p>
                    </div>
                </template>
                <footer class="spread" x-show="!editor.loading">
                    <button type="button" class="button danger" x-show="editor.mode === 'edit'" @click="confirmDeleteRow()" :disabled="busy">Delete</button>
                    <span class="spacer"></span>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="button" class="button" x-show="editor.mode === 'edit'" @click="saveRow(true)" :disabled="busy" :class="{ 'is-loading': action === 'duplicate' }">Save as new row</button>
                    <button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'save' }" x-text="editor.mode === 'edit' ? 'Save' : 'Insert'"></button>
                </footer>
            </form>

            <!-- Column -->
            <form x-show="modal === 'column'" @submit.prevent="submitColumn()" class="design-form">
                <p class="loading-line" x-show="designLoading"><span class="spinner small"></span> Loading…</p>
                <template x-if="!designLoading && column">
                    <div class="design-grid">
                        <label class="stack">Name <input type="text" x-model="column.name" required spellcheck="false" maxlength="64"></label>
                        <label class="stack">Type
                            <select x-model="column.type" @change="column.length = defaultLength(column.type)">
                                <template x-for="group in typeGroups" :key="group[0]">
                                    <optgroup :label="group[0]">
                                        <template x-for="type in group[1]" :key="type"><option :value="type" x-text="type" :selected="column.type === type"></option></template>
                                    </optgroup>
                                </template>
                            </select>
                        </label>
                        <label class="stack" x-show="typeTakesLength(column.type)"><span x-text="['DECIMAL', 'FLOAT', 'DOUBLE'].includes(column.type) ? 'Precision, scale' : (['DATETIME', 'TIMESTAMP', 'TIME'].includes(column.type) ? 'Fraction digits' : 'Length')"></span>
                            <input type="text" x-model="column.length" inputmode="numeric" spellcheck="false" :placeholder="column.type === 'DECIMAL' ? '10,2' : ''">
                        </label>
                        <label class="stack wide" x-show="['ENUM', 'SET'].includes(column.type)">Allowed values, one per line
                            <textarea rows="4" x-model="column.valuesText" spellcheck="false"></textarea>
                        </label>
                        <label class="stack">Default
                            <select x-model="column.default.kind">
                                <option value="none">None</option>
                                <option value="null" x-show="column.nullable">NULL</option>
                                <option value="value">A value</option>
                                <option value="current_timestamp" x-show="['DATETIME', 'TIMESTAMP'].includes(column.type)">The current time</option>
                            </select>
                        </label>
                        <label class="stack" x-show="column.default.kind === 'value'">Default value <input type="text" x-model="column.default.value" spellcheck="false"></label>
                        <label class="stack" x-show="typeIsText(column.type)">Collation
                            <input type="text" x-model="column.collation" list="collation-list" spellcheck="false" placeholder="Table default">
                        </label>
                        <label class="stack" x-show="columnPositions().length">Position
                            <select x-model="column.position">
                                <template x-for="option in columnPositions()" :key="option[0]"><option :value="option[0]" x-text="option[1]" :selected="column.position === option[0]"></option></template>
                            </select>
                        </label>
                        <label class="stack wide">Comment <input type="text" x-model="column.comment" maxlength="1024"></label>
                        <div class="checks wide">
                            <label class="check"><input type="checkbox" x-model="column.nullable" @change="if (!column.nullable && column.default.kind === 'null') column.default.kind = 'none'"> Allow NULL</label>
                            <label class="check" x-show="['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT', 'DECIMAL', 'FLOAT', 'DOUBLE'].includes(column.type)"><input type="checkbox" x-model="column.unsigned"> Unsigned</label>
                            <label class="check" x-show="['TINYINT', 'SMALLINT', 'MEDIUMINT', 'INT', 'BIGINT'].includes(column.type)"><input type="checkbox" x-model="column.autoIncrement"> Auto increment</label>
                            <label class="check" x-show="['DATETIME', 'TIMESTAMP'].includes(column.type)"><input type="checkbox" x-model="column.onUpdateCurrentTimestamp"> Set to the current time on every update</label>
                        </div>
                        <p class="notice small wide" x-show="column.original">Changing a column's type rewrites the whole table and can cut values that no longer fit. Review the statement before you run it.</p>
                    </div>
                </template>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy || designLoading" :class="{ 'is-loading': action === 'preview' }">Review SQL</button>
                </footer>
            </form>

            <!-- Index -->
            <form x-show="modal === 'index'" @submit.prevent="submitIndex()" class="design-form">
                <div class="design-grid">
                    <label class="stack">Kind
                        <select x-model="indexForm.kind">
                            <option value="index">Index</option>
                            <option value="unique">Unique</option>
                            <option value="primary" x-show="!structure || !structure.indexes.some((i) => i.primary)">Primary key</option>
                            <option value="fulltext">Full text</option>
                        </select>
                    </label>
                    <label class="stack" x-show="indexForm.kind !== 'primary'">Name <input type="text" x-model="indexForm.name" spellcheck="false" maxlength="64" placeholder="Chosen by the server"></label>
                </div>
                <fieldset>
                    <legend>Columns, in order</legend>
                    <template x-for="(part, i) in indexForm.columns" :key="i">
                        <div class="index-part">
                            <select x-model="part.name" aria-label="Column">
                                <template x-for="name in (structure ? structure.columns.map((c) => c.name) : [])" :key="name"><option :value="name" x-text="name" :selected="part.name === name"></option></template>
                            </select>
                            <input type="text" x-model="part.length" inputmode="numeric" placeholder="Prefix length" aria-label="Prefix length">
                            <button type="button" class="link danger" @click="indexForm.columns.splice(i, 1)" x-show="indexForm.columns.length > 1" aria-label="Remove column">✕</button>
                        </div>
                    </template>
                    <button type="button" class="link add-part" @click="indexForm.columns.push({ name: structure.columns[0].name, length: '' })">+ Add column</button>
                </fieldset>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'preview' }">Review SQL</button>
                </footer>
            </form>

            <!-- Table options -->
            <form x-show="modal === 'options'" @submit.prevent="submitTableOptions()" class="design-form">
                <div class="design-grid">
                    <label class="stack">Engine
                        <select x-model="optionsForm.engine">
                            <template x-for="engine in ['InnoDB', 'MyISAM', 'Aria']" :key="engine"><option :value="engine" x-text="engine" :selected="optionsForm.engine === engine"></option></template>
                        </select>
                    </label>
                    <label class="stack">Collation <input type="text" x-model="optionsForm.collation" list="collation-list" spellcheck="false"></label>
                    <label class="stack wide">Comment <input type="text" x-model="optionsForm.comment" maxlength="2048"></label>
                    <label class="check wide"><input type="checkbox" x-model="optionsForm.convert"> Convert every text column to the new collation</label>
                </div>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'preview' }">Review SQL</button>
                </footer>
            </form>

            <!-- New table -->
            <form x-show="modal === 'create-table'" @submit.prevent="submitCreateTable()" class="design-form">
                <div class="design-grid">
                    <label class="stack">Table name <input type="text" x-model="newTable.name" required spellcheck="false" maxlength="64"></label>
                    <label class="stack">Engine
                        <select x-model="newTable.engine">
                            <template x-for="engine in ['InnoDB', 'MyISAM', 'Aria']" :key="engine"><option :value="engine" x-text="engine" :selected="newTable.engine === engine"></option></template>
                        </select>
                    </label>
                    <label class="stack">Collation <input type="text" x-model="newTable.collation" list="collation-list" spellcheck="false" placeholder="Database default"></label>
                </div>
                <div class="table-wrap">
                    <table class="grid new-columns">
                        <thead><tr><th>Name</th><th>Type</th><th>Length</th><th>Default</th><th title="Allow NULL">Null</th><th title="Auto increment">Auto inc.</th><th title="Primary key">Key</th><th></th></tr></thead>
                        <tbody>
                            <template x-for="(col, i) in newTable.columns" :key="i">
                                <tr>
                                    <td><input type="text" x-model="col.name" spellcheck="false" aria-label="Column name" maxlength="64"></td>
                                    <td>
                                        <select x-model="col.type" aria-label="Type" @change="col.length = defaultLength(col.type)">
                                            <template x-for="group in typeGroups" :key="group[0]">
                                                <optgroup :label="group[0]"><template x-for="type in group[1]" :key="type"><option :value="type" x-text="type" :selected="col.type === type"></option></template></optgroup>
                                            </template>
                                        </select>
                                    </td>
                                    <td><input type="text" x-model="col.length" :placeholder="['ENUM', 'SET'].includes(col.type) ? 'a, b, c' : ''" aria-label="Length or values" spellcheck="false"></td>
                                    <td><input type="text" x-model="col.defaultText" placeholder="none" aria-label="Default" spellcheck="false"></td>
                                    <td class="center"><input type="checkbox" x-model="col.nullable" aria-label="Allow NULL"></td>
                                    <td class="center"><input type="checkbox" x-model="col.autoIncrement" aria-label="Auto increment"></td>
                                    <td class="center"><input type="checkbox" x-model="col.primary" aria-label="Primary key"></td>
                                    <td><button type="button" class="link danger" @click="newTable.columns.splice(i, 1)" x-show="newTable.columns.length > 1" aria-label="Remove column">✕</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="muted small">Default: leave empty for none, write <code>NULL</code> or <code>CURRENT_TIMESTAMP</code>, or any value. For ENUM and SET, list the values separated by commas.</p>
                <footer class="spread">
                    <button type="button" class="link" @click="newTable.columns.push(blankColumn())">+ Add column</button>
                    <span class="spacer"></span>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'preview' }">Review SQL</button>
                </footer>
            </form>

            <!-- Review a structure change -->
            <div x-show="modal === 'review'">
                <p class="small" x-text="review.destructive ? 'This throws data away and cannot be undone. Nothing has run yet.' : 'This is the statement that will run. Nothing has run yet.'"></p>
                <pre class="code" x-text="review.sql"></pre>
                <footer>
                    <button type="button" class="button" @click="backFromReview()" :disabled="action === 'schema'" x-text="review.back ? 'Back' : 'Cancel'"></button>
                    <button type="button" class="button" :class="[review.destructive ? 'danger-solid' : 'primary', action === 'schema' ? 'is-loading' : '']" @click="runSchema()" :disabled="busy">Run</button>
                </footer>
            </div>

            <!-- Object definition -->
            <div x-show="modal === 'definition'">
                <p class="notice small" x-show="definition.sql === null">The server does not show this definition to you.</p>
                <pre class="code" x-show="definition.sql !== null" x-text="definition.sql"></pre>
                <footer>
                    <button type="button" class="button" @click="copy(definition.sql)" x-show="definition.sql !== null">Copy</button>
                    <button type="button" class="button primary" x-show="canWrite() && definition.sql !== null" @click="editDefinition(definition.type, definition.name, definition.sql)">Edit in SQL</button>
                </footer>
            </div>

            <!-- Processes -->
            <div x-show="modal === 'processes'">
                <p class="muted small">Your connections to the database server, other than the one showing this list.</p>
                <p class="empty" x-show="!processesLoading && processes.length === 0">Nothing else is running.</p>
                <div class="table-wrap" x-show="processes.length">
                    <table class="grid">
                        <thead><tr><th class="num">Id</th><th>Database</th><th>Command</th><th class="num">Time</th><th>Query</th><th></th></tr></thead>
                        <tbody>
                            <template x-for="process in processes" :key="process.id">
                                <tr>
                                    <td class="num" x-text="process.id"></td>
                                    <td class="mono" x-text="process.database || ''"></td>
                                    <td x-text="process.command + (process.state ? ' · ' + process.state : '')"></td>
                                    <td class="num" x-text="process.seconds + ' s'"></td>
                                    <td class="mono pre process-query" x-text="process.query || ''"></td>
                                    <td class="nowrap">
                                        <button type="button" class="link danger" x-show="process.query" @click="killProcess(process.id, false)" :disabled="busy">Stop query</button>
                                        <button type="button" class="link danger" @click="killProcess(process.id, true)" :disabled="busy">Disconnect</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <footer>
                    <button type="button" class="button" @click="loadProcesses()" :disabled="busy" :class="{ 'is-loading': processesLoading }">Refresh</button>
                    <button type="button" class="button primary" @click="closeModal()">Close</button>
                </footer>
            </div>

            <!-- Foreign key -->
            <form x-show="modal === 'foreign-key'" @submit.prevent="submitForeignKey()" class="design-form">
                <div class="design-grid">
                    <label class="stack">Points to table
                        <select x-model="foreignKey.referencedTable" @change="loadReferencedColumns()">
                            <template x-for="item in tables.filter((t) => !t.view)" :key="item.name"><option :value="item.name" x-text="item.name" :selected="foreignKey.referencedTable === item.name"></option></template>
                        </select>
                    </label>
                    <label class="stack">Name <input type="text" x-model="foreignKey.name" spellcheck="false" maxlength="64" placeholder="Chosen by the server"></label>
                    <label class="stack">When the row it points to is deleted
                        <select x-model="foreignKey.onDelete"><template x-for="action in referentialActions" :key="action"><option :value="action" x-text="action" :selected="foreignKey.onDelete === action"></option></template></select>
                    </label>
                    <label class="stack">When its key changes
                        <select x-model="foreignKey.onUpdate"><template x-for="action in referentialActions" :key="action"><option :value="action" x-text="action" :selected="foreignKey.onUpdate === action"></option></template></select>
                    </label>
                </div>
                <fieldset>
                    <legend>Columns</legend>
                    <template x-for="(pair, i) in foreignKey.pairs" :key="i">
                        <div class="index-part fk-pair">
                            <select x-model="pair.column" aria-label="Column in this table">
                                <template x-for="name in (structure ? structure.columns.map((c) => c.name) : [])" :key="name"><option :value="name" x-text="name" :selected="pair.column === name"></option></template>
                            </select>
                            <span class="muted">→</span>
                            <select x-model="pair.referenced" aria-label="Column it points to">
                                <template x-for="name in foreignKey.referencedColumns" :key="name"><option :value="name" x-text="name" :selected="pair.referenced === name"></option></template>
                            </select>
                            <button type="button" class="link danger" @click="foreignKey.pairs.splice(i, 1)" x-show="foreignKey.pairs.length > 1" aria-label="Remove pair">✕</button>
                        </div>
                    </template>
                    <button type="button" class="link add-part" @click="foreignKey.pairs.push({ column: structure.columns[0].name, referenced: foreignKey.referencedColumns[0] || '' })">+ Add column pair</button>
                </fieldset>
                <p class="muted small">Both columns need the same type, and the column pointed to needs an index. InnoDB tables only.</p>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy || foreignKey.referencedColumns.length === 0" :class="{ 'is-loading': action === 'preview' }">Review SQL</button>
                </footer>
            </form>

            <!-- Danger -->
            <div x-show="modal === 'danger'">
                <p x-text="danger.message"></p>
                <label x-show="danger.phrase" class="stack"><span>Type <code x-text="danger.phrase"></code> to confirm</span>
                    <input type="text" x-model="danger.typed" autocomplete="off" spellcheck="false" @keydown.enter.prevent="runDanger()">
                </label>
                <footer>
                    <button type="button" class="button" @click="closeModal()" :disabled="action === 'danger'">Cancel</button>
                    <button type="button" class="button danger-solid" @click="runDanger()" :disabled="busy || (Boolean(danger.phrase) && danger.typed !== danger.phrase)" :class="{ 'is-loading': action === 'danger' }" x-text="danger.label"></button>
                </footer>
            </div>

            <!-- Rename -->
            <form x-show="modal === 'rename'" @submit.prevent="rename()">
                <label class="stack">New name <input type="text" x-model="renameTo" required spellcheck="false" maxlength="64"></label>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="busy || !renameTo.trim() || renameTo.trim() === table" :class="{ 'is-loading': action === 'rename' }">Rename</button>
                </footer>
            </form>

            <!-- Maintenance results -->
            <div x-show="modal === 'messages'">
                <div class="table-wrap">
                    <table class="grid">
                        <thead><tr><th>Table</th><th>Type</th><th>Message</th></tr></thead>
                        <tbody>
                            <template x-for="(message, i) in messages" :key="i">
                                <tr><td class="mono" x-text="message.table"></td><td x-text="message.type"></td><td x-text="message.text"></td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <footer><button type="button" class="button" @click="closeModal()">Close</button></footer>
            </div>

            <!-- Export -->
            <form x-show="modal === 'export'" @submit.prevent="submitExport()">
                <p class="muted small" x-text="exportOptions.tables.length ? 'Tables: ' + exportOptions.tables.join(', ') : 'Every table and view in ' + db + '.'"></p>
                <fieldset>
                    <legend>Include</legend>
                    <label class="check"><input type="checkbox" x-model="exportOptions.structure"> Structure (CREATE TABLE, views, triggers)</label>
                    <label class="check"><input type="checkbox" x-model="exportOptions.data"> Data (INSERT)</label>
                </fieldset>
                <label class="check"><input type="checkbox" x-model="exportOptions.gzip"> Compress (.sql.gz)</label>
                <footer>
                    <button type="button" class="button" @click="closeModal()">Cancel</button>
                    <button type="submit" class="button primary" :disabled="!exportOptions.structure && !exportOptions.data">Download</button>
                </footer>
            </form>

            <!-- Import -->
            <div x-show="modal === 'import'">
                <template x-if="!importJob">
                    <form @submit.prevent="startImport()" class="stack-form">
                        <p class="muted small">Run an <code>.sql</code> or <code>.sql.gz</code> file in <strong x-text="db"></strong>. Statements run in order; tables the file drops or replaces are replaced.</p>
                        <input type="file" x-ref="importFile" accept=".sql,.gz,application/sql,application/gzip" required>
                        <p class="muted small" x-text="'Up to ' + bytes(session.import ? session.import.maxBytes : 0) + '.'"></p>
                        <footer>
                            <button type="button" class="button" @click="closeModal()">Cancel</button>
                            <button type="submit" class="button primary">Import</button>
                        </footer>
                    </form>
                </template>
                <template x-if="importJob">
                    <div class="stack-form">
                        <p><strong x-text="importJob.name"></strong></p>
                        <div class="progress-bar" :class="{ failed: importJob.state === 'failed' }"><span :style="{ width: importPercent() + '%' }"></span></div>
                        <p class="muted small" x-text="importStatus()"></p>
                        <template x-if="importJob.state === 'failed' && importJob.error">
                            <div class="result failed">
                                <p class="error-text" x-text="'Line ' + importJob.error.line + ': ' + importJob.error.message"></p>
                                <code class="failed-sql" x-text="importJob.error.sql"></code>
                            </div>
                        </template>
                        <footer>
                            <button type="button" class="button danger" x-show="!['done', 'failed', 'cancelled'].includes(importJob.state)" @click="cancelImport()">Stop</button>
                            <button type="button" class="button" x-show="importJob.state === 'failed' && importJob.canSkip" @click="skipImport()">Skip this statement and continue</button>
                            <button type="button" class="button primary" x-show="['done', 'failed', 'cancelled'].includes(importJob.state)" @click="closeImport()">Close</button>
                        </footer>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <datalist id="collation-list">
        <template x-for="name in collations" :key="name"><option :value="name"></option></template>
    </datalist>

    <div class="toasts" aria-live="assertive">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="toast" :class="toast.kind" x-text="toast.message" @click="dismiss(toast.id)"></div>
        </template>
    </div>

    <div class="session-ended" x-show="signedOut">
        <div class="card">
            <h3>Your session has ended</h3>
            <p>Open <?= $e($title) ?> again from your control panel to continue.</p>
        </div>
    </div>
</div>
<?php } ?>
</body>
</html>
