<?php

namespace App\Http\Controllers;

use App\Exceptions\HikvisionConnectionException;
use App\Services\AttendanceCalculatorService;
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
        AttendanceCalculatorService $calculator
    ): JsonResponse {
        try {
            $stats = $calculator->syncFromDevice($isapi, now()->startOfDay(), now());

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

