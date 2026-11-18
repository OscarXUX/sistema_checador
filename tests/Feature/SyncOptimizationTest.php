<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\AttendanceSyncCoordinator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Paquete 3: sincronización en lote (OP-02), incremental (OP-01), candado compartido (SE-13)
 * y caché del estado de la terminal (OP-05).
 */
class SyncOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $deviceEvents = [];

    /** @var array<int, array<string, mixed>> */
    private array $acsConditions = [];

    private bool $deviceFails = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        Http::fake(function (Request $request) {
            if ($this->deviceFails) {
                return Http::response(['statusCode' => 4, 'statusString' => 'Invalid Operation'], 400);
            }

            if (str_contains($request->url(), 'AcsEvent')) {
                $cond = $request->data()['AcsEventCond'];
                $this->acsConditions[] = $cond;
                $slice = array_slice($this->deviceEvents, (int) $cond['searchResultPosition'], (int) $cond['maxResults']);
                $more = (int) $cond['searchResultPosition'] + count($slice) < count($this->deviceEvents);

                return Http::response(['AcsEvent' => [
                    'responseStatusStrg' => $more ? 'MORE' : 'OK',
                    'totalMatches' => count($this->deviceEvents),
                    'InfoList' => $slice,
                ]], 200);
            }

            if (str_contains($request->url(), 'deviceInfo')) {
                return Http::response(['DeviceInfo' => ['deviceName' => 'Terminal', 'model' => 'DS-K1T804AEF']], 200);
            }

            return Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200);
        });
    }

    private function seedEmployees(int $count): void
    {
        $shift = WorkShift::create([
            'name' => 'Matutino', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);

        for ($i = 1; $i <= $count; $i++) {
            Employee::create([
                'hikvision_employee_no' => (string) (2000 + $i),
                'full_name' => "Empleado {$i}",
                'department' => 'Planta',
                'position' => 'Operador',
                'work_shift_id' => $shift->id,
                'is_active' => true,
            ]);
        }

        $statuses = ['checkIn' => '09:0', 'breakOut' => '14:0', 'breakIn' => '15:0', 'checkOut' => '18:1'];
        for ($i = 1; $i <= $count; $i++) {
            foreach ($statuses as $status => $time) {
                $this->deviceEvents[] = [
                    'employeeNoString' => (string) (2000 + $i),
                    'time' => '2026-11-18T'.$time.($i % 10).':00-06:00',
                    'attendanceStatus' => $status,
                    'currentVerifyMode' => 'fingerPrint',
                ];
            }
        }
    }

    private function countQueries(callable $callback): int
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $callback();

        return $queries;
    }

    public function test_bulk_sync_inserts_new_punches_and_skips_existing_with_few_queries(): void
    {
        $this->seedEmployees(50);
        $coordinator = app(AttendanceSyncCoordinator::class);
        $start = Carbon::parse('2026-11-18 00:00:00');
        $end = Carbon::parse('2026-11-18 23:59:59');

        $first = $coordinator->run($start, $end);

        $this->assertSame(200, $first['inserted']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame(200, AttendanceLog::count());
        $this->assertSame(50, DailySummary::count());
        $this->assertSame(DailySummary::STATUS_ON_TIME, DailySummary::firstOrFail()->status);

        $second = [];
        $queries = $this->countQueries(function () use ($coordinator, $start, $end, &$second): void {
            $second = $coordinator->run($start, $end);
        });

        $this->assertSame(0, $second['inserted']);
        $this->assertSame(200, $second['skipped']);
        // Antes: 2 consultas por checada (400). Ahora: empleados, turnos y checadas existentes
        $this->assertLessThanOrEqual(5, $queries);
    }

    public function test_duplicate_events_in_the_same_download_are_saved_once(): void
    {
        $this->seedEmployees(1);
        $this->deviceEvents[] = $this->deviceEvents[0];

        $stats = app(AttendanceSyncCoordinator::class)->run(
            Carbon::parse('2026-11-18 00:00:00'),
            Carbon::parse('2026-11-18 23:59:59')
        );

        $this->assertSame(4, $stats['inserted']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertSame(4, AttendanceLog::count());
    }

    public function test_first_incremental_sync_starts_at_beginning_of_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:30:00'));

        $this->artisan('hikvision:sync')->assertSuccessful();

        $this->assertSame('2026-11-18T00:00:00', substr($this->acsConditions[0]['startTime'], 0, 19));
        $this->assertSame('2026-11-18T10:30:00', substr(Cache::get(AttendanceSyncCoordinator::CURSOR_KEY), 0, 19));
    }

    public function test_incremental_sync_starts_from_last_cut_minus_overlap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:31:00'));
        Cache::forever(AttendanceSyncCoordinator::CURSOR_KEY, Carbon::parse('2026-11-18 10:30:00')->toIso8601String());

        $this->artisan('hikvision:sync')->assertSuccessful();

        $this->assertSame('2026-11-18T10:20:00', substr($this->acsConditions[0]['startTime'], 0, 19));
        $this->assertSame('2026-11-18T10:31:00', substr(Cache::get(AttendanceSyncCoordinator::CURSOR_KEY), 0, 19));
    }

    public function test_incremental_lookback_is_capped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 10:30:00'));
        Cache::forever(AttendanceSyncCoordinator::CURSOR_KEY, Carbon::parse('2026-01-01 08:00:00')->toIso8601String());

        $this->artisan('hikvision:sync')->assertSuccessful();

        $expected = Carbon::parse('2026-11-18 10:30:00')->subDays(AttendanceSyncCoordinator::MAX_LOOKBACK_DAYS);
        $this->assertSame($expected->format('Y-m-d\TH:i:s'), substr($this->acsConditions[0]['startTime'], 0, 19));
    }

    public function test_cursor_does_not_move_when_the_device_fails(): void
    {
        $this->deviceFails = true;
        Cache::forever(AttendanceSyncCoordinator::CURSOR_KEY, '2026-11-18T08:00:00-06:00');

        $this->artisan('hikvision:sync')->assertFailed();

        $this->assertSame('2026-11-18T08:00:00-06:00', Cache::get(AttendanceSyncCoordinator::CURSOR_KEY));
    }

    public function test_manual_sync_returns_409_while_another_sync_is_running(): void
    {
        $lock = Cache::lock(AttendanceSyncCoordinator::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $this->postJson('/device/sync')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Ya hay una sincronización en curso. Intenta de nuevo en unos segundos.');

        $this->artisan('hikvision:sync')
            ->expectsOutputToContain('Ya hay una sincronización en curso')
            ->assertSuccessful();

        $this->assertSame([], $this->acsConditions);

        $lock->release();
        $this->postJson('/device/sync')->assertOk();
    }

    public function test_device_status_is_cached_and_fresh_queries_again(): void
    {
        $this->getJson('/device/status')->assertOk()->assertJsonPath('online', true);
        $this->getJson('/device/status')->assertOk();
        Http::assertSentCount(1);

        $this->getJson('/device/status?fresh=1')->assertOk();
        Http::assertSentCount(2);
    }
}
