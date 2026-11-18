@extends('layouts.app')

@section('title', 'Empleados y Enrolamiento — Checador Hikvision')
@section('page_title', 'Gestión de Empleados y Enrolamiento')
@section('page_subtitle', 'Padrón fiscal laboral conectado por ISAPI con DS-K1T804AEF')

@push('styles')
    {{-- Wijmo solo en esta página (auditoría OP-06) --}}
    @vite(['resources/js/wijmo/grid.js'])
@endpush

@section('content')
<section class="surface-card p-3 p-md-4 isolated-panel">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <search>
                <form id="employee-filter-form" class="d-flex align-items-center">
                    <label for="employee-search-input" class="visually-hidden">Buscar empleados</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-dark border-secondary-subtle text-secondary">
                            <i class="bi bi-search" aria-hidden="true"></i>
                        </span>
                        <input id="employee-search-input"
                               type="search"
                               class="form-control bg-dark border-secondary-subtle"
                               placeholder="Buscar nombre, ID reloj, RFC, depto..."
                               autocorrect="off"
                               autocapitalize="none"
                               spellcheck="false">
                    </div>
                </form>
            </search>
            <span class="badge text-bg-dark border border-secondary-subtle" id="employee-count-badge">
                {{ $employeesPage['meta']['total'] }} empleados
            </span>
        </div>

        <button id="btn-new-employee" type="button" class="btn btn-primary btn-sm d-inline-flex align-items-center gap-2">
            <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
            <span>Nuevo Empleado</span>
        </button>
    </div>

    <div id="employees-grid" aria-label="Tabla de empleados y acciones biométricas"></div>

    {{-- Paginación en el servidor (auditoría OP-07) --}}
    <nav class="d-flex justify-content-between align-items-center gap-2 mt-3" aria-label="Páginas de empleados">
        <button id="employees-prev" type="button" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left" aria-hidden="true"></i> Anterior
        </button>
        <span id="employees-page-label" class="small text-secondary font-mono-tabular" aria-live="polite">
            Página {{ $employeesPage['meta']['page'] }} de {{ $employeesPage['meta']['last_page'] }}
        </span>
        <button id="employees-next" type="button" class="btn btn-outline-secondary btn-sm">
            Siguiente <i class="bi bi-chevron-right" aria-hidden="true"></i>
        </button>
    </nav>
</section>

<!-- Modal nativo <dialog>: Crear / Editar Empleado -->
<dialog id="employee-form-dialog" class="app-dialog" aria-labelledby="employee-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="employee-dialog-title" class="h6 mb-0 fw-semibold">Nuevo Empleado</h2>
            <button type="button" class="btn-close" data-close-dialog="employee-form-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <form id="employee-form" method="POST" action="{{ route('employees.store') }}" novalidate>
            @csrf
            <input type="hidden" id="emp-id" name="id" value="">

            <div class="p-3 p-md-4 row g-3">
                <div id="employee-form-errors" class="col-12 d-none">
                    <div class="alert alert-danger py-2 small mb-0" role="alert" aria-live="polite"></div>
                </div>

                <div class="col-12 col-sm-4">
                    <label for="emp-no" class="form-label small fw-medium">ID Reloj Hikvision <span class="text-danger">*</span></label>
                    <input id="emp-no"
                           name="hikvision_employee_no"
                           type="text"
                           class="form-control font-mono-tabular"
                           required
                           maxlength="32"
                           pattern="[A-Za-z0-9_-]+"
                           autocorrect="off"
                           autocapitalize="none"
                           spellcheck="false"
                           aria-describedby="emp-no-help"
                           autofocus>
                    <div id="emp-no-help" class="form-text small">Llave única en terminal (ej. 1015).</div>
                </div>

                <div class="col-12 col-sm-8">
                    <label for="emp-name" class="form-label small fw-medium">Nombre Completo <span class="text-danger">*</span></label>
                    <input id="emp-name"
                           name="full_name"
                           type="text"
                           class="form-control"
                           required
                           maxlength="150"
                           autocomplete="name">
                </div>

                <div class="col-12 col-sm-4">
                    <label for="emp-rfc" class="form-label small fw-medium">RFC</label>
                    <input id="emp-rfc"
                           name="rfc"
                           type="text"
                           class="form-control font-mono-tabular text-uppercase"
                           maxlength="13"
                           pattern="[A-Za-zÑñ&]{3,4}\d{6}[A-Za-z0-9]{3}"
                           autocorrect="off"
                           autocapitalize="characters"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-5">
                    <label for="emp-curp" class="form-label small fw-medium">CURP</label>
                    <input id="emp-curp"
                           name="curp"
                           type="text"
                           class="form-control font-mono-tabular text-uppercase"
                           maxlength="18"
                           minlength="18"
                           autocorrect="off"
                           autocapitalize="characters"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-3">
                    <label for="emp-nss" class="form-label small fw-medium">NSS (11 dígitos)</label>
                    <input id="emp-nss"
                           name="nss"
                           type="text"
                           class="form-control font-mono-tabular"
                           maxlength="11"
                           inputmode="numeric"
                           pattern="\d{11}"
                           autocorrect="off"
                           spellcheck="false">
                </div>

                <div class="col-12 col-sm-6">
                    <label for="emp-department" class="form-label small fw-medium">Departamento <span class="text-danger">*</span></label>
                    <input id="emp-department"
                           name="department"
                           type="text"
                           class="form-control"
                           list="department-options"
                           required
                           maxlength="100">
                    <datalist id="department-options">
                        @foreach($departments as $dept)
                            <option value="{{ $dept }}"></option>
                        @endforeach
                    </datalist>
                </div>

                <div class="col-12 col-sm-6">
                    <label for="emp-position" class="form-label small fw-medium">Puesto <span class="text-danger">*</span></label>
                    <input id="emp-position"
                           name="position"
                           type="text"
                           class="form-control"
                           required
                           maxlength="100">
                </div>

                <div class="col-12 col-sm-8">
                    <label for="emp-shift" class="form-label small fw-medium">Turno Fiscal Asignado <span class="text-danger">*</span></label>
                    <select id="emp-shift" name="work_shift_id" class="form-select" required>
                        @foreach($workShifts as $shift)
                            <option value="{{ $shift->id }}">
                                {{ $shift->name }} ({{ $shift->schedule_label }} · {{ $shift->meal_duration_minutes }}m comida)
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-sm-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input id="emp-active" name="is_active" class="form-check-input" type="checkbox" value="1" checked>
                        <label class="form-check-label small" for="emp-active">Empleado Activo</label>
                    </div>
                </div>
            </div>

            <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="employee-form-dialog">
                    Cancelar
                </button>
                <button id="emp-submit-btn" type="submit" class="btn btn-primary btn-sm">
                    Guardar Empleado
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Enrolamiento Remoto de Huella en Tiempo Real -->
<dialog id="fingerprint-dialog" class="app-dialog" closedby="any" aria-labelledby="fp-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="fp-dialog-title" class="h6 mb-0 fw-semibold">Captura Remota de Huella (ISAPI)</h2>
            <button type="button" class="btn-close" data-close-dialog="fingerprint-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <div class="p-4 text-center">
            <div id="fp-pulse-indicator" class="fingerprint-pulse mb-3">
                <i class="bi bi-fingerprint" aria-hidden="true"></i>
            </div>

            <h3 id="fp-employee-name" class="h6 fw-semibold mb-1">-</h3>
            <p class="small text-secondary font-mono-tabular mb-3" id="fp-employee-no">ID Reloj: -</p>

            <div class="row justify-content-center mb-3">
                <div class="col-12 col-sm-7 text-start">
                    <label for="fp-finger-no" class="form-label small fw-medium">Índice de dedo (1-10)</label>
                    <select id="fp-finger-no" class="form-select form-select-sm">
                        <option value="1">1 — Pulgar Derecho</option>
                        <option value="2" selected>2 — Índice Derecho</option>
                        <option value="3">3 — Medio Derecho</option>
                        <option value="6">6 — Pulgar Izquierdo</option>
                        <option value="7">7 — Índice Izquierdo</option>
                    </select>
                </div>
            </div>

            <div id="fp-status-box" class="alert alert-secondary small text-start mb-0" role="status" aria-live="polite">
                Presiona <strong>Activar Lector Óptico</strong> para enviar <code>POST /ISAPI/AccessControl/CaptureFingerPrint</code> a la terminal y solicitar al empleado colocar su dedo.
            </div>
        </div>

        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="fingerprint-dialog" autofocus>
                Cerrar
            </button>
            <button id="fp-start-btn" type="button" class="btn btn-info btn-sm text-dark fw-semibold">
                <i class="bi bi-broadcast me-1" aria-hidden="true"></i>Activar Lector Óptico
            </button>
        </div>
    </div>
</dialog>

<!-- Modal nativo <dialog>: Confirmación de Baja / Eliminación -->
<dialog id="delete-employee-dialog" class="app-dialog" closedby="any" aria-labelledby="delete-dialog-title">
    <div class="surface-card overflow-hidden">
        <div class="p-3 border-bottom border-secondary-subtle d-flex justify-content-between align-items-center">
            <h2 id="delete-dialog-title" class="h6 mb-0 fw-semibold text-danger">Confirmar Baja</h2>
            <button type="button" class="btn-close" data-close-dialog="delete-employee-dialog" aria-label="Cerrar ventana"></button>
        </div>

        <div class="p-3 p-md-4">
            <p class="mb-3">
                ¿Deseas dar de baja a <strong id="del-employee-name"></strong> (<span id="del-employee-no" class="font-mono-tabular"></span>)?
                Si ya tiene checadas, quedará como <strong>Inactivo</strong> y se conserva su historial.
                Si no tiene checadas, se elimina del sistema.
            </p>
            <div class="form-check">
                <input id="del-from-device" class="form-check-input" type="checkbox" value="1">
                <label class="form-check-label small" for="del-from-device">
                    Dar de baja también en la terminal Hikvision (<code>PUT /ISAPI/AccessControl/UserInfo/Delete</code>)
                </label>
            </div>
        </div>

        <div class="p-3 border-top border-secondary-subtle d-flex justify-content-end gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-close-dialog="delete-employee-dialog" autofocus>
                Cancelar
            </button>
            <button id="del-confirm-btn" type="button" class="btn btn-danger btn-sm">
                Dar de Baja
            </button>
        </div>
    </div>
</dialog>
@endsection

@push('scripts')
<script id="employees-initial-data" type="application/json">@json($employeesPage)</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const { core: wjCore, grid: wjGrid } = window.AppUI.wijmo;
    const { apiFetch, showToast, escapeHtml } = window.AppUI;

    const routes = {
        store: '{{ route("employees.store") }}',
        updateBase: '{{ url("/employees") }}',
        list: '{{ route("employees.list") }}',
    };

    // Paginación y búsqueda en el servidor (auditoría OP-07): la página solo trae 50 empleados a la vez
    const initialPage = JSON.parse(document.getElementById('employees-initial-data').textContent);
    let employees = initialPage.employees || [];
    let meta = initialPage.meta;
    const view = new wjCore.CollectionView(employees);
    const searchInput = document.getElementById('employee-search-input');
    const prevBtn = document.getElementById('employees-prev');
    const nextBtn = document.getElementById('employees-next');
    document.getElementById('employee-filter-form')?.addEventListener('submit', (e) => e.preventDefault());

    let searchTimer = null;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadPage(1), 300);
    });
    prevBtn.addEventListener('click', () => loadPage(meta.page - 1));
    nextBtn.addEventListener('click', () => loadPage(meta.page + 1));

    function updateCount() {
        document.getElementById('employee-count-badge').textContent = `${meta.total} empleados`;
        document.getElementById('employees-page-label').textContent = `Página ${meta.page} de ${meta.last_page}`;
        prevBtn.disabled = meta.page <= 1;
        nextBtn.disabled = meta.page >= meta.last_page;
    }
    updateCount();

    async function loadPage(page) {
        const params = new URLSearchParams({
            page: String(Math.max(1, page)),
            q: (searchInput.value || '').trim(),
        });
        try {
            const data = await apiFetch(`${routes.list}?${params}`, { method: 'GET' });
            employees = data.employees || [];
            meta = data.meta;
            view.sourceCollection = employees;
            view.refresh();
            updateCount();
        } catch (err) {
            showToast(err.message || 'No se pudo cargar la lista de empleados.', 'danger');
        }
    }

    const grid = new wjGrid.FlexGrid('#employees-grid', {
        itemsSource: view,
        autoGenerateColumns: false,
        isReadOnly: true,
        selectionMode: wjGrid.SelectionMode.Row,
        headersVisibility: wjGrid.HeadersVisibility.Column,
        columns: [
            { binding: 'hikvision_employee_no', header: 'ID Reloj', width: 95, cssClass: 'font-mono-tabular' },
            { binding: 'full_name', header: 'Nombre Completo', width: '*', minWidth: 200 },
            { binding: 'rfc', header: 'RFC', width: 140, cssClass: 'font-mono-tabular' },
            { binding: 'department', header: 'Departamento', width: 130 },
            { binding: 'shift_name', header: 'Turno', width: 165 },
            { binding: 'status_label', header: 'Estado', width: 95 },
            { binding: 'device_synced_at', header: 'Reloj / Huella', width: 170 },
            { binding: 'id', header: 'Acciones Hikvision / CRUD', width: 320, allowSorting: false },
        ],
    });
    grid.rows.defaultSize = 48;

    grid.formatItem.addHandler((s, e) => {
        if (e.panel !== s.cells) return;
        const col = s.columns[e.col];
        const row = s.rows[e.row]?.dataItem;
        if (!row) return;

        if (col.binding === 'status_label') {
            const cls = row.is_active ? 'text-bg-success' : 'text-bg-secondary';
            e.cell.innerHTML = `<span class="badge ${cls}">${escapeHtml(row.status_label)}</span>`;
        } else if (col.binding === 'device_synced_at') {
            const syncHtml = row.device_synced_at
                ? `<span class="badge text-bg-dark border border-info text-info me-1" title="Última sincronización">${escapeHtml(row.device_synced_at)}</span>`
                : `<span class="badge text-bg-dark border border-secondary text-secondary me-1">Pendiente</span>`;
            const fpHtml = row.fingerprint_enrolled
                ? `<span class="badge text-bg-dark border border-success text-success"><i class="bi bi-fingerprint me-1"></i>Huella OK</span>`
                : `<span class="badge text-bg-dark border border-warning text-warning">Sin Huella</span>`;
            e.cell.innerHTML = `<div class="d-flex flex-wrap gap-1">${syncHtml}${fpHtml}</div>`;
        } else if (col.binding === 'id') {
            e.cell.innerHTML = `
                <div class="btn-group btn-group-sm" role="group" aria-label="Acciones de empleado">
                    <button type="button" class="btn btn-outline-info" data-action="sync" data-id="${row.id}" title="Sincronizar a Hikvision">
                        <i class="bi bi-hdd-network me-1" aria-hidden="true"></i>Sincronizar
                    </button>
                    <button type="button" class="btn btn-outline-warning" data-action="fingerprint" data-id="${row.id}" title="Capturar Huella Remota">
                        <i class="bi bi-fingerprint me-1" aria-hidden="true"></i>Huella
                    </button>
                    <button type="button" class="btn btn-outline-light" data-action="edit" data-id="${row.id}" title="Editar empleado">
                        <i class="bi bi-pencil" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="btn btn-outline-danger" data-action="delete" data-id="${row.id}" title="Dar de baja">
                        <i class="bi bi-trash" aria-hidden="true"></i>
                    </button>
                </div>`;
        }
    });

    // Cierre unificado de <dialog>
    document.querySelectorAll('[data-close-dialog]').forEach((btn) => {
        btn.addEventListener('click', () => {
            document.getElementById(btn.getAttribute('data-close-dialog'))?.close();
        });
    });

    const formDialog = document.getElementById('employee-form-dialog');
    const empForm = document.getElementById('employee-form');
    const formErrorBox = document.getElementById('employee-form-errors');
    const submitBtn = document.getElementById('emp-submit-btn');

    function clearFormErrors() {
        formErrorBox.classList.add('d-none');
        formErrorBox.querySelector('.alert').textContent = '';
        empForm.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
    }

    function openCreateModal() {
        clearFormErrors();
        empForm.reset();
        document.getElementById('emp-id').value = '';
        document.getElementById('emp-active').checked = true;
        document.getElementById('employee-dialog-title').textContent = 'Nuevo Empleado';
        submitBtn.textContent = 'Guardar Empleado';
        formDialog.showModal();
    }

    function openEditModal(emp) {
        clearFormErrors();
        document.getElementById('emp-id').value = emp.id;
        document.getElementById('emp-no').value = emp.hikvision_employee_no || '';
        document.getElementById('emp-name').value = emp.full_name || '';
        document.getElementById('emp-rfc').value = emp.rfc || '';
        document.getElementById('emp-curp').value = emp.curp || '';
        document.getElementById('emp-nss').value = emp.nss || '';
        document.getElementById('emp-department').value = emp.department || '';
        document.getElementById('emp-position').value = emp.position || '';
        document.getElementById('emp-shift').value = String(emp.work_shift_id || '');
        document.getElementById('emp-active').checked = Boolean(emp.is_active);
        document.getElementById('employee-dialog-title').textContent = `Editar Empleado #${emp.hikvision_employee_no}`;
        submitBtn.textContent = 'Actualizar Cambios';
        formDialog.showModal();
    }

    document.getElementById('btn-new-employee').addEventListener('click', openCreateModal);

    // Validación por campo tras interacción (blur) y limpieza en input
    empForm.querySelectorAll('input, select').forEach((field) => {
        field.addEventListener('input', () => {
            field.removeAttribute('aria-invalid');
        });
        field.addEventListener('blur', () => {
            if (!field.checkValidity()) {
                field.setAttribute('aria-invalid', 'true');
            }
        });
    });

    empForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearFormErrors();

        if (!empForm.checkValidity()) {
            const firstInvalid = empForm.querySelector(':invalid');
            if (firstInvalid) {
                firstInvalid.setAttribute('aria-invalid', 'true');
                firstInvalid.focus();
                firstInvalid.reportValidity();
            }
            return;
        }

        const empId = document.getElementById('emp-id').value;
        const isEdit = empId !== '';
        const url = isEdit ? `${routes.updateBase}/${empId}` : routes.store;

        const payload = {
            hikvision_employee_no: document.getElementById('emp-no').value,
            full_name: document.getElementById('emp-name').value,
            rfc: document.getElementById('emp-rfc').value || null,
            curp: document.getElementById('emp-curp').value || null,
            nss: document.getElementById('emp-nss').value || null,
            department: document.getElementById('emp-department').value,
            position: document.getElementById('emp-position').value,
            work_shift_id: Number(document.getElementById('emp-shift').value),
            is_active: document.getElementById('emp-active').checked,
        };

        submitBtn.disabled = true;
        try {
            const res = await apiFetch(url, {
                method: isEdit ? 'PUT' : 'POST',
                body: payload,
            });
            await reloadEmployees();
            formDialog.close();
            showToast(res.message, 'success');
        } catch (err) {
            const validationErrors = err.payload?.errors
                ? Object.values(err.payload.errors).flat().join(' · ')
                : err.message;
            formErrorBox.classList.remove('d-none');
            formErrorBox.querySelector('.alert').textContent = validationErrors;
        } finally {
            submitBtn.disabled = false;
        }
    });

    // Modal de captura remota de huella
    const fpDialog = document.getElementById('fingerprint-dialog');
    const fpPulse = document.getElementById('fp-pulse-indicator');
    const fpStatusBox = document.getElementById('fp-status-box');
    const fpStartBtn = document.getElementById('fp-start-btn');
    let currentFpEmployee = null;

    function openFingerprintModal(emp) {
        currentFpEmployee = emp;
        document.getElementById('fp-employee-name').textContent = emp.full_name;
        document.getElementById('fp-employee-no').textContent = `ID Reloj Hikvision: ${emp.hikvision_employee_no}`;
        fpPulse.classList.remove('scanning', 'success', 'error');
        fpStatusBox.className = 'alert alert-secondary small text-start mb-0';
        fpStatusBox.innerHTML = 'Presiona <strong>Activar Lector Óptico</strong> para enviar <code>POST /ISAPI/AccessControl/CaptureFingerPrint</code> a la terminal y solicitar al empleado colocar su dedo.';
        fpStartBtn.disabled = false;
        fpDialog.showModal();
    }

    fpStartBtn.addEventListener('click', async () => {
        if (!currentFpEmployee) return;

        const fingerNo = Number(document.getElementById('fp-finger-no').value || 2);
        fpStartBtn.disabled = true;
        fpPulse.classList.remove('success', 'error');
        fpPulse.classList.add('scanning');
        fpStatusBox.className = 'alert alert-info small text-start mb-0';
        fpStatusBox.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Esperando lectura en el sensor óptico del DS-K1T804AEF... Coloque el dedo en el reloj.';

        try {
            const res = await apiFetch(`${routes.updateBase}/${currentFpEmployee.id}/capture-fingerprint`, {
                method: 'POST',
                body: { finger_no: fingerNo },
            });
            fpPulse.classList.remove('scanning');
            fpPulse.classList.add('success');
            fpStatusBox.className = 'alert alert-success small text-start mb-0';
            fpStatusBox.textContent = res.message || 'Huella capturada y vinculada correctamente.';
            await reloadEmployees();
            showToast(res.message, 'success');
        } catch (err) {
            fpPulse.classList.remove('scanning');
            fpPulse.classList.add('error');
            fpStatusBox.className = 'alert alert-danger small text-start mb-0';
            fpStatusBox.textContent = err.message || 'No se pudo completar la captura remota de huella.';
        } finally {
            fpStartBtn.disabled = false;
        }
    });

    // Modal de eliminación
    const deleteDialog = document.getElementById('delete-employee-dialog');
    const delConfirmBtn = document.getElementById('del-confirm-btn');
    let currentDeleteEmployee = null;

    function openDeleteModal(emp) {
        currentDeleteEmployee = emp;
        document.getElementById('del-employee-name').textContent = emp.full_name;
        document.getElementById('del-employee-no').textContent = `#${emp.hikvision_employee_no}`;
        document.getElementById('del-from-device').checked = false;
        deleteDialog.showModal();
    }

    delConfirmBtn.addEventListener('click', async () => {
        if (!currentDeleteEmployee) return;
        delConfirmBtn.disabled = true;

        try {
            const res = await apiFetch(`${routes.updateBase}/${currentDeleteEmployee.id}`, {
                method: 'DELETE',
                body: {
                    delete_from_device: document.getElementById('del-from-device').checked,
                },
            });
            await reloadEmployees();
            deleteDialog.close();
            showToast(res.message, 'success');
        } catch (err) {
            showToast(err.message || 'Error al eliminar el empleado.', 'danger');
        } finally {
            delConfirmBtn.disabled = false;
        }
    });

    async function syncEmployeeToHikvision(emp, buttonEl) {
        const original = buttonEl.innerHTML;
        buttonEl.disabled = true;
        buttonEl.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span>';

        try {
            const res = await apiFetch(`${routes.updateBase}/${emp.id}/sync-device`, { method: 'POST' });
            await reloadEmployees();
            showToast(res.message, 'success');
        } catch (err) {
            showToast(err.message || 'Error al sincronizar usuario en Hikvision.', 'danger');
        } finally {
            buttonEl.disabled = false;
            buttonEl.innerHTML = original;
        }
    }

    async function reloadEmployees() {
        // Recarga la página actual con la búsqueda actual
        await loadPage(meta.page);
    }

    // Delegación única de eventos sobre FlexGrid
    document.getElementById('employees-grid').addEventListener('click', (event) => {
        const btn = event.target.closest('button[data-action]');
        if (!btn) return;

        const id = Number(btn.getAttribute('data-id'));
        const action = btn.getAttribute('data-action');
        const emp = employees.find((e) => e.id === id);
        if (!emp) return;

        if (action === 'edit') {
            openEditModal(emp);
        } else if (action === 'sync') {
            syncEmployeeToHikvision(emp, btn);
        } else if (action === 'fingerprint') {
            openFingerprintModal(emp);
        } else if (action === 'delete') {
            openDeleteModal(emp);
        }
    });
});
</script>
@endpush
