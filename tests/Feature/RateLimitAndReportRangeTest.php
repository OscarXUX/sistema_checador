<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitAndReportRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Todas las rutas requieren sesión (SE-01)
        $this->actingAs(User::factory()->create());
    }

    public function test_device_sync_is_rate_limited(): void
    {
        Http::fake([
            '*' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'totalMatches' => 0,
                    'InfoList' => [],
                ],
            ], 200),
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/device/sync')->assertOk();
        }

        $this->postJson('/device/sync')
            ->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Demasiadas solicitudes. Espera un momento e intenta de nuevo.');
    }

    public function test_reports_are_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/reports/kardex/data')->assertOk();
        }

        $this->getJson('/reports/kardex/data')->assertStatus(429);
    }

    public function test_kardex_range_is_limited_to_max_days(): void
    {
        $response = $this->getJson('/reports/kardex/data?start_date=2025-01-01&end_date=2026-11-17')
            ->assertOk()
            ->assertJsonPath('filters.end_date', '2026-11-17')
            ->assertJsonPath('filters.range_adjusted', true);

        $expectedStart = now()->parse('2026-11-17')->subDays(ReportController::MAX_RANGE_DAYS - 1)->toDateString();
        $response->assertJsonPath('filters.start_date', $expectedStart);
    }

    public function test_kardex_range_within_limit_is_not_adjusted(): void
    {
        $this->getJson('/reports/kardex/data?start_date=2026-11-01&end_date=2026-11-17')
            ->assertOk()
            ->assertJsonPath('filters.start_date', '2026-11-01')
            ->assertJsonPath('filters.range_adjusted', false);
    }

    public function test_kardex_page_warns_when_range_is_adjusted(): void
    {
        $this->withoutVite();

        $this->get('/reports/kardex?start_date=2025-01-01&end_date=2026-11-17')
            ->assertOk()
            ->assertSee('El rango máximo es de 93 días', false);
    }

    public function test_kardex_audit_logs_load_raw_payload_on_demand(): void
    {
        $shift = WorkShift::create([
            'name' => 'Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);

        $employee = Employee::create([
            'hikvision_employee_no' => '9010',
            'full_name' => 'Clara Soto Díaz',
            'department' => 'Finanzas',
            'position' => 'Contadora',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => now()->setTime(9, 1, 0),
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['employeeNoString' => '9010', 'serialNo' => 778899],
        ]);

        $this->getJson('/reports/kardex/data')
            ->assertOk()
            ->assertJsonMissingPath('audit_logs.0.raw_payload');

        $detail = $this->getJson("/reports/kardex/logs/{$log->id}/payload")
            ->assertOk()
            ->assertJsonPath('id', $log->id)
            ->assertJsonPath('employee_no', '9010');

        $this->assertStringContainsString('778899', (string) $detail->json('raw_payload'));
    }
}
