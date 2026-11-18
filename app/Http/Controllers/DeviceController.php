<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Exceptions\SyncInProgressException;
use App\Services\AttendanceSyncCoordinator;
use App\Services\AuditLogger;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DeviceController extends Controller
{
    public const STATUS_CACHE_KEY = 'hikvision:status';

    /**
     * Segundos que se reutiliza el último estado de la terminal (auditoría OP-05).
     */
    public const STATUS_CACHE_SECONDS = 30;

    /**
     * Estado de la terminal. Todas las pestañas abiertas comparten la misma consulta cada 30 s;
     * "Probar Conectividad" manda ?fresh=1 para consultar de nuevo.
     */
    public function status(Request $request, HikvisionIsapiService $isapi): JsonResponse
    {
        if ($request->boolean('fresh')) {
            Cache::forget(self::STATUS_CACHE_KEY);
        }

        $status = Cache::remember(
            self::STATUS_CACHE_KEY,
            self::STATUS_CACHE_SECONDS,
            fn (): array => $isapi->testConnection()
        );

        return response()->json($status);
    }

    /**
     * Sincronización manual del día completo. Comparte candado con la tarea programada (SE-13).
     */
    public function sync(AttendanceSyncCoordinator $coordinator, AuditLogger $audit): JsonResponse
    {
        try {
            $stats = $coordinator->run(now()->startOfDay(), now());

            $audit->record('device.sync', null, "Sincronización manual: {$stats['inserted']} checadas nuevas", [], [
                'received' => $stats['received'],
                'inserted' => $stats['inserted'],
                'skipped' => $stats['skipped'],
                'unmatched' => $stats['unmatched'],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Sincronización completada: {$stats['inserted']} eventos nuevos, {$stats['skipped']} omitidos.",
                'stats' => $stats,
            ]);
        } catch (SyncInProgressException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 409);
        } catch (HikvisionConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'online' => false,
            ], 502);
        }
    }
}
