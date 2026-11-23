<?php

namespace App\Console\Commands;

use App\Exceptions\HikvisionConnectionException;
use App\Exceptions\SyncInProgressException;
use App\Services\AttendanceSyncCoordinator;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sincroniza las checadas desde la terminal Hikvision DS-K1T804AEF hacia attendance_logs.
 */
class HikvisionSyncCommand extends Command
{
    protected $signature = 'hikvision:sync
        {--from= : Fecha/hora de inicio (YYYY-MM-DD o ISO8601). Sin esta opción solo descarga lo nuevo desde la última sincronización}
        {--to= : Fecha/hora de fin (YYYY-MM-DD o ISO8601); por defecto ahora}';

    protected $description = 'Descarga checadas vía ISAPI desde la terminal Hikvision y recalcula el Kardex diario.';

    public function handle(AttendanceSyncCoordinator $coordinator): int
    {
        try {
            if ($this->option('from') === null && $this->option('to') === null) {
                // Modo de la tarea programada: solo lo nuevo (auditoría OP-01)
                $dataResultado = $coordinator->runIncremental();
                $this->info("Sincronización incremental desde {$dataResultado['from']}.");
            } else {
                $start = $this->option('from')
                    ? Carbon::parse((string) $this->option('from'))->startOfDay()
                    : now()->startOfDay();

                $end = $this->option('to')
                    ? Carbon::parse((string) $this->option('to'))->endOfDay()
                    : now();

                $this->info("Consultando eventos ISAPI ({$start->toDateTimeString()} -> {$end->toDateTimeString()})...");
                $dataResultado = $coordinator->run($start, $end);
            }
        } catch (SyncInProgressException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        } catch (HikvisionConnectionException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sincronización finalizada. Eventos recibidos: %d | Nuevos: %d | Duplicados omitidos: %d | Sin empleado local: %d',
            $dataResultado['received'],
            $dataResultado['inserted'],
            $dataResultado['skipped'],
            $dataResultado['unmatched']
        ));

        return self::SUCCESS;
    }
}
