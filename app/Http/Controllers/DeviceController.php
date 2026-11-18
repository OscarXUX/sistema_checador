<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Services\AttendanceCalculatorService;
use App\Services\AuditLogger;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\JsonResponse;

class DeviceController extends Controller
{
    public function status(HikvisionIsapiService $isapi): JsonResponse
    {
        return response()->json($isapi->testConnection());
    }

    public function sync(
        HikvisionIsapiService $isapi,
        AttendanceCalculatorService $calculator,
        AuditLogger $audit
    ): JsonResponse {
        try {
            $stats = $calculator->syncFromDevice($isapi, now()->startOfDay(), now());

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
        } catch (HikvisionConnectionException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'online' => false,
            ], 502);
        }
    }
}

