<?php

namespace Tests\Feature;

use App\Models\AttendanceEventType;
use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\PunchClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paquete 3 (notas 3, 4 y 6 de Oscar): clasificación por ventanas de horario, checadas repetidas o
 * fuera de orden y catálogo de tipos de evento.
 */
class AttendanceRulesTest extends TestCase
{
    use RefreshDatabase;

    private WorkShift $shift;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Turno 09:00-18:00, tolerancia 10, comida 60. Ventanas por omisión: entrada 08:00-11:00, salida 17:00-21:00
        $this->shift = WorkShift::create([
            'name' => 'Administrativo', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->employee = Employee::create([
            'hikvision_employee_no' => '3001', 'full_name' => 'Ana Ruiz', 'department' => 'Calidad',
            'position' => 'Inspectora', 'work_shift_id' => $this->shift->id, 'is_active' => true,
        ]);
    }

    /**
     * @param  list<string|array{0: string, 1: string}>  $punches  hora o [hora, tecla]
     */
    private function punch(array $punches, string $date = '2026-11-10'): DailySummary
    {
        foreach ($punches as $punch) {
            [$time, $key] = is_array($punch) ? $punch : [$punch, AttendanceLog::EVENT_UNCLASSIFIED];
            AttendanceLog::create([
                'employee_id' => $this->employee->id, 'device_ip' => '192.168.0.69',
                'punched_at' => "{$date} {$time}", 'event_type' => $key,
                'verification_mode' => 'fingerprint', 'raw_payload' => [],
            ]);
        }

        return DailySummary::with(['entryEventType', 'exitEventType', 'mealEventType'])
            ->where('employee_id', $this->employee->id)->where('work_date', $date)->firstOrFail();
    }

    /**
     * @return list<string>
     */
    private function incidentCodes(DailySummary $summary): array
    {
        return array_column($summary->incidents ?? [], 'code');
    }

    public function test_repeated_punch_is_ignored_and_reported(): void
    {
        $summary = $this->punch(['09:00:00', '09:01:30', '14:00:00', '15:00:00', '18:05:00']);

        $this->assertSame('09:00:00', $summary->first_check_in->format('H:i:s'));
        $this->assertSame('14:00:00', $summary->break_out->format('H:i:s'));
        $this->assertSame('15:00:00', $summary->break_in->format('H:i:s'));
        $this->assertSame('18:05:00', $summary->last_check_out->format('H:i:s'));
        $this->assertSame([PunchClassifier::INCIDENT_REPEATED], $this->incidentCodes($summary));
        $this->assertSame('a_tiempo', $summary->entryEventType->code);
        $this->assertSame('comida_normal', $summary->mealEventType->code);
        $this->assertSame('salida_normal', $summary->exitEventType->code);
    }

    public function test_two_entries_keep_the_first_and_report_the_second(): void
    {
        $summary = $this->punch(['08:50:00', '09:30:00', '18:00:00']);

        $this->assertSame('08:50:00', $summary->first_check_in->format('H:i:s'));
        $this->assertNull($summary->break_out);
        $this->assertSame([PunchClassifier::INCIDENT_EXTRA_ENTRY], $this->incidentCodes($summary));
    }

    public function test_exit_without_entry_is_reported_and_day_is_incomplete(): void
    {
        $summary = $this->punch(['18:10:00']);

        $this->assertNull($summary->first_check_in);
        $this->assertSame('18:10:00', $summary->last_check_out->format('H:i:s'));
        $this->assertSame([PunchClassifier::INCIDENT_EXIT_WITHOUT_ENTRY], $this->incidentCodes($summary));
        $this->assertSame(DailySummary::STATUS_INCOMPLETE, $summary->status);
    }

    public function test_first_punch_between_windows_is_a_late_entry(): void
    {
        $summary = $this->punch(['12:00:00', '18:00:00']);

        $this->assertSame('12:00:00', $summary->first_check_in->format('H:i:s'));
        $this->assertNull($summary->break_out);
        $this->assertSame(180, $summary->late_minutes);
        $this->assertSame('retardo_mayor', $summary->entryEventType->code);
    }

    public function test_last_punch_of_a_closed_day_is_an_early_exit(): void
    {
        $summary = $this->punch(['09:00:00', '15:00:00']);

        $this->assertSame('15:00:00', $summary->last_check_out->format('H:i:s'));
        $this->assertNull($summary->break_out);
        $this->assertSame(180, $summary->early_exit_minutes);
        $this->assertSame('salida_anticipada', $summary->exitEventType->code);
    }

    public function test_checkout_key_between_windows_counts_as_exit(): void
    {
        $summary = $this->punch(['09:00:00', '13:00:00', '14:00:00', ['16:00:00', AttendanceLog::EVENT_CHECK_OUT]]);

        $this->assertSame('13:00:00', $summary->break_out->format('H:i:s'));
        $this->assertSame('14:00:00', $summary->break_in->format('H:i:s'));
        $this->assertSame('16:00:00', $summary->last_check_out->format('H:i:s'));
        $this->assertSame(120, $summary->early_exit_minutes);
    }

    public function test_late_minutes_choose_between_retardo_and_retardo_mayor(): void
    {
        $this->assertSame('retardo', $this->punch(['09:12:00', '18:00:00'], '2026-11-10')->entryEventType->code);
        $this->assertSame('retardo_mayor', $this->punch(['09:20:00', '18:00:00'], '2026-11-11')->entryEventType->code);
    }

    public function test_meal_over_the_allowed_minutes_is_comida_excedida(): void
    {
        $summary = $this->punch(['09:00:00', '14:00:00', '15:25:00', '18:00:00']);

        $this->assertSame(25, $summary->meal_exceeded_minutes);
        $this->assertSame('comida_excedida', $summary->mealEventType->code);
    }

    public function test_punch_before_entry_window_is_out_of_window(): void
    {
        $summary = $this->punch(['06:30:00', '18:00:00']);

        $this->assertSame('06:30:00', $summary->first_check_in->format('H:i:s'));
        $this->assertSame([PunchClassifier::INCIDENT_OUT_OF_WINDOW], $this->incidentCodes($summary));
    }

    public function test_custom_windows_change_the_classification(): void
    {
        // Por omisión (entrada 08:00-11:00) 10:30 es una segunda entrada
        $summary = $this->punch(['09:00:00', '10:30:00', '11:15:00', '18:00:00']);
        $this->assertSame([PunchClassifier::INCIDENT_EXTRA_ENTRY], $this->incidentCodes($summary));

        // Con entrada 07:00-10:00, 10:30 y 11:15 son la comida
        $this->actingAs(User::factory()->create());
        $this->put("/catalogos/ventanas/{$this->shift->id}", [
            'entry_window_start' => '07:00', 'entry_window_end' => '10:00',
            'exit_window_start' => '17:00', 'exit_window_end' => '20:00',
            'repeat_threshold_minutes' => 3,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $summary->refresh();
        $this->assertSame('10:30:00', $summary->break_out->format('H:i:s'));
        $this->assertSame('11:15:00', $summary->break_in->format('H:i:s'));
        $this->assertSame([], $this->incidentCodes($summary));
        $this->assertTrue(AuditLog::where('action', 'shift.windows_updated')->exists());
    }

    public function test_windows_must_contain_shift_hours_and_not_overlap(): void
    {
        $this->actingAs(User::factory()->create());
        $base = ['exit_window_start' => '17:00', 'exit_window_end' => '20:00', 'repeat_threshold_minutes' => 3];

        $this->put("/catalogos/ventanas/{$this->shift->id}", [...$base, 'entry_window_start' => '09:30', 'entry_window_end' => '11:00'])
            ->assertSessionHasErrors('entry_window_start');

        $this->put("/catalogos/ventanas/{$this->shift->id}", [...$base, 'entry_window_start' => '08:00', 'entry_window_end' => '17:30'])
            ->assertSessionHasErrors('exit_window_start');

        $this->put("/catalogos/ventanas/{$this->shift->id}", [...$base, 'entry_window_start' => '08:00'])
            ->assertSessionHasErrors('entry_window_end');

        $this->assertFalse($this->shift->fresh()->hasCustomWindows());
    }

    public function test_use_defaults_clears_custom_windows(): void
    {
        $this->shift->update(['entry_window_start' => '07:00', 'entry_window_end' => '10:00', 'exit_window_start' => '17:00', 'exit_window_end' => '20:00']);
        $this->actingAs(User::factory()->create());

        $this->put("/catalogos/ventanas/{$this->shift->id}", ['use_defaults' => '1', 'repeat_threshold_minutes' => 5])->assertSessionHasNoErrors();

        $shift = $this->shift->fresh();
        $this->assertFalse($shift->hasCustomWindows());
        $this->assertSame(5, $shift->repeat_threshold_minutes);
        $this->assertSame(['08:00', '11:00'], $shift->windowTimes()['entrada']);
    }

    public function test_night_shift_windows_cross_midnight(): void
    {
        $night = WorkShift::create([
            'name' => 'Nocturno', 'entry_time' => '22:00:00', 'exit_time' => '06:00:00',
            'meal_duration_minutes' => 45, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->employee->update(['work_shift_id' => $night->id]);

        foreach (['2026-11-12 22:05:00', '2026-11-13 02:00:00', '2026-11-13 02:40:00', '2026-11-13 06:10:00'] as $at) {
            AttendanceLog::create([
                'employee_id' => $this->employee->id, 'device_ip' => '192.168.0.69', 'punched_at' => $at,
                'event_type' => AttendanceLog::EVENT_UNCLASSIFIED, 'verification_mode' => 'fingerprint', 'raw_payload' => [],
            ]);
        }

        $summary = DailySummary::where('employee_id', $this->employee->id)->where('work_date', '2026-11-12')->firstOrFail();
        $this->assertSame('2026-11-12 22:05:00', $summary->first_check_in->format('Y-m-d H:i:s'));
        $this->assertSame(40, $summary->meal_minutes);
        $this->assertSame('2026-11-13 06:10:00', $summary->last_check_out->format('Y-m-d H:i:s'));
        $this->assertNull($summary->incidents);
    }

    public function test_editing_event_types_reclassifies_summaries(): void
    {
        $summary = $this->punch(['09:20:00', '18:00:00']);
        $this->assertSame('retardo_mayor', $summary->entryEventType->code);

        $this->actingAs(User::factory()->create());
        $mayor = AttendanceEventType::where('code', 'retardo_mayor')->firstOrFail();
        $retardo = AttendanceEventType::where('code', 'retardo')->firstOrFail();

        // Se cruza con "Retardo mayor" (16 o más)
        $this->put("/catalogos/tipos-evento/{$retardo->id}", ['name' => 'Retardo', 'min_minutes' => 1, 'max_minutes' => 30, 'color' => 'warning', 'is_active' => '1'])
            ->assertSessionHasErrors('min_minutes');

        $this->put("/catalogos/tipos-evento/{$mayor->id}", ['name' => 'Retardo mayor', 'min_minutes' => 31, 'max_minutes' => '', 'color' => 'danger', 'is_active' => '1'])
            ->assertSessionHasNoErrors();
        $this->put("/catalogos/tipos-evento/{$retardo->id}", ['name' => 'Retardo', 'min_minutes' => 1, 'max_minutes' => 30, 'color' => 'warning', 'is_active' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('retardo', $summary->fresh()->entryEventType->code);
        $this->assertTrue(AuditLog::where('action', 'event_type.updated')->exists());
    }

    public function test_custom_event_type_can_be_created_and_deleted_but_system_ones_cannot(): void
    {
        $this->actingAs(User::factory()->create());
        AttendanceEventType::where('code', 'salida_anticipada')->update(['min_minutes' => 31]);

        $this->post('/catalogos/tipos-evento', ['name' => 'Salida un poco antes', 'applies_to' => 'salida', 'min_minutes' => 1, 'max_minutes' => 30, 'color' => 'info'])
            ->assertSessionHasNoErrors();
        $created = AttendanceEventType::where('code', 'salida_un_poco_antes')->firstOrFail();

        $summary = $this->punch(['09:00:00', ['17:45:00', AttendanceLog::EVENT_CHECK_OUT]]);
        $this->assertSame('salida_un_poco_antes', $summary->exitEventType->code);

        $system = AttendanceEventType::where('code', 'a_tiempo')->firstOrFail();
        $this->delete("/catalogos/tipos-evento/{$system->id}")->assertSessionHasErrors('event_type');
        $this->delete("/catalogos/tipos-evento/{$created->id}")->assertSessionHasNoErrors();

        $this->assertNull($summary->fresh()->exit_event_type_id);
    }

    public function test_catalog_pages_follow_permissions(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create());
        $this->get('/catalogos/ventanas')->assertOk()->assertSee('Administrativo')->assertSee('08:00');
        $this->get('/catalogos/tipos-evento')->assertOk()->assertSee('Retardo mayor');

        $this->actingAs(User::factory()->withProfile('Consulta')->create());
        $this->get('/catalogos/ventanas')->assertForbidden();
        $this->put("/catalogos/ventanas/{$this->shift->id}", ['use_defaults' => '1', 'repeat_threshold_minutes' => 3])->assertForbidden();
    }

    public function test_kardex_data_includes_events_and_incidents(): void
    {
        $this->punch(['09:20:00', '09:21:00', '18:00:00']);
        $this->actingAs(User::factory()->create());

        $this->getJson('/reports/kardex/data?start_date=2026-11-10&end_date=2026-11-10')
            ->assertOk()
            ->assertJsonPath('kardex_rows.0.events.0.name', 'Retardo mayor')
            ->assertJsonPath('kardex_rows.0.incidents.0.label', 'Checada repetida')
            ->assertJsonPath('kardex_rows.0.incidents.0.at', '09:21:00');
    }
}
