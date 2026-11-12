<?php

namespace App\Console\Commands;

use App\Exceptions\HikvisionConnectionException;
use App\Services\AttendanceCalculatorService;
use App\Services\HikvisionIsapiService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sincroniza las checadas desde la terminal Hikvision DS-K1T804AEF hacia attendance_logs.
 */
class HikvisionSyncCommand extends Command
{
    protected $signature = 'hikvision:sync
        {--from= : Fecha/hora de inicio (YYYY-MM-DD o ISO8601); por defecto inicio de hoy}
        {--to= : Fecha/hora de fin (YYYY-MM-DD o ISO8601); por defecto ahora}';

    protected $description = 'Descarga checadas vía ISAPI desde la terminal Hikvision y recalcula el Kardex diario.';

    public function handle(
        HikvisionIsapiService $isapi,
        AttendanceCalculatorService $calculator,
    ): int {
        $start = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))->startOfDay()
            : now()->startOfDay();

        $end = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : now();

        $this->info("Consultando eventos ISAPI en {$isapi->getBaseUrl()} ({$start->toDateTimeString()} -> {$end->toDateTimeString()})...");

        try {
            $stats = $calculator->syncFromDevice($isapi, $start, $end);
        } catch (HikvisionConnectionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sincronización finalizada. Eventos recibidos: %d | Nuevos: %d | Duplicados omitidos: %d | Sin empleado local: %d',
            $stats['received'],
            $stats['inserted'],
            $stats['skipped'],
            $stats['unmatched']
        ));

        return self::SUCCESS;
    }
}
