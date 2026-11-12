<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_kardex_shifts_and_device_endpoints_render_cleanly(): void
    {
        $shift = WorkShift::create([
            'name' => 'Administrativo Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);

        $employee = Employee::create([
            'hikvision_employee_no' => '1001',
            'full_name' => 'Ana Sofía Mendoza Ríos',
            'rfc' => 'MERA920415HDF',
            'curp' => 'MERA920415MDFNNS01',
            'nss' => '11920415123',
            'department' => 'Ingeniería',
            'position' => 'Arquitecta de Software',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => now()->startOfDay()->setTime(8, 55),
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['attendanceStatus' => 'checkIn'],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Empleados en Planta')
            ->assertSee('En Hora de Comida')
            ->assertSee('Retardos Hoy')
            ->assertSee('Horas Extra (Semana)');

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonStructure([
                'kpis' => ['active_employees', 'in_plant', 'in_meal', 'meal_exceeded_count', 'late_today', 'weekly_overtime_hours'],
                'charts' => ['punctuality', 'departments'],
                'live_logs',
            ]);

        $this->get('/reports/kardex?department=Ingenier%C3%ADa')
            ->assertOk()
            ->assertSee('Kardex Diario Calculado');

        $this->getJson('/reports/kardex/data?department=Ingenier%C3%ADa')
            ->assertOk()
            ->assertJsonStructure(['filters', 'kardex_rows', 'audit_logs']);

        $this->get('/shifts')
            ->assertOk()
            ->assertSee('Administrativo Matutino');

        Http::fake([
            'http://192.168.0.69/ISAPI/System/deviceInfo*' => Http::response([
                'DeviceInfo' => [
                    'model' => 'DS-K1T804AEF',
                    'serialNumber' => 'DS-K1T804AEF-01',
                ],
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'InfoList' => [],
                ],
            ], 200),
        ]);

        $this->getJson('/device/status')
            ->assertOk()
            ->assertJsonPath('online', true);

        $this->postJson('/device/sync')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
