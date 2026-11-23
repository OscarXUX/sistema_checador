<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Models\WorkShift;
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

    public function store(StoreEmployeeRequest $request): JsonResponse|RedirectResponse
    {
        $employee = Employee::create($request->validated());
        $employee->load('workShift');

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
        $employee->update($request->validated());
        $employee->load('workShift');

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

    public function destroy(
        Request $request,
        Employee $employee,
        HikvisionIsapiService $isapi
    ): JsonResponse|RedirectResponse {
        $employeeNo = $employee->hikvision_employee_no;
        $employeeName = $employee->full_name;
        $deviceNotice = null;

        if ($request->boolean('delete_from_device')) {
            try {
                $isapi->deleteUserFromDevice($employeeNo);
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

        $employee->delete();

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Empleado {$employeeName} eliminado del sistema.{$deviceNotice}",
            ]);
        }

        return redirect()
            ->route('employees.index')
            ->with('status', "Empleado {$employeeName} eliminado del sistema.{$deviceNotice}");
    }

    public function syncToDevice(Employee $employee, HikvisionIsapiService $isapi): JsonResponse
    {
        try {
            $result = $isapi->syncUserToDevice($employee);
            $employee->refresh()->load('workShift');

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
