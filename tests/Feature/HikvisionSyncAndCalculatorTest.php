<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\AttendanceCalculatorService;
use App\Services\HikvisionIsapiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class HikvisionSyncAndCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function createShiftAndEmployee(string $empNo = '1001'): Employee
    {
        $shift = WorkShift::create([
            'name' => 'Administrativo',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);

        return Employee::create([
            'hikvision_employee_no' => $empNo,
            'full_name' => 'Carlos Vega',
            'rfc' => 'VELC881103MNL',
            'curp' => 'VELC881103HNLLRG04',
            'nss' => '22881103456',
            'department' => 'Ingeniería',
            'position' => 'DevOps',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);
    }

    public function test_calculates_tardiness_meal_net_worked_and_overtime_on_explicit_punches(): void
    {
        $employee = $this->createShiftAndEmployee('1001');

        // Jornada ordinaria = 480 min (09:00 a 18:00 - 60m comida)
        // Entrada 09:20 (+20 min retardo > 10m tolerancia)
        // Comida 14:00 a 14:50 (50 min <= 60m)
        // Salida 19:20 -> bruto = 600 min, neto = 550 min -> extra = 70 min
        $events = [
            ['2026-11-10 09:20:00', 'check_in'],
            ['2026-11-10 14:00:00', 'break_out'],
            ['2026-11-10 14:50:00', 'break_in'],
            ['2026-11-10 19:20:00', 'check_out'],
        ];

        foreach ($events as [$at, $type]) {
            AttendanceLog::create([
                'employee_id' => $employee->id,
                'device_ip' => '192.168.0.69',
                'punched_at' => $at,
                'event_type' => $type,
                'verification_mode' => 'fingerprint',
                'raw_payload' => ['attendanceStatus' => $type],
            ]);
        }

        $summary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-10')
            ->firstOrFail();

        $this->assertSame(20, $summary->late_minutes);
        $this->assertSame(50, $summary->meal_minutes);
        $this->assertSame(550, $summary->worked_minutes);
        $this->assertSame(70, $summary->overtime_minutes);
        $this->assertSame(DailySummary::STATUS_LATE, $summary->status);
    }

    public function test_applies_chronological_fallback_and_meal_exceeded_status_for_unclassified_punches(): void
    {
        $employee = $this->createShiftAndEmployee('1007');

        // 4 checadas sin tecla de estado (unclassified):
        // 1ª=09:05 (en tolerancia), 2ª=14:00, 3ª=15:25 (85m > 60m comida), 4ª=18:25
        $punches = [
            '2026-11-10 09:05:00',
            '2026-11-10 14:00:00',
            '2026-11-10 15:25:00',
            '2026-11-10 18:25:00',
        ];

        foreach ($punches as $at) {
            AttendanceLog::create([
                'employee_id' => $employee->id,
                'device_ip' => '192.168.0.69',
                'punched_at' => $at,
                'event_type' => AttendanceLog::EVENT_UNCLASSIFIED,
                'verification_mode' => 'card',
                'raw_payload' => ['attendanceStatus' => ''],
            ]);
        }

        $summary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-10')
            ->firstOrFail();

        $this->assertSame(0, $summary->late_minutes);
        $this->assertSame(85, $summary->meal_minutes);
        // Bruto: 09:05 -> 18:25 = 560 min; neto = 560 - 85 = 475 min
        $this->assertSame(475, $summary->worked_minutes);
        $this->assertSame(0, $summary->overtime_minutes);
        $this->assertSame(DailySummary::STATUS_MEAL_EXCEEDED, $summary->status);
    }

    public function test_attendance_logs_are_immutable_for_fiscal_audit(): void
    {
        $employee = $this->createShiftAndEmployee('1009');

        $log = AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-10 08:59:00',
            'event_type' => 'check_in',
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['test' => true],
        ]);

        $this->expectException(LogicException::class);
        $log->update(['event_type' => 'check_out']);
    }

    public function test_hikvision_sync_command_prevents_duplicates_and_recalculates_summary(): void
    {
        $employee = $this->createShiftAndEmployee('1001');

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'totalMatches' => 2,
                    'numOfMatches' => 2,
                    'InfoList' => [
                        [
                            'employeeNoString' => '1001',
                            'time' => '2026-11-11T08:55:00-06:00',
                            'attendanceStatus' => 'checkIn',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '1001',
                            'time' => '2026-11-11T18:05:00-06:00',
                            'attendanceStatus' => 'checkOut',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Primera corrida
        $this->artisan('hikvision:sync', [
            '--from' => '2026-11-11',
            '--to' => '2026-11-11',
        ])->assertSuccessful();

        // Segunda corrida con los mismos eventos -> sin duplicados
        $this->artisan('hikvision:sync', [
            '--from' => '2026-11-11',
            '--to' => '2026-11-11',
        ])->assertSuccessful();

        $this->assertSame(2, AttendanceLog::where('employee_id', $employee->id)->count());

        $summary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-11')
            ->firstOrFail();

        $this->assertSame(DailySummary::STATUS_ON_TIME, $summary->status);
        $this->assertSame(550, $summary->worked_minutes);
        $this->assertSame(70, $summary->overtime_minutes);
    }

    public function test_overnight_shift_groups_punches_into_shift_start_date(): void
    {
        $shift = WorkShift::create([
            'name' => 'Nocturno',
            'entry_time' => '22:00:00',
            'exit_time' => '06:00:00',
            'meal_duration_minutes' => 45,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);

        $employee = Employee::create([
            'hikvision_employee_no' => '1002',
            'full_name' => 'Jorge Navarro Vela',
            'department' => 'Vigilancia',
            'position' => 'Velador',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'totalMatches' => 4,
                    'numOfMatches' => 4,
                    'InfoList' => [
                        [
                            'employeeNoString' => '1002',
                            'time' => '2026-11-12T22:15:00-06:00',
                            'attendanceStatus' => 'checkIn',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '1002',
                            'time' => '2026-11-13T02:00:00-06:00',
                            'attendanceStatus' => 'breakOut',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '1002',
                            'time' => '2026-11-13T02:45:00-06:00',
                            'attendanceStatus' => 'breakIn',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '1002',
                            'time' => '2026-11-13T06:30:00-06:00',
                            'attendanceStatus' => 'checkOut',
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $calculator = app(AttendanceCalculatorService::class);
        $isapi = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);

        $stats = $calculator->syncFromDevice(
            $isapi,
            Carbon::parse('2026-11-12 20:00:00', 'America/Mexico_City'),
            Carbon::parse('2026-11-13 08:00:00', 'America/Mexico_City')
        );

        $this->assertSame(4, $stats['inserted']);
        $this->assertSame(1, DailySummary::where('employee_id', $employee->id)->count());

        $summary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-12')
            ->firstOrFail();

        // Turno 22:00-06:00 (480 - 45 = 435m ordinarios). Entrada 22:15 (15m retardo), salida 06:30 (495m - 45m = 450m netos, 15m extra).
        $this->assertSame(15, $summary->late_minutes);
        $this->assertSame(45, $summary->meal_minutes);
        $this->assertSame(450, $summary->worked_minutes);
        $this->assertSame(15, $summary->overtime_minutes);
        $this->assertSame(DailySummary::STATUS_LATE, $summary->status);
    }

    public function test_normalizes_inverted_break_punches_and_single_orphan_break_in(): void
    {
        $emp1 = $this->createShiftAndEmployee('1011');
        $shift = $emp1->workShift;

        $emp2 = Employee::create([
            'hikvision_employee_no' => '1012',
            'full_name' => 'María López',
            'department' => 'Ingeniería',
            'position' => 'QA',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        // Empleado 1 con marcas invertidas (como ocurría antes con breakIn/breakOut del K1T804)
        AttendanceLog::create([
            'employee_id' => $emp1->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-12 09:05:00',
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 1],
        ]);
        AttendanceLog::create([
            'employee_id' => $emp1->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-12 14:32:41',
            'event_type' => AttendanceLog::EVENT_BREAK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 3],
        ]);
        AttendanceLog::create([
            'employee_id' => $emp1->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-12 14:45:29',
            'event_type' => AttendanceLog::EVENT_BREAK_OUT,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 4],
        ]);

        $summary1 = DailySummary::where('employee_id', $emp1->id)
            ->whereDate('work_date', '2026-11-12')
            ->firstOrFail();

        $this->assertSame(12, $summary1->meal_minutes);
        $this->assertSame('14:32:41', $summary1->break_out?->format('H:i:s'));
        $this->assertSame('14:45:29', $summary1->break_in?->format('H:i:s'));

        // Empleado 2 hoy con check_in y una sola marca break_in huérfana -> promovida a break_out (En Comida)
        $now = now();
        AttendanceLog::create([
            'employee_id' => $emp2->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => $now->copy()->subMinutes(20),
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 1],
        ]);
        AttendanceLog::create([
            'employee_id' => $emp2->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => $now->copy()->subMinutes(5),
            'event_type' => AttendanceLog::EVENT_BREAK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 3],
        ]);

        $summary2 = DailySummary::where('employee_id', $emp2->id)
            ->whereDate('work_date', $now->toDateString())
            ->firstOrFail();

        $this->assertNotNull($summary2->break_out);
        $this->assertNull($summary2->break_in);
        $this->assertSame(DailySummary::STATUS_IN_MEAL, $summary2->status);
    }

    public function test_two_unclassified_punches_map_to_check_out_when_shift_closed_or_near_exit(): void
    {
        $employee = $this->createShiftAndEmployee('1013');

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-10 09:02:00',
            'event_type' => AttendanceLog::EVENT_UNCLASSIFIED,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['attendanceStatus' => ''],
        ]);
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-10 18:05:00',
            'event_type' => AttendanceLog::EVENT_UNCLASSIFIED,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['attendanceStatus' => ''],
        ]);

        $summary = DailySummary::where('employee_id', $employee->id)
            ->whereDate('work_date', '2026-11-10')
            ->firstOrFail();

        $this->assertNotNull($summary->last_check_out);
        $this->assertNull($summary->break_out);
        $this->assertSame(543, $summary->worked_minutes);
        $this->assertSame(63, $summary->overtime_minutes);
        $this->assertSame(DailySummary::STATUS_ON_TIME, $summary->status);
    }

    public function test_recalculate_command_and_shift_update_refresh_daily_summaries(): void
    {
        $this->actingAs(User::factory()->create());

        $employee = $this->createShiftAndEmployee('1014');
        $shift = $employee->workShift;

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-11 09:15:00',
            'event_type' => AttendanceLog::EVENT_CHECK_IN,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 1],
        ]);
        AttendanceLog::create([
            'employee_id' => $employee->id,
            'device_ip' => '192.168.0.69',
            'punched_at' => '2026-11-11 18:15:00',
            'event_type' => AttendanceLog::EVENT_CHECK_OUT,
            'verification_mode' => 'fingerprint',
            'raw_payload' => ['statusValue' => 2],
        ]);

        // Modificamos manualmente el summary a 0 para verificar que attendance:recalculate lo restaura
        DailySummary::where('employee_id', $employee->id)->update(['late_minutes' => 0, 'worked_minutes' => 0]);

        $this->artisan('attendance:recalculate')->assertSuccessful();

        $summary = DailySummary::where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(15, $summary->late_minutes);
        $this->assertSame(540, $summary->worked_minutes);

        // Actualizar el turno a entrada 09:30 debe recalcular automáticamente el retardo a 0
        $this->put("/shifts/{$shift->id}", [
            'name' => $shift->name,
            'entry_time' => '09:30',
            'exit_time' => '18:30',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ])->assertRedirect(route('shifts.index'));

        $updated = $summary->fresh();
        $this->assertSame(0, $updated->late_minutes);
        $this->assertSame(DailySummary::STATUS_ON_TIME, $updated->status);
    }
}
