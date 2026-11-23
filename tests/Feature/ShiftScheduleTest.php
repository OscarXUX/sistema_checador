<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\ShiftException;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationAssignment;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\ShiftScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftScheduleTest extends TestCase
{
    use RefreshDatabase;

    private WorkShift $shift1;

    private WorkShift $shift2;

    private WorkShift $shift3;

    private WorkShift $shiftDept;

    private WorkShift $shiftExc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->shift1 = WorkShift::create([
            'name' => 'Turno 1 Matutino', 'entry_time' => '07:00:00', 'exit_time' => '15:00:00',
            'meal_duration_minutes' => 30, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->shift2 = WorkShift::create([
            'name' => 'Turno 2 Vespertino', 'entry_time' => '15:00:00', 'exit_time' => '23:00:00',
            'meal_duration_minutes' => 30, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->shift3 = WorkShift::create([
            'name' => 'Turno 3 Nocturno', 'entry_time' => '23:00:00', 'exit_time' => '07:00:00',
            'meal_duration_minutes' => 30, 'tolerance_minutes' => 10, 'max_weekly_hours' => 42,
        ]);
        $this->shiftDept = WorkShift::create([
            'name' => 'Turno Depto', 'entry_time' => '08:00:00', 'exit_time' => '17:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->shiftExc = WorkShift::create([
            'name' => 'Turno Excepción', 'entry_time' => '10:00:00', 'exit_time' => '19:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
    }

    public function test_resolver_enforces_strict_priority_exception_employee_group_department_default(): void
    {
        $employee = Employee::create([
            'hikvision_employee_no' => '2001',
            'full_name' => 'Carlos Méndez',
            'department' => 'Producción',
            'position' => 'Operador',
            'work_shift_id' => $this->shift1->id,
            'is_active' => true,
        ]);

        $group = EmployeeGroup::create(['name' => 'Cuadrilla A']);
        $group->employees()->attach($employee->id);

        $rotDept = $this->createRotation('Rot Depto', 7, [$this->shiftDept->id]);
        $rotGroup = $this->createRotation('Rot Grupo', 7, [$this->shift2->id]);
        $rotEmp = $this->createRotation('Rot Emp', 7, [$this->shift3->id]);

        $deptAssign = ShiftRotationAssignment::create([
            'shift_rotation_id' => $rotDept->id,
            'target_type' => ShiftRotationAssignment::TARGET_DEPARTMENT,
            'department' => 'Producción',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $groupAssign = ShiftRotationAssignment::create([
            'shift_rotation_id' => $rotGroup->id,
            'target_type' => ShiftRotationAssignment::TARGET_GROUP,
            'employee_group_id' => $group->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ]);

        $empAssign = ShiftRotationAssignment::create([
            'shift_rotation_id' => $rotEmp->id,
            'target_type' => ShiftRotationAssignment::TARGET_EMPLOYEE,
            'employee_id' => $employee->id,
            'starts_on' => '2026-11-10',
            'ends_on' => '2026-11-20',
        ]);

        $exception = ShiftException::create([
            'employee_id' => $employee->id,
            'work_shift_id' => $this->shiftExc->id,
            'starts_on' => '2026-11-12',
            'ends_on' => '2026-11-12',
            'reason' => 'Permiso operativo',
        ]);

        $resolver = app(ShiftScheduleResolver::class);

        // 1. Excepción gana sobre todo
        $this->assertSame($this->shiftExc->id, $resolver->resolveForEmployeeOnDate($employee, CarbonImmutable::parse('2026-11-12'))?->id);
        $exception->delete();

        // 2. Empleado gana sobre grupo y departamento
        $this->assertSame($this->shift3->id, $resolver->resolveForEmployeeOnDate($employee, CarbonImmutable::parse('2026-11-12'))?->id);
        $empAssign->delete();

        // 3. Grupo gana sobre departamento
        $this->assertSame($this->shift2->id, $resolver->resolveForEmployeeOnDate($employee, CarbonImmutable::parse('2026-11-12'))?->id);
        $groupAssign->delete();

        // 4. Departamento gana sobre turno base
        $this->assertSame($this->shiftDept->id, $resolver->resolveForEmployeeOnDate($employee, CarbonImmutable::parse('2026-11-12'))?->id);
        $deptAssign->delete();

        // 5. Turno actual del empleado como respaldo
        $this->assertSame($this->shift1->id, $resolver->resolveForEmployeeOnDate($employee, CarbonImmutable::parse('2026-11-12'))?->id);
    }

    public function test_rotation_sequences_123_321_213_and_cycle_days_resolve_correctly(): void
    {
        $startsOn = CarbonImmutable::parse('2026-11-02');

        $rot123 = $this->createRotation('1-2-3', 7, [$this->shift1->id, $this->shift2->id, $this->shift3->id]);
        $rot321 = $this->createRotation('3-2-1', 7, [$this->shift3->id, $this->shift2->id, $this->shift1->id]);
        $rot213 = $this->createRotation('2-1-3', 3, [$this->shift2->id, $this->shift1->id, $this->shift3->id]);

        // 1->2->3 semanal (7 días)
        $this->assertSame($this->shift1->id, $rot123->resolveShiftForDate(CarbonImmutable::parse('2026-11-02'), $startsOn)?->id);
        $this->assertSame($this->shift1->id, $rot123->resolveShiftForDate(CarbonImmutable::parse('2026-11-08'), $startsOn)?->id);
        $this->assertSame($this->shift2->id, $rot123->resolveShiftForDate(CarbonImmutable::parse('2026-11-09'), $startsOn)?->id);
        $this->assertSame($this->shift3->id, $rot123->resolveShiftForDate(CarbonImmutable::parse('2026-11-16'), $startsOn)?->id);
        $this->assertSame($this->shift1->id, $rot123->resolveShiftForDate(CarbonImmutable::parse('2026-11-23'), $startsOn)?->id);

        // 3->2->1 semanal (7 días)
        $this->assertSame($this->shift3->id, $rot321->resolveShiftForDate(CarbonImmutable::parse('2026-11-02'), $startsOn)?->id);
        $this->assertSame($this->shift2->id, $rot321->resolveShiftForDate(CarbonImmutable::parse('2026-11-09'), $startsOn)?->id);
        $this->assertSame($this->shift1->id, $rot321->resolveShiftForDate(CarbonImmutable::parse('2026-11-16'), $startsOn)?->id);

        // 2->1->3 ciclo corto (3 días)
        $this->assertSame($this->shift2->id, $rot213->resolveShiftForDate(CarbonImmutable::parse('2026-11-02'), $startsOn)?->id);
        $this->assertSame($this->shift1->id, $rot213->resolveShiftForDate(CarbonImmutable::parse('2026-11-05'), $startsOn)?->id);
        $this->assertSame($this->shift3->id, $rot213->resolveShiftForDate(CarbonImmutable::parse('2026-11-08'), $startsOn)?->id);
    }

    public function test_saving_exception_recalculates_attendance_summaries_automatically(): void
    {
        $shift09 = WorkShift::create([
            'name' => 'Oficina 09-18', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);

        // Base shift es 09:00, así que checar a las 08:00 sería a tiempo.
        $employee = Employee::create([
            'hikvision_employee_no' => '2002',
            'full_name' => 'Laura Ríos',
            'department' => 'Sistemas',
            'position' => 'Ingeniera',
            'work_shift_id' => $shift09->id,
            'is_active' => true,
        ]);

        // Rotación previa fija turno de 07:00
        $rot07 = $this->createRotation('Rot 07', 7, [$this->shift1->id]);
        $this->post('/shifts/assignments', [
            'shift_rotation_id' => $rot07->id,
            'target_type' => 'employee',
            'employee_id' => $employee->id,
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ])->assertRedirect();

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-10 08:00:00',
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['minor' => 38],
        ]);
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-10 18:00:00',
            'event_type' => AttendanceLog::EVENT_CHECK_OUT,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['minor' => 38],
        ]);

        // Con rotación 07:00, entrar a las 08:00 genera 60 min de retardo
        $summary = DailySummary::where('employee_id', $employee->id)->where('work_date', '2026-11-10')->firstOrFail();
        $this->assertSame(60, $summary->late_minutes);
        $this->assertSame(DailySummary::STATUS_LATE, $summary->status);

        // Guardar excepción a 09:00 debe disparar attendance:recalculate automáticamente y dejar late_minutes = 0
        $this->post('/shifts/exceptions', [
            'employee_id' => $employee->id,
            'work_shift_id' => $shift09->id,
            'starts_on' => '2026-11-10',
            'ends_on' => '2026-11-10',
            'reason' => 'Cambio autorizado',
        ])->assertRedirect();

        $summary->refresh();
        $this->assertSame(0, $summary->late_minutes);
        $this->assertSame(DailySummary::STATUS_ON_TIME, $summary->status);

        $this->artisan('attendance:recalculate', [
            '--from' => '2026-11-10',
            '--to' => '2026-11-10',
            '--employee' => $employee->id,
        ])->assertExitCode(0);
    }

    public function test_shifts_view_renders_four_tabs_and_14_day_preview(): void
    {
        $this->withoutVite();

        $response = $this->get('/shifts?tab=rotaciones')->assertOk();

        $response->assertSee('id="tab-turnos"', false);
        $response->assertSee('id="tab-rotaciones"', false);
        $response->assertSee('id="tab-asignaciones"', false);
        $response->assertSee('id="tab-excepciones"', false);
        $response->assertSee('Vista Previa de 14 Días');
    }

    /**
     * @param  list<int>  $shiftIds
     */
    private function createRotation(string $name, int $cycleDays, array $shiftIds): ShiftRotation
    {
        $rotation = ShiftRotation::create([
            'name' => $name,
            'cycle_days' => $cycleDays,
            'is_active' => true,
        ]);

        foreach (array_values($shiftIds) as $idx => $shiftId) {
            $rotation->steps()->create([
                'work_shift_id' => $shiftId,
                'step_order' => $idx + 1,
            ]);
        }

        return $rotation;
    }
}
