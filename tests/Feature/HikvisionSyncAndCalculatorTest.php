<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
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
}
