<?php

namespace App\Services;

use App\Exceptions\SyncInProgressException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Coordina las sincronizaciones con la terminal.
 *
 * - Un solo candado para la tarea programada y el botón "Sincronizar" (auditoría SE-13).
 * - La tarea programada descarga solo lo nuevo desde la última sincronización (auditoría OP-01).
 */
class AttendanceSyncCoordinator
{
    public const LOCK_KEY = 'hikvision:sync:lock';

    public const CURSOR_KEY = 'hikvision:sync:last_end';

    /**
     * Tiempo máximo que se respeta el candado si un proceso muere sin liberarlo.
     */
    private const LOCK_SECONDS = 300;

    /**
     * Minutos que se vuelven a pedir antes del último corte, por checadas que la terminal registra con retraso
     * o por diferencias de reloj. Los duplicados se descartan.
     */
    public const OVERLAP_MINUTES = 10;

    /**
     * Límite hacia atrás si el servidor estuvo apagado mucho tiempo.
     */
    public const MAX_LOOKBACK_DAYS = 31;

    public function __construct(
        private readonly HikvisionIsapiService $isapi,
        private readonly AttendanceCalculatorService $calculator,
    ) {
    }

    /**
     * Descarga desde el último corte (menos el traslape) hasta ahora y guarda el nuevo corte.
     * La primera vez, desde el inicio del día.
     *
     * @return array<string, int|string>
     *
     * @throws SyncInProgressException
     */
    public function runIncremental(): array
    {
        $end = CarbonImmutable::now();
        $start = $this->incrementalStart($end);

        $stats = $this->run($start, $end);

        // Solo se avanza el corte si la descarga terminó bien
        Cache::forever(self::CURSOR_KEY, $end->toIso8601String());

        return $stats + ['from' => $start->toIso8601String()];
    }

    /**
     * Descarga un rango fijo (botón "Sincronizar" o --from/--to). No mueve el corte incremental.
     *
     * @return array<string, int|string>
     *
     * @throws SyncInProgressException
     */
    public function run(CarbonInterface $start, CarbonInterface $end): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncInProgressException;
        }

        try {
            return $this->calculator->syncFromDevice($this->isapi, $start, $end);
        } finally {
            $lock->release();
        }
    }

    public function incrementalStart(CarbonImmutable $end): CarbonImmutable
    {
        $cursor = Cache::get(self::CURSOR_KEY);
        $start = is_string($cursor)
            ? CarbonImmutable::parse($cursor)->subMinutes(self::OVERLAP_MINUTES)
            : $end->startOfDay();

        $limit = $end->subDays(self::MAX_LOOKBACK_DAYS);

        return $start->lessThan($limit) ? $limit : $start;
    }
}
