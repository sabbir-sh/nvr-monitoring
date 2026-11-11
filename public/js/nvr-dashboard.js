(() => {
    const config = window.NVR_CONFIG;
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const rows = document.getElementById('device-rows');
    const dialog = document.getElementById('device-dialog');
    const form = document.getElementById('device-form');
    const errors = document.getElementById('form-errors');
    const toast = document.getElementById('toast');
    let page = 1;
    let lastPage = 1;
    let deviceCache = new Map();
    let toastTimer;
    let searchTimer;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    })[character]);

    function showToast(message, isError = false) {
        toast.textContent = message;
        toast.classList.toggle('error', isError);
        toast.classList.add('visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => toast.classList.remove('visible'), 3200);
    }

    function setSummary(summary) {
        document.getElementById('stat-total').textContent = summary.total;
        document.getElementById('stat-online').textContent = summary.online;
        document.getElementById('stat-offline').textContent = summary.offline;
        document.getElementById('stat-unknown').textContent = summary.unknown;
        document.getElementById('sidebar-device-count').textContent = summary.total;
    }

    function checkedTime(value) {
        if (!value) return '<span class="last-checked never">Never</span>';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '<span class="last-checked never">Unknown</span>';
        return `<span class="last-checked" title="${escapeHtml(date.toLocaleString())}">${escapeHtml(date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }))}</span>`;
    }

    function deviceRow(device) {
        const status = device.enabled ? device.status : 'disabled';
        const statusLabel = { online: 'Online', offline: 'Offline', unknown: 'Not checked', disabled: 'Disabled' }[status] || 'Not checked';
        const response = device.latency_ms === null ? '—' : `${escapeHtml(device.latency_ms)} ms`;
        const errorTitle = device.last_error ? ` title="${escapeHtml(device.last_error)}"` : '';

        return `<tr>
            <td><span class="device-name" title="${escapeHtml(device.name)}">${escapeHtml(device.name)}</span><span class="device-address">${escapeHtml(device.ip)}</span></td>
            <td class="site-cell">${escapeHtml(device.site || '—')}<span class="vendor-subtitle">${escapeHtml(device.vendor || 'NVR')}</span></td>
            <td><span class="port-code">TCP :${escapeHtml(device.port)}</span></td>
            <td><span class="status-pill status-${escapeHtml(status)}"${errorTitle}>${statusLabel}</span></td>
            <td><span class="latency-value">${response}</span></td>
            <td>${checkedTime(device.last_checked_at)}</td>
            <td><div class="row-actions">
                <button class="row-action" type="button" data-action="check" data-id="${device.id}" title="Check now" aria-label="Check ${escapeHtml(device.name)}"${device.enabled ? '' : ' disabled'}>↻</button>
                <button class="row-action" type="button" data-action="edit" data-id="${device.id}" title="Edit" aria-label="Edit ${escapeHtml(device.name)}">✎</button>
                <button class="row-action delete" type="button" data-action="delete" data-id="${device.id}" title="Remove" aria-label="Remove ${escapeHtml(device.name)}">×</button>
            </div></td>
        </tr>`;
    }

    async function refresh() {
        const parameters = new URLSearchParams({
            page: String(page),
            search: document.getElementById('search-input').value.trim(),
            status: document.getElementById('status-filter').value
        });

        try {
            const response = await fetch(`${config.dataUrl}?${parameters}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('Could not load dashboard data.');
            const result = await response.json();

            setSummary(result.summary);
            deviceCache = new Map(result.devices.map((device) => [String(device.id), device]));
            lastPage = Math.max(1, result.pagination.last_page);
            if (page > lastPage) {
                page = lastPage;
                return refresh();
            }

            rows.innerHTML = result.devices.length
                ? result.devices.map(deviceRow).join('')
                : '<tr><td class="table-empty" colspan="7">No NVRs found. Add a device or import your CSV list.</td></tr>';

            const first = result.pagination.total ? ((page - 1) * 50) + 1 : 0;
            const last = Math.min(page * 50, result.pagination.total);
            document.getElementById('page-summary').textContent = `Showing ${first}–${last} of ${result.pagination.total} devices`;
            document.getElementById('page-number').textContent = `${page} / ${lastPage}`;
            document.getElementById('previous-page').disabled = page <= 1;
            document.getElementById('next-page').disabled = page >= lastPage;
            document.getElementById('last-updated').textContent = `Updated ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
        } catch (error) {
            showToast(error.message || 'Unable to reach the local server.', true);
            if (!rows.children.length) rows.innerHTML = '<tr><td class="table-empty" colspan="7">Dashboard data could not be loaded.</td></tr>';
        }
    }

    async function request(url, method = 'POST', body) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf };
        if (body && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
        const response = await fetch(url, { method, headers, body });
        const result = response.status === 204 ? {} : await response.json();
        if (!response.ok) {
            const messages = result.errors ? Object.values(result.errors).flat() : [result.message || 'The request could not be completed.'];
            throw new Error(messages.join(' '));
        }
        return result;
    }

    function openDialog(device = null) {
        form.reset();
        errors.textContent = '';
        errors.classList.remove('visible');
        document.getElementById('dialog-title').textContent = device ? 'Edit NVR' : 'Add an NVR';
        document.getElementById('save-device').textContent = device ? 'Save changes' : 'Save NVR';
        form.elements.name.value = device?.name || '';
        form.elements.ip.value = device?.ip || '';
        form.elements.port.value = device?.port || 80;
        form.elements.site.value = device?.site || '';
        form.elements.vendor.value = device?.vendor || '';
        form.elements.channels.value = device?.channels || '';
        form.elements.enabled.value = device?.enabled === false ? '0' : '1';
        form.dataset.deviceId = device?.id || '';
        dialog.showModal();
    }

    document.getElementById('add-device-button').addEventListener('click', () => openDialog());
    document.getElementById('close-dialog').addEventListener('click', () => dialog.close());
    document.getElementById('cancel-dialog').addEventListener('click', () => dialog.close());
    document.getElementById('refresh-button').addEventListener('click', refresh);
    document.getElementById('previous-page').addEventListener('click', () => { if (page > 1) { page--; refresh(); } });
    document.getElementById('next-page').addEventListener('click', () => { if (page < lastPage) { page++; refresh(); } });

    document.getElementById('search-input').addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => { page = 1; refresh(); }, 280);
    });
    document.getElementById('status-filter').addEventListener('change', () => { page = 1; refresh(); });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        errors.textContent = '';
        errors.classList.remove('visible');
        const values = Object.fromEntries(new FormData(form).entries());
        const deviceId = form.dataset.deviceId;
        const url = deviceId ? `${config.devicesUrl}/${deviceId}` : config.devicesUrl;
        const method = deviceId ? 'PUT' : 'POST';
        document.getElementById('save-device').disabled = true;

        try {
            const result = await request(url, method, JSON.stringify(values));
            dialog.close();
            showToast(result.message);
            page = 1;
            await refresh();
        } catch (error) {
            errors.textContent = error.message;
            errors.classList.add('visible');
        } finally {
            document.getElementById('save-device').disabled = false;
        }
    });

    rows.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        const id = button.dataset.id;
        const row = button.closest('tr');
        const deviceName = row.querySelector('.device-name').textContent;

        try {
            if (button.dataset.action === 'check') {
                const result = await request(`${config.devicesUrl}/${id}/check`);
                showToast(result.message);
            } else if (button.dataset.action === 'edit') {
                const device = deviceCache.get(id);
                if (!device) throw new Error('Could not load this NVR. Refresh the dashboard and try again.');
                openDialog(device);
            } else if (button.dataset.action === 'delete' && window.confirm(`Remove "${deviceName}" from the inventory?`)) {
                const result = await request(`${config.devicesUrl}/${id}`, 'DELETE');
                showToast(result.message || 'NVR removed successfully.');
                await refresh();
            }
        } catch (error) {
            showToast(error.message, true);
        }
    });

    document.getElementById('check-all-button').addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const result = await request(config.checkAllUrl);
            showToast(result.message);
        } catch (error) {
            showToast(error.message, true);
        } finally {
            button.disabled = false;
        }
    });

    document.getElementById('import-button').addEventListener('click', () => document.getElementById('import-file').click());
    document.getElementById('import-file').addEventListener('change', async (event) => {
        const file = event.target.files[0];
        if (!file) return;
        const body = new FormData();
        body.append('file', file);
        try {
            const result = await request(config.importUrl, 'POST', body);
            showToast(result.message);
            page = 1;
            await refresh();
        } catch (error) {
            showToast(error.message, true);
        } finally {
            event.target.value = '';
        }
    });

    document.getElementById('current-year').textContent = new Date().getFullYear();
    refresh();
    window.setInterval(refresh, 10000);
})();
