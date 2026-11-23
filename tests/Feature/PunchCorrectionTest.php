<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\AttendanceLogCorrection;
use App\Models\AuditLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\PunchClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corrección manual de checadas e incidencias en el dashboard (paquete 3, propuesta 3.3).
 */
class PunchCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = WorkShift::create([
            'name' => 'Administrativo', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->employee = Employee::create([
            'hikvision_employee_no' => '4001', 'full_name' => 'Luis Mora', 'department' => 'Almacén',
            'position' => 'Auxiliar', 'work_shift_id' => $shift->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $times
     * @return array<string, AttendanceLog>
     */
    private function punch(array $times, string $date = '2026-11-10'): array
    {
        $lstChecadas = [];
        foreach ($times as $time) {
            $lstChecadas[$time] = AttendanceLog::create([
                'employee_id' => $this->employee->id, 'device_ip' => '192.168.0.69', 'punched_at' => "{$date} {$time}",
                'event_type' => AttendanceLog::EVENT_UNCLASSIFIED, 'verification_mode' => 'fingerprint', 'raw_payload' => [],
            ]);
        }

        return $lstChecadas;
    }

    private function summary(string $date = '2026-11-10'): DailySummary
    {
        return DailySummary::where('employee_id', $this->employee->id)->where('work_date', $date)->firstOrFail();
    }

    public function test_correcting_a_repeated_entry_as_break_out_recalculates_the_day(): void
    {
        // 10:30 cae en la ventana de entrada (08:00-11:00): por omisión es "entrada repetida"
        $lstChecadas = $this->punch(['09:00:00', '10:30:00', '11:30:00', '18:00:00']);
        $this->assertContains(PunchClassifier::INCIDENT_EXTRA_ENTRY, array_column($this->summary()->incidents, 'code'));

        $this->actingAs(User::factory()->create());
        $this->postJson("/reports/kardex/punches/{$lstChecadas['10:30:00']->id}/correction", [
            'corrected_type' => AttendanceLog::EVENT_BREAK_OUT, 'reason' => 'Salió a comer temprano, lo confirmó su jefe',
        ])->assertOk()->assertJsonPath('success', true);

        $summary = $this->summary();
        $this->assertSame('10:30:00', $summary->break_out->format('H:i:s'));
        $this->assertSame('11:30:00', $summary->break_in->format('H:i:s'));
        $this->assertNull($summary->incidents);
        $this->assertSame(1, $summary->corrected_punches);

        // La checada original no cambia
        $this->assertSame(AttendanceLog::EVENT_UNCLASSIFIED, $lstChecadas['10:30:00']->fresh()->event_type);
        $this->assertTrue(AuditLog::where('action', 'attendance.corrected')->exists());
    }

    public function test_ignored_punch_does_not_count(): void
    {
        $lstChecadas = $this->punch(['09:00:00', '13:00:00', '18:00:00']);
        $this->assertSame('13:00:00', $this->summary()->break_out->format('H:i:s'));

        $this->actingAs(User::factory()->create());
        $this->postJson("/reports/kardex/punches/{$lstChecadas['13:00:00']->id}/correction", [
            'corrected_type' => AttendanceLogCorrection::TYPE_IGNORE, 'reason' => 'Puso el dedo por error',
        ])->assertOk();

        $this->assertNull($this->summary()->break_out);
        $this->assertSame(540, $this->summary()->worked_minutes);
    }

    public function test_corrected_entry_wins_over_the_window(): void
    {
        $lstChecadas = $this->punch(['08:30:00', '09:30:00', '18:00:00']);
        $this->assertSame('08:30:00', $this->summary()->first_check_in->format('H:i:s'));

        $this->actingAs(User::factory()->create());
        $this->postJson("/reports/kardex/punches/{$lstChecadas['09:30:00']->id}/correction", [
            'corrected_type' => AttendanceLog::EVENT_CHECK_IN, 'reason' => 'La de 08:30 fue de otra persona con su tarjeta',
        ])->assertOk();

        $summary = $this->summary();
        $this->assertSame('09:30:00', $summary->first_check_in->format('H:i:s'));
        $this->assertSame(30, $summary->late_minutes);
        $this->assertSame([PunchClassifier::INCIDENT_EXTRA_ENTRY], array_column($summary->incidents, 'code'));
    }

    public function test_removing_the_correction_restores_the_automatic_classification(): void
    {
        $lstChecadas = $this->punch(['09:00:00', '13:00:00', '18:00:00']);
        $this->actingAs(User::factory()->create());
        $url = "/reports/kardex/punches/{$lstChecadas['13:00:00']->id}/correction";

        $this->postJson($url, ['corrected_type' => AttendanceLogCorrection::TYPE_IGNORE, 'reason' => 'Prueba de quitar'])->assertOk();
        $this->deleteJson($url)->assertOk();

        $this->assertSame('13:00:00', $this->summary()->break_out->format('H:i:s'));
        $this->assertSame(0, $this->summary()->corrected_punches);
        $this->assertTrue(AuditLog::where('action', 'attendance.correction_removed')->exists());
        $this->deleteJson($url)->assertNotFound();
    }

    public function test_reason_and_type_are_required(): void
    {
        $lstChecadas = $this->punch(['09:00:00']);
        $this->actingAs(User::factory()->create());
        $url = "/reports/kardex/punches/{$lstChecadas['09:00:00']->id}/correction";

        $this->postJson($url, ['corrected_type' => 'check_in', 'reason' => ''])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson($url, ['corrected_type' => 'otra_cosa', 'reason' => 'Motivo válido'])->assertStatus(422)->assertJsonValidationErrors('corrected_type');
        $this->assertSame(0, AttendanceLogCorrection::count());
    }

    public function test_day_endpoint_lists_punches_roles_and_incidents(): void
    {
        $this->punch(['09:00:00', '09:01:00', '18:00:00']);
        $this->actingAs(User::factory()->withProfile('Consulta')->create());

        $this->getJson("/reports/kardex/summaries/{$this->summary()->id}/punches")
            ->assertOk()
            ->assertJsonPath('can_correct', false)
            ->assertJsonPath('punches.0.role', AttendanceLog::EVENT_CHECK_IN)
            ->assertJsonPath('punches.1.role', 'not_counted')
            ->assertJsonPath('punches.1.incidents.0', 'Checada repetida')
            ->assertJsonPath('punches.2.role_label', 'Salida');
    }

    public function test_query_profile_cannot_correct(): void
    {
        $lstChecadas = $this->punch(['09:00:00']);
        $this->actingAs(User::factory()->withProfile('Consulta')->create());

        $this->postJson("/reports/kardex/punches/{$lstChecadas['09:00:00']->id}/correction", [
            'corrected_type' => 'ignore', 'reason' => 'No debería poder',
        ])->assertForbidden();
    }

    public function test_dashboard_shows_todays_incidents(): void
    {
        $this->travelTo('2026-11-10 12:00:00');
        $this->punch(['09:00:00', '09:01:00']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/dashboard/data')
            ->assertOk()
            ->assertJsonPath('kpis.incidents_today', 1)
            ->assertJsonPath('kpis.incident_alerts.0.label', 'Checada repetida')
            ->assertJsonPath('kpis.incident_alerts.0.at', '09:01:00');

        $this->withoutVite()->get('/')->assertOk()->assertSee('Incidencias de checadas hoy');
    }
}
