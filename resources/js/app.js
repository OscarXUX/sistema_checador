import './bootstrap';
import * as bootstrap from 'bootstrap';
import { csvEscape } from './csv';

window.bootstrap = bootstrap;

// Wijmo se carga solo en las páginas que lo usan: resources/js/wijmo/*.js (auditoría OP-06)

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
    const contenedorAvisos = document.getElementById('contenedorAvisos');
    if (!contenedorAvisos) return;

    const dataColores = {
        success: 'text-bg-success border-success',
        danger: 'text-bg-danger border-danger',
        warning: 'text-bg-warning border-warning text-dark',
        info: 'text-bg-dark border-info',
    };

    const toastEl = document.createElement('div');
    toastEl.className = `toast align-items-center ${dataColores[variant] ?? dataColores.info} border`;
    toastEl.setAttribute('role', 'status');
    toastEl.setAttribute('aria-live', 'polite');

    const bodyWrapper = document.createElement('div');
    bodyWrapper.className = 'd-flex';

    const body = document.createElement('div');
    body.className = 'toast-body';
    body.textContent = message;

    const btnCerrarAviso = document.createElement('button');
    btnCerrarAviso.type = 'button';
    btnCerrarAviso.className = 'btn-close btn-close-white me-2 m-auto';
    btnCerrarAviso.setAttribute('data-bs-dismiss', 'toast');
    btnCerrarAviso.setAttribute('aria-label', 'Cerrar notificación');

    bodyWrapper.append(body, btnCerrarAviso);
    toastEl.appendChild(bodyWrapper);
    contenedorAvisos.appendChild(toastEl);

    const toast = new bootstrap.Toast(toastEl, { delay: 4500 });
    toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    toast.show();
}

async function apiFetch(url, options = {}) {
    const dataEncabezados = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCsrfToken(),
        ...(options.headers || {}),
    };

    if (options.body && !(options.body instanceof FormData) && typeof options.body !== 'string') {
        dataEncabezados['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }

    const response = await fetch(url, {
        ...options,
        headers: dataEncabezados,
    });

    const dataRespuesta = await response.json().catch(() => ({}));

    // 401: sin sesión; 419: token CSRF vencido junto con la sesión (auditoría SE-01)
    if (response.status === 401 || response.status === 419) {
        const loginUrl = document.body.dataset.loginUrl || '/login';
        window.location.assign(`${loginUrl}?expirada=1`);
        const error = new Error('Tu sesión expiró. Vuelve a iniciar sesión.');
        error.status = response.status;
        throw error;
    }

    if (!response.ok) {
        const error = new Error(dataRespuesta.message || `Error HTTP ${response.status}`);
        error.status = response.status;
        error.payload = dataRespuesta;
        throw error;
    }

    return dataRespuesta;
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
    const mdMenuMovil = document.getElementById('mdMenuMovil');
    const btnAbrirMenu = document.getElementById('btnAbrirMenu');
    const btnCerrarMenu = document.getElementById('btnCerrarMenu');
    if (!mdMenuMovil || !btnAbrirMenu || !btnCerrarMenu) return;

    const scroller = mdMenuMovil.querySelector('.mobile-drawer-scroller');
    const sheet = mdMenuMovil.querySelector('.mobile-drawer-sheet');
    if (!scroller || !sheet) return;

    async function abrirMenu() {
        mdMenuMovil.showModal();
        if (!CSS.supports('scroll-initial-target', 'nearest')) {
            scroller.scrollTo({ left: scroller.offsetWidth, behavior: 'instant' });
            await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
        }
        scroller.scrollTo({ left: 0, behavior: 'auto' });
    }

    function cerrarMenu() {
        scroller.scrollTo({ left: scroller.offsetWidth, behavior: 'auto' });
    }

    if (!CSS.supports('animation-timeline: scroll()')) {
        scroller.addEventListener('scroll', () => {
            const width = sheet.offsetWidth || 1;
            const ratio = Math.max(0, Math.min(1, 1 - scroller.scrollLeft / width));
            mdMenuMovil.style.setProperty('--drawer-backdrop', String(ratio));
        });
    }

    const visibleThreshold = 1 / Math.max(1, window.innerWidth);
    const observer = new IntersectionObserver(
        (lstEntradas) => {
            const entry = lstEntradas.at(-1);
            if (!entry) return;
            if (entry.intersectionRatio < visibleThreshold && mdMenuMovil.open) {
                mdMenuMovil.close();
                btnAbrirMenu.setAttribute('aria-expanded', 'false');
            }
            if (entry.intersectionRatio === 1) {
                btnAbrirMenu.setAttribute('aria-expanded', 'true');
            }
        },
        { root: mdMenuMovil, threshold: [visibleThreshold, 1] }
    );
    observer.observe(sheet);

    btnAbrirMenu.addEventListener('click', abrirMenu);
    btnCerrarMenu.addEventListener('click', cerrarMenu);
    mdMenuMovil.addEventListener('click', (event) => {
        if (!sheet.contains(event.target)) {
            cerrarMenu();
        }
    });
    mdMenuMovil.addEventListener('cancel', (event) => {
        if (event.cancelable) {
            event.preventDefault();
            cerrarMenu();
        }
    });
}

function updateDeviceStatusBadges(dataEstado) {
    document.querySelectorAll('[data-device-status-widget]').forEach((widget) => {
        const dot = widget.querySelector('[data-device-dot]');
        const label = widget.querySelector('[data-device-label]');
        const meta = widget.querySelector('[data-device-meta]');

        if (dot) {
            dot.classList.remove('online', 'offline', 'checking');
            dot.classList.add(dataEstado.online ? 'online' : 'offline');
        }
        if (label) {
            label.textContent = dataEstado.online ? 'Online' : 'Offline';
            label.classList.toggle('text-success', Boolean(dataEstado.online));
            label.classList.toggle('text-danger', !dataEstado.online);
            label.classList.remove('text-warning');
        }
        if (meta) {
            meta.textContent = dataEstado.online
                ? `${dataEstado.model || 'DS-K1T804AEF'} · ${dataEstado.device_ip}`
                : dataEstado.message || `Sin conexión (${dataEstado.device_ip || '192.168.0.69'})`;
        }
    });
}

function initDeviceControls() {
    const statusUrl = document.body.dataset.deviceStatusUrl;
    const syncUrl = document.body.dataset.deviceSyncUrl;

    // fresh = true vuelve a consultar la terminal; si no, el servidor responde de su caché de 30 s (OP-05)
    async function refreshStatus(fresh = false) {
        if (!statusUrl) return;
        try {
            const url = fresh ? `${statusUrl}?fresh=1` : statusUrl;
            const dataEstado = await apiFetch(url, { method: 'GET' });
            updateDeviceStatusBadges(dataEstado);
        } catch {
            updateDeviceStatusBadges({
                online: false,
                device_ip: '192.168.0.69',
                message: 'Error consultando estado del reloj',
            });
        }
    }

    refreshStatus();
    setInterval(() => refreshStatus(), 30000);

    document.querySelectorAll('[data-refresh-device-status]').forEach((btn) => {
        btn.addEventListener('click', () => refreshStatus(true));
    });

    document.querySelectorAll('[data-sync-now-btn]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (!syncUrl) return;
            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Sincronizando...';

            try {
                const dataRespuesta = await apiFetch(syncUrl, { method: 'POST' });
                showToast(dataRespuesta.message || 'Sincronización completada.', 'success');
                window.dispatchEvent(new CustomEvent('hikvision:synced', { detail: dataRespuesta }));
                refreshStatus(true);
            } catch (err) {
                // 409: ya hay una sincronización en curso (SE-13)
                showToast(err.message || 'No se pudo sincronizar con la terminal Hikvision.', err.status === 409 ? 'warning' : 'danger');
                refreshStatus(true);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
    });
}

// Se combina con lo que ya hayan registrado los módulos de Wijmo de la página
window.AppUI = Object.assign(window.AppUI || {}, {
    wijmo: window.AppUI?.wijmo || {},
    apiFetch,
    showToast,
    escapeHtml,
    csvEscape,
});

document.addEventListener('DOMContentLoaded', () => {
    initDialogFallbacks();
    initMobileDrawer();
    initDeviceControls();
});
