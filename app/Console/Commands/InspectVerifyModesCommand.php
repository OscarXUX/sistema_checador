<?php

namespace App\Console\Commands;

use App\Models\AttendanceLog;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;

/**
 * Inspecciona los valores reales de currentVerifyMode y minor en bitácora y/o en la terminal Hikvision.
 */
class InspectVerifyModesCommand extends Command
{
    protected $signature = 'hikvision:inspect-verify-modes
        {--limit=500 : Máximo de registros recientes de attendance_logs a revisar}
        {--live : Consultar también capacidades y eventos recientes en vivo por ISAPI}';

    protected $description = 'Muestra los valores reales de currentVerifyMode y minor en attendance_logs y (opcional) en la terminal ISAPI';

    public function handle(HikvisionIsapiService $isapi): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $logs = AttendanceLog::query()
            ->orderByDesc('punched_at')
            ->limit($limit)
            ->get(['id', 'punched_at', 'verification_mode', 'raw_payload']);

        $summary = [];
        foreach ($logs as $log) {
            $payload = is_array($log->raw_payload) ? $log->raw_payload : [];
            $rawVerifyMode = (string) ($payload['currentVerifyMode'] ?? '(ausente)');
            $minor = isset($payload['minor']) ? (string) $payload['minor'] : '(ausente)';
            $mapped = $isapi->mapVerificationMode($payload);
            $key = "{$rawVerifyMode}|{$minor}|{$log->verification_mode}|{$mapped}";

            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'currentVerifyMode' => $rawVerifyMode,
                    'minor' => $minor,
                    'stored' => (string) $log->verification_mode,
                    'mapped' => $mapped,
                    'count' => 0,
                ];
            }
            $summary[$key]['count']++;
        }

        $this->info("Registros revisados en attendance_logs: {$logs->count()}");
        if ($summary === []) {
            $this->warn('No hay registros en attendance_logs.');
        } else {
            $this->table(
                ['currentVerifyMode', 'minor', 'Guardado BD', 'Mapeo actual', 'Eventos'],
                array_values($summary)
            );
        }

        if ($this->option('live')) {
            $this->newLine();
            $this->info('Consultando terminal ISAPI en vivo: '.$isapi->getBaseUrl());
            $caps = $isapi->detectVerificationCapabilities();
            if (! ($caps['online'] ?? false)) {
                $this->warn((string) ($caps['message'] ?? 'Terminal offline o fuera de red.'));

                return self::SUCCESS;
            }

            $this->info('Métodos detectados:');
            foreach ($caps['methods'] as $code => $info) {
                $this->line(sprintf(' - %s: %s', $code, ($info['supported'] ?? false) ? 'SOPORTADO' : 'NO SOPORTADO'));
            }
            if (($caps['verify_modes_reported'] ?? []) !== []) {
                $this->line('verifyModes reportados por firmware: '.implode(', ', $caps['verify_modes_reported']));
            }

            try {
                $events = $isapi->fetchAttendanceEvents(now()->subDays(7), now());
                $liveModes = [];
                foreach ($events as $ev) {
                    $raw = $ev['raw_payload'] ?? [];
                    $cvm = (string) ($raw['currentVerifyMode'] ?? '(ausente)');
                    $min = isset($raw['minor']) ? (string) $raw['minor'] : '(ausente)';
                    $k = "{$cvm}|{$min}|{$ev['verification_mode']}";
                    $liveModes[$k] = [
                        'currentVerifyMode' => $cvm,
                        'minor' => $min,
                        'mapped' => $ev['verification_mode'],
                        'count' => ($liveModes[$k]['count'] ?? 0) + 1,
                    ];
                }
                $this->info('Eventos en vivo últimos 7 días: '.count($events));
                if ($liveModes !== []) {
                    $this->table(['currentVerifyMode', 'minor', 'Mapeo', 'Eventos'], array_values($liveModes));
                }
            } catch (\Throwable $e) {
                $this->warn('No se pudieron descargar eventos AcsEvent en vivo: '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
