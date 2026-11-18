<?php

namespace App\Services;

use App\Exceptions\HikvisionConnectionException;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP con Digest Auth para el protocolo Hikvision ISAPI (DS-K1T804AEF).
 */
class HikvisionIsapiService
{
    private string $baseUrl;

    private string $username;

    private string $password;

    private string $deviceSerial;

    private int $timeout;

    public function __construct(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null,
        ?string $deviceSerial = null,
        ?int $timeout = null,
    ) {
        $config = config('services.hikvision', []);

        $this->baseUrl = rtrim((string) ($baseUrl ?? $config['url'] ?? 'http://192.168.0.69'), '/');
        $this->username = (string) ($username ?? $config['username'] ?? 'admin');
        $this->password = (string) ($password ?? $config['password'] ?? '');
        $this->deviceSerial = (string) ($deviceSerial ?? $config['device_serial'] ?? 'DS-K1T804AEF');
        $this->timeout = (int) ($timeout ?? $config['timeout'] ?? 10);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getDeviceIp(): string
    {
        return (string) (parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl);
    }

    /**
     * Valida conectividad, credenciales Digest Auth e información general del reloj.
     *
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $checkedAt = now()->toIso8601String();

        try {
            $response = $this->client(min($this->timeout, 5))
                ->acceptJson()
                ->get($this->endpoint('/ISAPI/System/deviceInfo?format=json'));

            // Algunos firmwares DS-K1T responden XML si no aceptan ?format=json en deviceInfo
            if ($response->status() === 400 || $response->status() === 404) {
                $response = $this->client(min($this->timeout, 5))
                    ->get($this->endpoint('/ISAPI/System/deviceInfo'));
            }

            if (! $response->successful()) {
                return [
                    'online' => false,
                    'device_ip' => $this->getDeviceIp(),
                    'device_name' => null,
                    'model' => $this->deviceSerial,
                    'serial_number' => $this->deviceSerial,
                    'firmware_version' => null,
                    'mac_address' => null,
                    'http_status' => $response->status(),
                    'checked_at' => $checkedAt,
                    'message' => $response->status() === 401
                        ? 'Error de autenticación Digest Auth (usuario/contraseña incorrectos).'
                        : "La terminal respondió con estado HTTP {$response->status()}.",
                ];
            }

            $info = $this->extractDeviceInfo($response);

            return [
                'online' => true,
                'device_ip' => $this->getDeviceIp(),
                'device_name' => $info['deviceName'] ?? 'Terminal Biométrica Hikvision',
                'model' => $info['model'] ?? $this->deviceSerial,
                'serial_number' => $info['serialNumber'] ?? $this->deviceSerial,
                'firmware_version' => $info['firmwareVersion'] ?? null,
                'mac_address' => $info['macAddress'] ?? null,
                'http_status' => $response->status(),
                'checked_at' => $checkedAt,
                'message' => 'Terminal conectada vía ISAPI Digest Auth.',
            ];
        } catch (ConnectionException $e) {
            Log::warning('Hikvision ISAPI timeout/connection failed in testConnection', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'online' => false,
                'device_ip' => $this->getDeviceIp(),
                'device_name' => null,
                'model' => $this->deviceSerial,
                'serial_number' => $this->deviceSerial,
                'firmware_version' => null,
                'mac_address' => null,
                'http_status' => null,
                'checked_at' => $checkedAt,
                'message' => 'Sin respuesta de la terminal en '.$this->getDeviceIp().'.',
            ];
        } catch (\Throwable $e) {
            Log::error('Unexpected error testing Hikvision ISAPI connection', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'online' => false,
                'device_ip' => $this->getDeviceIp(),
                'device_name' => null,
                'model' => $this->deviceSerial,
                'serial_number' => $this->deviceSerial,
                'firmware_version' => null,
                'mac_address' => null,
                'http_status' => null,
                'checked_at' => $checkedAt,
                // Mensaje genérico; el detalle queda en el log (SE-12)
                'message' => 'Error inesperado al comunicarse con la terminal. Revisa el log del sistema.',
            ];
        }
    }

    /**
     * Da de alta o actualiza a un empleado directamente en la terminal Hikvision.
     *
     * @return array<string, mixed>
     *
     * @throws HikvisionConnectionException
     */
    public function syncUserToDevice(Employee $employee): array
    {
        $endpoint = '/ISAPI/AccessControl/UserInfo/Record?format=json';
        $payload = [
            'UserInfo' => [
                'employeeNo' => (string) $employee->hikvision_employee_no,
                'name' => mb_substr(trim($employee->full_name), 0, 32),
                'userType' => 'normal',
                'Valid' => [
                    'enable' => (bool) $employee->is_active,
                    'beginTime' => '2020-01-01T00:00:00',
                    'endTime' => '2035-12-31T23:59:59',
                ],
                'doorRight' => '1',
                'RightPlan' => [
                    [
                        'doorNo' => 1,
                        'planTemplateNo' => '1',
                    ],
                ],
                'localUIRight' => false,
                'gender' => 'unknown',
            ],
        ];

        $response = $this->sendRequest('post', $endpoint, $payload);
        $body = $this->parseResponseBody($response);

        // Si el usuario ya existe en el reloj, actualizamos usando UserInfo/Modify
        if ($this->isDuplicateEmployeeResponse($response, $body)) {
            $modifyEndpoint = '/ISAPI/AccessControl/UserInfo/Modify?format=json';
            $response = $this->sendRequest('put', $modifyEndpoint, $payload);
            $body = $this->parseResponseBody($response);
            $endpoint = $modifyEndpoint;
        }

        $this->assertIsapiSuccess($response, $body, $endpoint, 'No se pudo sincronizar al empleado en la terminal.');

        $employee->forceFill(['device_synced_at' => now()])->save();

        return [
            'success' => true,
            'employee_no' => $employee->hikvision_employee_no,
            'endpoint' => $endpoint,
            'device_response' => $body,
            'message' => "Empleado {$employee->hikvision_employee_no} sincronizado en Hikvision.",
        ];
    }

    /**
     * Da de baja a un empleado en la terminal Hikvision.
     *
     * @return array<string, mixed>
     *
     * @throws HikvisionConnectionException
     */
    public function deleteUserFromDevice(string $employeeNo): array
    {
        $endpoint = '/ISAPI/AccessControl/UserInfo/Delete?format=json';
        $payload = [
            'UserInfoDelCond' => [
                'EmployeeNoList' => [
                    ['employeeNo' => $employeeNo],
                ],
            ],
        ];

        $response = $this->sendRequest('put', $endpoint, $payload);
        $body = $this->parseResponseBody($response);

        $this->assertIsapiSuccess($response, $body, $endpoint, "No se pudo eliminar al empleado {$employeeNo} del reloj.");

        return [
            'success' => true,
            'employee_no' => $employeeNo,
            'device_response' => $body,
            'message' => "Empleado {$employeeNo} dado de baja en la terminal Hikvision.",
        ];
    }

    /**
     * Activa el lector óptico de la terminal para capturar la huella del empleado.
     *
     * @return array<string, mixed>
     *
     * @throws HikvisionConnectionException
     */
    public function triggerRemoteFingerprintCapture(string $employeeNo, int $fingerNo = 1): array
    {
        $endpoint = '/ISAPI/AccessControl/CaptureFingerPrint';
        $fingerIndex = max(1, min(10, $fingerNo));

        $xmlPayload = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CaptureFingerPrint version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">'
            .'<CaptureFingerPrintCond><fingerNo>%d</fingerNo></CaptureFingerPrintCond>'
            .'</CaptureFingerPrint>',
            $fingerIndex
        );

        try {
            $response = $this->client(max($this->timeout, 25))
                ->withBody($xmlPayload, 'application/xml')
                ->post($this->endpoint($endpoint));
        } catch (ConnectionException $e) {
            throw new HikvisionConnectionException(
                'Tiempo de espera agotado al activar el lector biométrico en '.$this->getDeviceIp().'.',
                null,
                $endpoint,
                $e
            );
        }

        $body = $this->parseResponseBody($response);
        $this->assertIsapiSuccess($response, $body, $endpoint, 'No se pudo capturar la huella desde el lector óptico.');

        $fingerData = $this->extractNestedValue($body, ['fingerData', 'fingerPrintData.fingerData', 'CaptureFingerPrint.fingerPrintData.fingerData', 'FingerPrintData.fingerData']);

        // Sin plantilla no hay huella registrada: se falla en lugar de marcarla como capturada (SE-10)
        if (! is_string($fingerData) || $fingerData === '') {
            Log::warning('La terminal respondió OK a CaptureFingerPrint pero sin plantilla de huella', [
                'employee_no' => $employeeNo,
                'finger_no' => $fingerIndex,
            ]);

            throw new HikvisionConnectionException(
                'La terminal no devolvió la huella capturada. Coloca el dedo de nuevo en el lector e intenta otra vez.',
                $response->status(),
                $endpoint
            );
        }

        $this->bindCapturedFingerprint($employeeNo, $fingerIndex, $fingerData);

        Employee::where('hikvision_employee_no', $employeeNo)->update([
            'fingerprint_enrolled' => true,
            'device_synced_at' => now(),
        ]);

        return [
            'success' => true,
            'employee_no' => $employeeNo,
            'finger_no' => $fingerIndex,
            'template_captured' => true,
            // Sin device_response: trae la plantilla biométrica (fingerData) y no debe salir del servidor (SE-04).
            'message' => "Huella #{$fingerIndex} capturada y asociada al empleado {$employeeNo}.",
        ];
    }

    /**
     * Descarga de forma paginada todos los eventos de acceso (major = 5) en un rango de fechas.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws HikvisionConnectionException
     */
    public function fetchAttendanceEvents(CarbonInterface $start, CarbonInterface $end): array
    {
        $endpoint = '/ISAPI/AccessControl/AcsEvent?format=json';
        $position = 0;
        $maxResults = 20;
        $maxPages = 500;
        $events = [];

        $startTime = $start->format('Y-m-d\TH:i:sP');
        $endTime = $end->format('Y-m-d\TH:i:sP');

        for ($page = 0; $page < $maxPages; $page++) {
            $payload = [
                'AcsEventCond' => [
                    'searchID' => '1',
                    'searchResultPosition' => $position,
                    'maxResults' => $maxResults,
                    'major' => 5,
                    'minor' => 0,
                    'startTime' => $startTime,
                    'endTime' => $endTime,
                ],
            ];

            $response = $this->sendRequest('post', $endpoint, $payload);
            $body = $this->parseResponseBody($response);
            $this->assertIsapiSuccess($response, $body, $endpoint, 'Error al consultar eventos AcsEvent en la terminal.');

            $acsEvent = $body['AcsEvent'] ?? $body;
            $infoList = $acsEvent['InfoList'] ?? [];

            if (! is_array($infoList) || count($infoList) === 0) {
                break;
            }

            foreach ($infoList as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $normalized = $this->normalizeAcsEvent($item);
                if ($normalized !== null) {
                    $events[] = $normalized;
                }
            }

            $statusStr = strtoupper((string) ($acsEvent['responseStatusStrg'] ?? 'OK'));
            $totalMatches = isset($acsEvent['totalMatches']) ? (int) $acsEvent['totalMatches'] : null;
            $batchCount = count($infoList);
            $position += $batchCount;

            $hasMoreByStatus = $statusStr === 'MORE';
            $hasMoreByTotal = $totalMatches !== null && $position < $totalMatches;

            if (! $hasMoreByStatus && ! $hasMoreByTotal) {
                break;
            }
        }

        return $events;
    }

    /**
     * Asocia la plantilla de huella capturada al usuario en la terminal.
     *
     * @throws HikvisionConnectionException
     */
    private function bindCapturedFingerprint(string $employeeNo, int $fingerNo, string $fingerData): void
    {
        $endpoint = '/ISAPI/AccessControl/FingerPrint/SetUp?format=json';
        $payload = [
            'FingerPrintCfg' => [
                'employeeNo' => $employeeNo,
                'enableCardReader' => [1],
                'fingerPrintID' => $fingerNo,
                'fingerType' => 'normalFinger',
                'fingerData' => $fingerData,
            ],
        ];

        $response = $this->sendRequest('post', $endpoint, $payload);
        $body = $this->parseResponseBody($response);

        if (! $response->successful() || (($body['statusCode'] ?? 1) !== 1 && ($body['subStatusCode'] ?? '') === 'badJsonFormat')) {
            $legacyPayload = [
                'FingerPrintData' => [
                    'employeeNo' => $employeeNo,
                    'fingerPrintID' => $fingerNo,
                    'fingerType' => 'normalFinger',
                    'fingerData' => $fingerData,
                ],
            ];
            $response = $this->sendRequest('post', $endpoint, $legacyPayload);
            $body = $this->parseResponseBody($response);
        }

        $this->assertIsapiSuccess($response, $body, $endpoint, 'La huella se capturó pero no pudo vincularse al usuario.');
    }

    private function client(?int $timeout = null): PendingRequest
    {
        $effectiveTimeout = $timeout ?? $this->timeout;

        return Http::withDigestAuth($this->username, $this->password)
            ->timeout($effectiveTimeout)
            ->connectTimeout(min(5, $effectiveTimeout));
    }

    private function endpoint(string $path): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws HikvisionConnectionException
     */
    private function sendRequest(string $method, string $endpoint, array $payload): Response
    {
        try {
            $request = $this->client()->acceptJson()->asJson();

            return match (strtolower($method)) {
                'put' => $request->put($this->endpoint($endpoint), $payload),
                'get' => $request->get($this->endpoint($endpoint), $payload),
                default => $request->post($this->endpoint($endpoint), $payload),
            };
        } catch (ConnectionException $e) {
            // El detalle técnico (cURL) va al log, no al usuario (SE-12)
            Log::warning('Hikvision ISAPI connection failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new HikvisionConnectionException(
                'No se pudo conectar con la terminal Hikvision en '.$this->getDeviceIp().'. Verifica que esté encendida y en la misma red.',
                null,
                $endpoint,
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResponseBody(Response $response): array
    {
        $json = $response->json();
        if (is_array($json)) {
            return $json;
        }

        $raw = trim($response->body());
        if ($raw !== '' && str_starts_with($raw, '<')) {
            $previousUseErrors = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA);
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);

            if ($xml !== false) {
                $encoded = json_encode($xml);
                if (is_string($encoded)) {
                    $decoded = json_decode($encoded, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        return ['raw' => $raw];
    }

    /**
     * @return array<string, mixed>
     */
    private function extractDeviceInfo(Response $response): array
    {
        $body = $this->parseResponseBody($response);

        if (isset($body['DeviceInfo']) && is_array($body['DeviceInfo'])) {
            return $body['DeviceInfo'];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isDuplicateEmployeeResponse(Response $response, array $body): bool
    {
        $subCode = strtolower((string) ($body['subStatusCode'] ?? ''));

        return in_array($subCode, [
            'deviceuseralreadyexist',
            'employeenoalreadyexist',
            'employeenoexist',
            'useralreadyexist',
        ], true) || ($response->status() === 400 && str_contains($subCode, 'exist'));
    }

    /**
     * @param  array<string, mixed>  $body
     *
     * @throws HikvisionConnectionException
     */
    private function assertIsapiSuccess(Response $response, array $body, string $endpoint, string $fallbackMessage): void
    {
        $statusCode = isset($body['statusCode']) ? (int) $body['statusCode'] : null;
        $statusString = strtoupper((string) ($body['statusString'] ?? ''));
        $subStatusCode = (string) ($body['subStatusCode'] ?? '');

        $isOkStatusCode = $statusCode === null || $statusCode === 1;
        $isOkString = $statusString === '' || $statusString === 'OK';

        if ($response->successful() && $isOkStatusCode && $isOkString) {
            return;
        }

        // Se registra el cuerpo para depurar, pero sin datos biométricos ni credenciales (SE-04).
        Log::error('Hikvision ISAPI rechazó la petición o devolvió statusCode != 1', [
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'status_code' => $statusCode,
            'body' => $this->redactSensitive($body),
        ]);

        $detail = $body['errorMsg']
            ?? ($subStatusCode !== '' ? "{$statusString} ({$subStatusCode})" : null)
            ?? $fallbackMessage;

        throw new HikvisionConnectionException(
            "{$fallbackMessage} Detalle ISAPI: {$detail}",
            $response->status(),
            $endpoint
        );
    }

    /**
     * Llaves que nunca se registran ni se devuelven al cliente (comparación sin mayúsculas).
     */
    private const SENSITIVE_KEYS = [
        'fingerdata',
        'facedata',
        'facepic',
        'facepicdata',
        'picdata',
        'password',
        'pwd',
    ];

    private const MAX_RAW_LOG_LENGTH = 500;

    /**
     * Reemplaza datos biométricos y credenciales por [REDACTADO] y recorta texto crudo.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redactSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = '[REDACTADO]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redactSensitive($value);
            } elseif ($key === 'raw' && is_string($value) && mb_strlen($value) > self::MAX_RAW_LOG_LENGTH) {
                $data[$key] = mb_substr($value, 0, self::MAX_RAW_LOG_LENGTH).'… [recortado]';
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function normalizeAcsEvent(array $item): ?array
    {
        $employeeNo = trim((string) ($item['employeeNoString'] ?? $item['employeeNo'] ?? ''));
        $timeRaw = (string) ($item['time'] ?? '');

        if ($employeeNo === '' || $timeRaw === '') {
            return null;
        }

        try {
            $punchedAt = Carbon::parse($timeRaw)->setTimezone((string) config('app.timezone', 'America/Mexico_City'));
        } catch (\Throwable) {
            return null;
        }

        return [
            'employee_no' => $employeeNo,
            'device_ip' => $this->getDeviceIp(),
            'punched_at' => $punchedAt,
            'event_type' => $this->mapAttendanceStatus($item['attendanceStatus'] ?? null),
            'verification_mode' => $this->mapVerificationMode($item),
            'raw_payload' => $item,
        ];
    }

    private function mapAttendanceStatus(?string $status): string
    {
        return match ($status) {
            'checkIn' => 'check_in',
            'breakOut' => 'break_out',
            'breakIn' => 'break_in',
            'checkOut' => 'check_out',
            default => 'unclassified',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapVerificationMode(array $item): string
    {
        $mode = strtolower((string) ($item['currentVerifyMode'] ?? ''));

        if ($mode !== '') {
            if (str_contains($mode, 'finger')) {
                return 'fingerprint';
            }
            if (str_contains($mode, 'face')) {
                return 'face';
            }
            if (str_contains($mode, 'card')) {
                return 'card';
            }
            if (str_contains($mode, 'pw') || str_contains($mode, 'pin') || str_contains($mode, 'password')) {
                return 'pin';
            }

            return $mode;
        }

        $minor = (int) ($item['minor'] ?? 0);

        return match ($minor) {
            1, 9 => 'card',
            10 => 'pin',
            75 => 'face',
            default => 'fingerprint',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $paths
     */
    private function extractNestedValue(array $data, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = data_get($data, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
