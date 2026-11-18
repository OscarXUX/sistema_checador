<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\WorkShift;
use App\Services\AuditLogger;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'employeesJson' => $this->serializeEmployees(),
        ]);
    }

    public function list(): JsonResponse
    {
        return response()->json([
            'employees' => $this->serializeEmployees(),
        ]);
    }

    /**
     * Campos del empleado que se registran en la bitácora (RFC, CURP y NSS se guardan como "[protegido]").
     */
    private const AUDITED_FIELDS = [
        'hikvision_employee_no', 'full_name', 'rfc', 'curp', 'nss',
        'department', 'position', 'work_shift_id', 'is_active',
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
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
            $result = $isapi->triggerRemoteFingerprintCapture($employee->hikvision_employee_no, $fingerNo);
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
     * @return array<int, array<string, mixed>>
     */
    private function serializeEmployees(): array
    {
        return Employee::with('workShift')
            ->orderBy('hikvision_employee_no')
            ->get()
            ->map(fn (Employee $employee): array => $this->formatEmployeeRow($employee))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEmployeeRow(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'hikvision_employee_no' => $employee->hikvision_employee_no,
            'full_name' => $employee->full_name,
            'rfc' => $employee->rfc,
            'curp' => $employee->curp,
            'nss' => $employee->nss,
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
