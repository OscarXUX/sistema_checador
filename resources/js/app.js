import './bootstrap';
import * as bootstrap from 'bootstrap';
import wjCore from './wijmo-bootstrap';
import '@mescius/wijmo.cultures/wijmo.culture.es-MX.js';
import * as wjGrid from '@mescius/wijmo.grid';
import * as wjFilter from '@mescius/wijmo.grid.filter';
import * as wjXlsx from '@mescius/wijmo.xlsx';
import * as wjGridXlsx from '@mescius/wijmo.grid.xlsx';
import * as wjChart from '@mescius/wijmo.chart';
import JSZip from 'jszip';
import { csvEscape } from './csv';

window.bootstrap = bootstrap;

// Registrar JSZip para exportación nativa a Excel con FlexGridXlsxConverter
const resolvedJsZip = typeof JSZip === 'function' ? JSZip : (JSZip?.default ?? JSZip);
wjXlsx.useJSZip(resolvedJsZip);

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function showToast(message, variant = 'info') {
    const container = document.getElementById('app-toast-container');
    if (!container) return;

    const palette = {
        success: 'text-bg-success border-success',
        danger: 'text-bg-danger border-danger',
        warning: 'text-bg-warning border-warning text-dark',
        info: 'text-bg-dark border-info',
    };

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center ${palette[variant] ?? palette.info} border`;
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', 'polite');

    const bodyWrapper = document.createElement('div');
    bodyWrapper.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');
    closeBtn.setAttribute('aria-label', 'Cerrar notificación');

    bodyWrapper.append(body, closeBtn);
    toastEl.appendChild(bodyWrapper);
    container.appendChild(toastEl);

    const toast = new bootstrap.Toast(toastEl, { delay: 4500 });
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    toast.show();
}

async function apiFetch(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
        ...(options.headers || {}),
    };

    if (options.body && !(options.body instanceof FormData) && typeof options.body !== 'string') {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, {
        ...options,
        headers,
    });

    const data = await response.json().catch(() => ({}));

    // 401: sin sesión; 419: token CSRF vencido junto con la sesión (auditoría SE-01)
    if (response.status === 401 || response.status === 419) {
        const loginUrl = document.body.dataset.loginUrl || '/login';
        window.location.assign(`${loginUrl}?expirada=1`);
        const error = new Error('Tu sesión expiró. Vuelve a iniciar sesión.');
        error.status = response.status;
        throw error;
    }

    if (!response.ok) {
        const error = new Error(data.message || `Error HTTP ${response.status}`);
        error.status = response.status;
        error.payload = data;
        throw error;
    }

    return data;
}

function initDialogFallbacks() {
    // Fallback para navegadores sin closedby="any" en <dialog>
    if (!('closedBy' in HTMLDialogElement.prototype)) {
        const outside = (e, dialog) => {
            const r = dialog.getBoundingClientRect();
            return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
        };

        document.querySelectorAll('dialog[closedby="any"]').forEach((dialog) => {
            let pressedOutside = false;
            dialog.addEventListener('pointerdown', (e) => {
                pressedOutside = outside(e, dialog);
            });
            dialog.addEventListener('pointerup', (e) => {
                if (pressedOutside && outside(e, dialog)) {
                    dialog.close();
                }
            });
        });
    }

    // Limpiar returnValue en cierre para evitar fuga de valores previos
    document.querySelectorAll('dialog').forEach((dialog) => {
        dialog.addEventListener('close', () => {
            dialog.returnValue = '';
        });
    });
}

function initMobileDrawer() {
    const drawer = document.getElementById('mobile-nav-drawer');
    const openBtn = document.getElementById('mobile-nav-open');
    const closeBtn = document.getElementById('mobile-nav-close');
    if (!drawer || !openBtn || !closeBtn) return;

    const scroller = drawer.querySelector('.mobile-drawer-scroller');
    const sheet = drawer.querySelector('.mobile-drawer-sheet');
    if (!scroller || !sheet) return;

    async function openDrawer() {
        drawer.showModal();
        if (!CSS.supports('scroll-initial-target', 'nearest')) {
            scroller.scrollTo({ left: scroller.offsetWidth, behavior: 'instant' });
            await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        }
        scroller.scrollTo({ left: 0, behavior: 'auto' });
    }

    function closeDrawer() {
        scroller.scrollTo({ left: scroller.offsetWidth, behavior: 'auto' });
    }

    if (!CSS.supports('animation-timeline: scroll()')) {
        scroller.addEventListener('scroll', () => {
            const width = sheet.offsetWidth || 1;
            const ratio = Math.max(0, Math.min(1, 1 - scroller.scrollLeft / width));
            drawer.style.setProperty('--drawer-backdrop', String(ratio));
        });
    }

    const visibleThreshold = 1 / Math.max(1, window.innerWidth);
    const observer = new IntersectionObserver(
        (entries) => {
            const entry = entries.at(-1);
            if (!entry) return;
            if (entry.intersectionRatio < visibleThreshold && drawer.open) {
                drawer.close();
                openBtn.setAttribute('aria-expanded', 'false');
            }
            if (entry.intersectionRatio === 1) {
                openBtn.setAttribute('aria-expanded', 'true');
            }
        },
        { root: drawer, threshold: [visibleThreshold, 1] }
    );
    observer.observe(sheet);

    openBtn.addEventListener('click', openDrawer);
    closeBtn.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', (event) => {
        if (!sheet.contains(event.target)) {
            closeDrawer();
        }
    });
    drawer.addEventListener('cancel', (event) => {
        if (event.cancelable) {
            event.preventDefault();
            closeDrawer();
        }
    });
}

function updateDeviceStatusBadges(statusPayload) {
    document.querySelectorAll('[data-device-status-widget]').forEach((widget) => {
        const dot = widget.querySelector('[data-device-dot]');
        const label = widget.querySelector('[data-device-label]');
        const meta = widget.querySelector('[data-device-meta]');

        if (dot) {
            dot.classList.remove('online', 'offline', 'checking');
            dot.classList.add(statusPayload.online ? 'online' : 'offline');
        }
        if (label) {
            label.textContent = statusPayload.online ? 'Online' : 'Offline';
            label.classList.toggle('text-success', Boolean(statusPayload.online));
            label.classList.toggle('text-danger', !statusPayload.online);
            label.classList.remove('text-warning');
        }
        if (meta) {
            meta.textContent = statusPayload.online
                ? `${statusPayload.model || 'DS-K1T804AEF'} · ${statusPayload.device_ip}`
                : statusPayload.message || `Sin conexión (${statusPayload.device_ip || '192.168.0.69'})`;
        }
    });
}

function initDeviceControls() {
    const statusUrl = document.body.dataset.deviceStatusUrl;
    const syncUrl = document.body.dataset.deviceSyncUrl;

    async function refreshStatus() {
        if (!statusUrl) return;
        try {
            const payload = await apiFetch(statusUrl, { method: 'GET' });
            updateDeviceStatusBadges(payload);
        } catch {
            updateDeviceStatusBadges({
                online: false,
                device_ip: '192.168.0.69',
                message: 'Error consultando estado del reloj',
            });
        }
    }

    refreshStatus();
    setInterval(refreshStatus, 30000);

    document.querySelectorAll('[data-refresh-device-status]').forEach((btn) => {
        btn.addEventListener('click', () => refreshStatus());
    });

    document.querySelectorAll('[data-sync-now-btn]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!syncUrl) return;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sincronizando...';

            try {
                const res = await apiFetch(syncUrl, { method: 'POST' });
                showToast(res.message || 'Sincronización completada.', 'success');
                window.dispatchEvent(new CustomEvent('hikvision:synced', { detail: res }));
                refreshStatus();
            } catch (err) {
                showToast(err.message || 'No se pudo sincronizar con la terminal Hikvision.', 'danger');
                refreshStatus();
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
    });
}

window.AppUI = {
    wijmo: {
        core: wjCore,
        grid: wjGrid,
        filter: wjFilter,
        xlsx: wjXlsx,
        gridXlsx: wjGridXlsx,
        chart: wjChart,
    },
    apiFetch,
    showToast,
    escapeHtml,
    csvEscape,
};

document.addEventListener('DOMContentLoaded', () => {
    initDialogFallbacks();
    initMobileDrawer();
    initDeviceControls();
});
