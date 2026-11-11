<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>NVR Control Center</title>
    <link rel="stylesheet" href="{{ asset('css/nvr-dashboard.css') }}">
    <script>
        window.NVR_CONFIG = {
            dataUrl: @json(route('dashboard.data')),
            devicesUrl: @json(url('/devices')),
            importUrl: @json(route('devices.import')),
            templateUrl: @json(route('devices.import-template')),
            checkAllUrl: @json(route('devices.check-all'))
        };
    </script>
    <script src="{{ asset('js/nvr-dashboard.js') }}" defer></script>
</head>
<body data-view="{{ $inventoryPage ? 'inventory' : 'overview' }}">
    <div class="app-shell">
        <main class="main-content">
            <header class="topbar">
            <a class="brand" href="{{ route('dashboard') }}">
                <span class="brand-mark">N</span>
                <span><strong>Net<span>Work</span></strong><small>MONITORING CENTER</small></span>
            </a>
                <nav class="primary-nav" id="primary-nav" aria-label="Main navigation">
                    <a class="nav-link {{ $inventoryPage ? '' : 'active' }}" href="{{ route('dashboard') }}">Overview</a>
                    <a class="nav-link {{ $inventoryPage ? 'active' : '' }}" href="{{ route('devices.inventory') }}">▤ Device inventory <span class="nav-count" id="device-count">0</span></a>
                </nav>
                <div class="topbar-actions">
                    <span class="sync-label"><span class="pulse-dot"></span><span id="last-updated">Connecting…</span></span>
                    <button class="button button-quiet" id="refresh-button" type="button"><span class="refresh-icon">↻</span> Refresh</button>
                </div>
            </header>

            @unless($inventoryPage)
            <section class="page-heading">
                <div>
                    <div class="eyebrow">NETWORK OPERATIONS</div>
                    <h1>Overview</h1>
                    <p>A clear view of every recorder across your sites.</p>
                </div>
                <div class="heading-actions">
                    <button class="button button-outline" id="import-button" type="button">Import CSV</button>
                    <a class="template-link" href="{{ route('devices.import-template') }}">Download template</a>
                    <button class="button button-primary" id="add-device-button" type="button"><span>＋</span> Add NVR</button>
                    <input type="file" id="import-file" accept=".csv,text/csv" hidden>
                </div>
            </section>
            @endunless

            @unless($inventoryPage)
            <section class="summary-grid" aria-label="NVR status summary">
                <article class="summary-card">
                    <div class="summary-top"><span class="summary-label">Total recorders</span><span class="summary-icon icon-total">▤</span></div>
                    <div class="summary-value" id="stat-total">—</div>
                    <div class="summary-caption">Registered in your inventory</div>
                </article>
                <article class="summary-card">
                    <div class="summary-top"><span class="summary-label">Online</span><span class="summary-icon icon-online">↗</span></div>
                    <div class="summary-value" id="stat-online">—</div>
                    <div class="summary-caption"><span class="status-text-online">Responding on configured port</span></div>
                </article>
                <article class="summary-card">
                    <div class="summary-top"><span class="summary-label">Offline</span><span class="summary-icon icon-offline">↘</span></div>
                    <div class="summary-value" id="stat-offline">—</div>
                    <div class="summary-caption">No response on configured port</div>
                </article>
                <article class="summary-card">
                    <div class="summary-top"><span class="summary-label">Not checked</span><span class="summary-icon icon-unknown">◷</span></div>
                    <div class="summary-value" id="stat-unknown">—</div>
                    <div class="summary-caption">Enabled recorders awaiting their first check</div>
                </article>
            </section>
            @endunless

            <section class="inventory-card" id="device-inventory">
                <div class="inventory-heading">
                    <div>
                        <div class="inventory-title-row"><h2>{{ $inventoryPage ? 'All recorders' : 'Device inventory' }}</h2><span class="live-badge"><span class="pulse-dot"></span> LIVE</span></div>
                        <p>Health checks run every 5 seconds. This view refreshes automatically.</p>
                    </div>
                    <div class="inventory-heading-actions">
                        @if($inventoryPage)
                        <button class="button button-outline" id="import-button" type="button">Import CSV</button>
                        <a class="template-link" href="{{ route('devices.import-template') }}">Download template</a>
                        <button class="button button-primary" id="add-device-button" type="button"><span>＋</span> Add NVR</button>
                        <input type="file" id="import-file" accept=".csv,text/csv" hidden>
                        @endif
                        <button class="button button-quiet check-all-button" id="check-all-button" type="button">Check all now <span>→</span></button>
                    </div>
                </div>
                <div class="table-toolbar">
                    <label class="search-box"><span>⌕</span><input id="search-input" type="search" placeholder="Search name, IP, site..." autocomplete="off"></label>
                    <label class="filter-label">Status
                        <select id="status-filter">
                            <option value="">All devices</option>
                            <option value="online">Online</option>
                            <option value="offline">Offline</option>
                            <option value="unknown">Not checked</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </label>
                </div>
                @if($inventoryPage)
                <div class="device-grid" id="device-grid" aria-live="polite">
                    <div class="grid-empty">Loading your NVR inventory…</div>
                </div>
                @else
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>RECORDER</th>
                            <th>SITE</th>
                            <th>STATUS</th>
                            <th>PING</th>
                            <th>LAST CHECKED</th>
                            <th></th></tr></thead>
                        <tbody id="device-rows"><tr><td class="table-empty" colspan="6">Loading your NVR inventory…</td></tr></tbody>
                    </table>
                </div>
                @endif
                <div class="table-footer">
                    <span id="page-summary">Loading devices…</span>
                    <div class="pagination-controls">
                        <button class="button button-page" id="previous-page" type="button">← Previous</button>
                        <span id="page-number">1 / 1</span>
                        <button class="button button-page" id="next-page" type="button">Next →</button>
                    </div>
                </div>
            </section>
        </main>
    </div>

    <dialog class="device-dialog" id="device-dialog">
        <form id="device-form">
            <div class="dialog-heading">
                <div><div class="eyebrow">DEVICE INVENTORY</div><h2 id="dialog-title">Add an NVR</h2></div>
                <button class="icon-button" id="close-dialog" type="button" aria-label="Close">×</button>
            </div>
            <p class="dialog-description">Online status is based on pinging the IP. The TCP port is saved for reference and does not affect the ping check.</p>
            <div class="form-grid">
                <label class="form-field full-width">Recorder name <span>*</span><input name="name" maxlength="120" placeholder="e.g. Main Gate NVR" required></label>
                <label class="form-field">IP address <span>*</span><input name="ip" inputmode="decimal" placeholder="192.168.1.201" required></label>
                <label class="form-field">TCP port (info only) <span>*</span><input name="port" type="number" min="1" max="65535" value="80" required></label>
                <label class="form-field">Site / location<input name="site" maxlength="120" placeholder="Main office"></label>
                <label class="form-field">Brand / vendor<input name="vendor" maxlength="120" placeholder="Optional"></label>
                <label class="form-field">Camera channels<input name="channels" type="number" min="1" max="256" placeholder="Optional"></label>
                <label class="form-field">Monitoring<select name="enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></label>
            </div>
            <div class="form-errors" id="form-errors" role="alert"></div>
            <div class="dialog-actions"><button class="button button-outline" id="cancel-dialog" type="button">Cancel</button><button class="button button-primary" id="save-device" type="submit">Save NVR</button></div>
        </form>
    </dialog>
    <div class="toast" id="toast" role="status" aria-live="polite"></div>
</body>
</html>
