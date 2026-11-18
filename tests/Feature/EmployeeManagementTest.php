<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Todas las rutas requieren sesión (SE-01)
        $this->actingAs(User::factory()->create());
    }

    private function seedShift(): WorkShift
    {
        return WorkShift::create([
            'name' => 'Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);
    }

    public function test_employee_crud_and_isapi_actions(): void
    {
        $shift = $this->seedShift();

        $this->get('/employees')->assertOk()->assertSee('Gestión de Empleados');

        $createRes = $this->postJson('/employees', [
            'hikvision_employee_no' => '4500',
            'full_name' => 'Sofía Herrera León',
            'rfc' => 'HELS940312MDF',
            'curp' => 'HELS940312MDFRRF02',
            'nss' => '12345678901',
            'department' => 'Ingeniería',
            'position' => 'QA Lead',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        $createRes->assertCreated()->assertJsonPath('success', true);
        $employee = Employee::where('hikvision_employee_no', '4500')->firstOrFail();

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response([
                'CaptureFingerPrint' => [
                    'fingerPrintData' => [
                        'fingerPrintID' => 2,
                        'fingerData' => 'PLANTILLA_BIOMETRICA_DE_PRUEBA',
                    ],
                ],
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/FingerPrint/SetUp?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Delete?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $this->postJson("/employees/{$employee->id}/sync-device")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson("/employees/{$employee->id}/capture-fingerprint", ['finger_no' => 2])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue($employee->fresh()->fingerprint_enrolled);

        $this->putJson("/employees/{$employee->id}", [
            'hikvision_employee_no' => '4500',
            'full_name' => 'Sofía Herrera Actualizada',
            'rfc' => 'HELS940312MDF',
            'curp' => 'HELS940312MDFRRF02',
            'nss' => '12345678901',
            'department' => 'Ingeniería',
            'position' => 'Engineering Manager',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ])->assertOk()->assertJsonPath('employee.position', 'Engineering Manager');

        $this->deleteJson("/employees/{$employee->id}", ['delete_from_device' => true])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('employees', ['id' => $employee->id]);
    }

    public function test_employee_validation_rejects_invalid_rfc_curp_and_nss(): void
    {
        $shift = $this->seedShift();

        $this->postJson('/employees', [
            'hikvision_employee_no' => '4501',
            'full_name' => 'Prueba Inválida',
            'rfc' => 'INVALIDO',
            'curp' => '1234',
            'nss' => 'ABC',
            'department' => 'Ventas',
            'position' => 'Asesor',
            'work_shift_id' => $shift->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rfc', 'curp', 'nss']);
    }

    private function seedEmployeeWithPunch(WorkShift $shift, string $employeeNo = '4600'): Employee
    {
        $employee = Employee::create([
            'hikvision_employee_no' => $employeeNo,
            'full_name' => 'Mario Ruiz Torres',
            'department' => 'Almacén',
            'position' => 'Montacarguista',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => now()->setTime(9, 0),
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['attendanceStatus' => 'checkIn'],
        ]);

        return $employee;
    }

    public function test_employee_with_attendance_is_deactivated_and_history_is_kept(): void
    {
        $employee = $this->seedEmployeeWithPunch($this->seedShift());

        $this->deleteJson("/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('deactivated', true);

        $this->assertDatabaseHas('employees', ['id' => $employee->id, 'is_active' => false]);
        $this->assertSame(1, AttendanceLog::where('employee_id', $employee->id)->count());
        $this->assertSame(1, DailySummary::where('employee_id', $employee->id)->count());
    }

    public function test_database_blocks_deleting_employee_with_attendance(): void
    {
        $employee = $this->seedEmployeeWithPunch($this->seedShift());

        $this->expectException(QueryException::class);

        $employee->delete();
    }

    public function test_fingerprint_capture_response_does_not_expose_template(): void
    {
        $shift = $this->seedShift();
        $employee = Employee::create([
            'hikvision_employee_no' => '4700',
            'full_name' => 'Laura Gómez Paz',
            'department' => 'Calidad',
            'position' => 'Inspectora',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response([
                'CaptureFingerPrint' => [
                    'fingerPrintData' => [
                        'fingerPrintID' => 1,
                        'fingerData' => 'PLANTILLA_BIOMETRICA_DE_PRUEBA',
                    ],
                ],
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/FingerPrint/SetUp?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $response = $this->postJson("/employees/{$employee->id}/capture-fingerprint", ['finger_no' => 1])
            ->assertOk()
            ->assertJsonPath('template_captured', true)
            ->assertJsonMissingPath('detail');

        $this->assertStringNotContainsString('PLANTILLA_BIOMETRICA_DE_PRUEBA', (string) $response->getContent());
    }
}
