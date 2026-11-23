<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\MarkingMethod;
use App\Services\AuditLogger;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarkingMethodController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(HikvisionIsapiService $isapi): View
    {
        $methods = MarkingMethod::orderBy('id')->get();
        $observedModes = $this->summarizeObservedVerifyModes($isapi);

        return view('marking-methods.index', [
            'methods' => $methods,
            'observedModes' => $observedModes,
            'deviceIp' => $isapi->getDeviceIp(),
        ]);
    }

    public function detect(Request $request, HikvisionIsapiService $isapi): JsonResponse|RedirectResponse
    {
        $result = $isapi->detectVerificationCapabilities();

        if (! ($result['online'] ?? false)) {
            $message = (string) ($result['message'] ?? 'No se pudo consultar la terminal Hikvision.');

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'online' => false,
                    'message' => $message,
                    'detection' => $result,
                ], 502);
            }

            return redirect()
                ->route('marking-methods.index')
                ->withErrors(['device' => $message]);
        }

        $now = now();
        $detectedSummary = [];

        foreach ($result['methods'] as $code => $info) {
            $method = MarkingMethod::where('code', $code)->first();
            if ($method === null) {
                continue;
            }

            $supported = $info['supported'] ?? null;
            $method->forceFill([
                'device_supported' => $supported,
                'last_detected_at' => $now,
                'device_details' => [
                    'endpoint' => $info['endpoint'] ?? null,
                    'verify_modes_reported' => $result['verify_modes_reported'] ?? [],
                    'device_ip' => $result['device_ip'] ?? null,
                ],
            ])->save();

            $detectedSummary[$code] = $supported;
        }

        $this->audit->record(
            'marking_methods.detected',
            null,
            'Detección ISAPI de medios de marcaje en terminal Hikvision',
            [],
            $detectedSummary
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'online' => true,
                'message' => $result['message'],
                'methods' => MarkingMethod::orderBy('id')->get(),
                'detection' => $result,
            ]);
        }

        return redirect()
            ->route('marking-methods.index')
            ->with('status', $result['message']);
    }

    public function update(Request $request, MarkingMethod $markingMethod): JsonResponse|RedirectResponse
    {
        $request->validate([
            'is_enabled' => ['required', 'boolean'],
        ]);

        $wantsEnabled = $request->boolean('is_enabled');

        if ($wantsEnabled && $markingMethod->isBlockedByPrivacyNotice()) {
            $message = sprintf(
                'El medio "%s" es biométrico y espera el aviso de privacidad (%s) antes de poder habilitarse.',
                $markingMethod->name,
                $markingMethod->privacy_notice_code ?? 'TE-02'
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'errors' => ['is_enabled' => [$message]],
                ], 422);
            }

            return redirect()
                ->route('marking-methods.index')
                ->withErrors(['is_enabled' => $message]);
        }

        $before = $markingMethod->is_enabled;
        $markingMethod->forceFill(['is_enabled' => $wantsEnabled])->save();

        if ($before !== $wantsEnabled) {
            $this->audit->record(
                'marking_method.updated',
                $markingMethod,
                sprintf('Medio "%s" %s para la empresa', $markingMethod->name, $wantsEnabled ? 'habilitado' : 'deshabilitado'),
                ['is_enabled' => $before],
                ['is_enabled' => $wantsEnabled]
            );
        }

        $statusMessage = sprintf(
            'Medio "%s" %s correctamente.',
            $markingMethod->name,
            $wantsEnabled ? 'habilitado' : 'deshabilitado'
        );

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $statusMessage,
                'method' => $markingMethod->fresh(),
            ]);
        }

        return redirect()
            ->route('marking-methods.index')
            ->with('status', $statusMessage);
    }

    /**
     * Resume combinaciones reales de currentVerifyMode y minor observadas en la bitácora local.
     *
     * @return list<array{current_verify_mode: string, minor: string, mapped: string, count: int}>
     */
    private function summarizeObservedVerifyModes(HikvisionIsapiService $isapi): array
    {
        $logs = AttendanceLog::query()
            ->orderByDesc('punched_at')
            ->limit(250)
            ->get(['id', 'verification_mode', 'raw_payload']);

        $grouped = [];

        foreach ($logs as $log) {
            $payload = is_array($log->raw_payload) ? $log->raw_payload : [];
            $cvm = isset($payload['currentVerifyMode']) && $payload['currentVerifyMode'] !== ''
                ? (string) $payload['currentVerifyMode']
                : '(ausente)';
            $minor = isset($payload['minor']) ? (string) $payload['minor'] : '(ausente)';
            $mapped = $isapi->mapVerificationMode($payload);
            $key = "{$cvm}|{$minor}|{$mapped}";

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'current_verify_mode' => $cvm,
                    'minor' => $minor,
                    'mapped' => AttendanceLog::verificationModeLabel($mapped)." ({$mapped})",
                    'count' => 0,
                ];
            }

            $grouped[$key]['count']++;
        }

        return array_values($grouped);
    }
}
