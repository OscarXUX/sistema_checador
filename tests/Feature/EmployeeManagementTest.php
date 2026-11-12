<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

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
}

