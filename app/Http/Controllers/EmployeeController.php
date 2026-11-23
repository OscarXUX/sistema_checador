<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\WorkShift;
use App\Services\AuditLogger;
use App\Services\HikvisionIsapiService;
use App\Support\PersonalData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(): View
    {
        $workShifts = WorkShift::orderBy('entry_time')->get();
        $departments = Employee::select('department')
            ->distinct()
            ->orderBy('department')
            ->pluck('department');

        return view('employees.index', [
            'workShifts' => $workShifts,
            'departments' => $departments,
            'employeesPage' => $this->paginateEmployees(1, self::DEFAULT_PER_PAGE, ''),
        ]);
    }

    /**
     * Listado paginado con búsqueda en el servidor (auditoría OP-07).
     */
    public function list(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTABLE_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        return response()->json($this->paginateEmployees(
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? self::DEFAULT_PER_PAGE),
            trim((string) ($validated['q'] ?? '')),
            $validated['sort'] ?? 'hikvision_employee_no',
            $validated['direction'] ?? 'asc',
        ));
    }

    /**
     * Campos del empleado que se registran en la bitácora (RFC, CURP y NSS se guardan como "[protegido]").
     */
    /**
     * Empleados por página en el listado (auditoría OP-07).
     */
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 100;

    /**
     * Columnas de la tabla que se pueden ordenar en el servidor, con su columna real.
     * El orden se aplica a todo el padrón (o a todo el resultado de la búsqueda) antes de paginar.
     */
    public const SORTABLE_COLUMNS = [
        'hikvision_employee_no' => 'hikvision_employee_no',
        'full_name' => 'full_name',
        'department' => 'department',
        'shift_name' => 'shift_name',
        'status_label' => 'is_active',
        'device_synced_at' => 'device_synced_at',
    ];

    private const AUDITED_FIELDS = [
        'hikvision_employee_no', 'full_name', 'rfc', 'curp', 'nss',
        'department', 'position', 'work_shift_id', 'is_active',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Datos completos de un empleado para el formulario de edición. Queda en la bitácora
     * quién vio RFC, CURP y NSS (auditoría SE-05, guía 4.21).
     */
    public function show(Employee $employee): JsonResponse
    {
        $employee->load('workShift');

        $this->audit->record(
            'employee.personal_data_viewed',
            $employee,
            "Consulta de RFC, CURP y NSS del empleado #{$employee->hikvision_employee_no}"
        );

        return response()->json([
            'employee' => $this->formatEmployeeRow($employee, true),
        ]);
    }

    public function store(StoreEmployeeRequest $request): JsonResponse|RedirectResponse
    {
        $employee = Employee::create($request->validated());
        $employee->load('workShift');

        $this->audit->record(
            'employee.created',
            $employee,
            "Alta del empleado #{$employee->hikvision_employee_no} {$employee->full_name}",
            [],
            $employee->only(self::AUDITED_FIELDS)
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Empleado {$employee->full_name} registrado correctamente.",
                'employee' => $this->formatEmployeeRow($employee),
            ], 201);
        }

        return redirect()
            ->route('employees.index')
            ->with('status', "Empleado {$employee->full_name} registrado correctamente.");
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): JsonResponse|RedirectResponse
    {
        $before = $employee->only(self::AUDITED_FIELDS);
        $employee->update($request->validated());
        $employee->load('workShift');

        [$old, $new] = $this->audit->diff($before, $employee->only(self::AUDITED_FIELDS));
        if ($new !== []) {
            $this->audit->record(
                'employee.updated',
                $employee,
                "Cambio en el empleado #{$employee->hikvision_employee_no} {$employee->full_name}",
                $old,
                $new
            );
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Empleado {$employee->full_name} actualizado.",
                'employee' => $this->formatEmployeeRow($employee),
            ]);
        }

        return redirect()
            ->route('employees.index')
            ->with('status', "Empleado {$employee->full_name} actualizado.");
    }

    /**
     * Elimina al empleado solo si no tiene historial. Si ya tiene checadas o resúmenes,
     * se da de baja de forma lógica para conservar la bitácora (auditoría SE-03).
     */
    public function destroy(
        Request $request,
        Employee $employee,
        HikvisionIsapiService $isapi
    ): JsonResponse|RedirectResponse {
        $employeeNo = $employee->hikvision_employee_no;
        $employeeName = $employee->full_name;
        $removedFromDevice = false;
        $deviceNotice = '';

        if ($request->boolean('delete_from_device')) {
            try {
                $isapi->deleteUserFromDevice($employeeNo);
                $removedFromDevice = true;
                $deviceNotice = ' También fue dado de baja en la terminal Hikvision.';
            } catch (HikvisionConnectionException $e) {
                if ($request->expectsJson()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No se pudo eliminar en el reloj Hikvision: '.$e->getMessage(),
                    ], 502);
                }

                return redirect()
                    ->route('employees.index')
                    ->withErrors(['device' => $e->getMessage()]);
            }
        }

        $hasHistory = $employee->attendanceLogs()->exists()
            || $employee->dailySummaries()->exists();

        if ($hasHistory) {
            $changes = ['is_active' => false];
            if ($removedFromDevice) {
                $changes['device_synced_at'] = null;
                $changes['fingerprint_enrolled'] = false;
            }
            $employee->forceFill($changes)->save();

            $message = "Empleado {$employeeName} dado de baja (inactivo). Se conserva su historial de checadas.{$deviceNotice}";
            $this->audit->record('employee.deactivated', $employee, "Baja del empleado #{$employeeNo} {$employeeName}".($removedFromDevice ? ' (también en la terminal)' : ''), ['is_active' => true], ['is_active' => false]);
        } else {
            $snapshot = $employee->only(self::AUDITED_FIELDS);
            $employee->delete();

            $message = "Empleado {$employeeName} eliminado del sistema.{$deviceNotice}";
            $this->audit->record('employee.deleted', $employee, "Eliminación del empleado #{$employeeNo} {$employeeName} (sin historial)".($removedFromDevice ? ' (también en la terminal)' : ''), $snapshot);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'deactivated' => $hasHistory,
                'message' => $message,
            ]);
        }

        return redirect()
            ->route('employees.index')
            ->with('status', $message);
    }

    public function syncToDevice(Employee $employee, HikvisionIsapiService $isapi): JsonResponse
    {
        try {
            $result = $isapi->syncUserToDevice($employee);
            $employee->refresh()->load('workShift');

            $this->audit->record('device.user_synced', $employee, "Empleado #{$employee->hikvision_employee_no} enviado a la terminal");

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'employee' => $this->formatEmployeeRow($employee),
                'detail' => $result,
            ]);
        } catch (HikvisionConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function deleteFromDevice(Employee $employee, HikvisionIsapiService $isapi): JsonResponse
    {
        try {
            $result = $isapi->deleteUserFromDevice($employee->hikvision_employee_no);
            $this->audit->record('device.user_deleted', $employee, "Empleado #{$employee->hikvision_employee_no} borrado de la terminal");
            $employee->forceFill([
                'device_synced_at' => null,
                'fingerprint_enrolled' => false,
            ])->save();
            $employee->load('workShift');

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'employee' => $this->formatEmployeeRow($employee),
            ]);
        } catch (HikvisionConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function captureFingerprint(
        Request $request,
        Employee $employee,
        HikvisionIsapiService $isapi
    ): JsonResponse {
        $validated = $request->validate([
            'finger_no' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $fingerNo = (int) ($validated['finger_no'] ?? 1);

        try {
            $result = $isapi->triggerRemoteFingerprintCapture($employee, $fingerNo);
            $employee->refresh()->load('workShift');

            $this->audit->record('device.fingerprint_captured', $employee, "Huella #{$fingerNo} capturada para el empleado #{$employee->hikvision_employee_no}");

            // Solo datos de estado: la plantilla de huella no se envía al navegador (SE-04).
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'employee' => $this->formatEmployeeRow($employee),
                'finger_no' => $result['finger_no'],
                'template_captured' => $result['template_captured'],
            ]);
        } catch (HikvisionConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Una página de empleados con el total para el paginador.
     *
     * @return array{employees: array<int, array<string, mixed>>, meta: array<string, int|string>}
     */
    private function paginateEmployees(
        int $page,
        int $perPage,
        string $term,
        string $sort = 'hikvision_employee_no',
        string $direction = 'asc',
    ): array {
        $query = Employee::with('workShift');
        $column = self::SORTABLE_COLUMNS[$sort] ?? 'hikvision_employee_no';

        if ($column === 'shift_name') {
            // El turno vive en otra tabla: se ordena por su nombre con una subconsulta
            $query->orderBy(
                WorkShift::select('name')->whereColumn('work_shifts.id', 'employees.work_shift_id'),
                $direction
            );
        } elseif ($column === 'is_active') {
            // Igual que el texto en pantalla: ascendente = "Activo" antes que "Inactivo"
            $query->orderBy('is_active', $direction === 'asc' ? 'desc' : 'asc');
        } else {
            $query->orderBy($column, $direction);
        }

        // Desempate estable para que ningún empleado se repita ni se salte entre páginas
        $query->orderBy('employees.id', $direction);

        if ($term !== '') {
            // Se escapan % y _ para que se busquen como texto
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function ($q) use ($like, $term): void {
                // RFC y CURP están cifrados: se buscan completos y exactos con su índice ciego (SE-05)
                $blind = PersonalData::blindIndex($term);
                $q->where('hikvision_employee_no', 'like', $like)
                    ->orWhere('full_name', 'like', $like)
                    ->orWhere('rfc_hash', $blind)
                    ->orWhere('curp_hash', $blind)
                    ->orWhere('department', 'like', $like)
                    ->orWhere('position', 'like', $like)
                    ->orWhereHas('workShift', fn ($shift) => $shift->where('name', 'like', $like));
            });
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Si se pidió una página que ya no existe (por ejemplo, tras una baja), se regresa la última
        if ($paginator->isEmpty() && $page > 1 && $paginator->lastPage() < $page) {
            $paginator = (clone $query)->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return [
            'employees' => $paginator->getCollection()
                ->map(fn (Employee $employee): array => $this->formatEmployeeRow($employee))
                ->values()
                ->all(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'q' => $term,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ];
    }

    /**
     * Fila para la tabla. RFC, CURP y NSS van enmascarados salvo que se pidan completos
     * (solo al abrir el formulario de edición). Auditoría SE-05.
     *
     * @return array<string, mixed>
     */
    private function formatEmployeeRow(Employee $employee, bool $withPersonalIds = false): array
    {
        return [
            'id' => $employee->id,
            'hikvision_employee_no' => $employee->hikvision_employee_no,
            'full_name' => $employee->full_name,
            'rfc' => $withPersonalIds ? $employee->rfc : PersonalData::maskRfc($employee->rfc),
            'curp' => $withPersonalIds ? $employee->curp : PersonalData::maskCurp($employee->curp),
            'nss' => $withPersonalIds ? $employee->nss : PersonalData::maskNss($employee->nss),
            'personal_ids_masked' => ! $withPersonalIds,
            'department' => $employee->department,
            'position' => $employee->position,
            'work_shift_id' => $employee->work_shift_id,
            'shift_name' => $employee->workShift?->name ?? 'Sin turno',
            'shift_schedule' => $employee->workShift?->schedule_label ?? '-',
            'is_active' => $employee->is_active,
            'status_label' => $employee->is_active ? 'Activo' : 'Inactivo',
            'fingerprint_enrolled' => $employee->fingerprint_enrolled,
            'device_synced_at' => $employee->device_synced_at?->format('d/m/Y H:i'),
        ];
    }
}
