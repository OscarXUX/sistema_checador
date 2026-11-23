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

    private bool $useDeviceWallTime;

    public function __construct(
        ?string $baseUrl = null,
        ?string $username = null,
        ?string $password = null,
        ?string $deviceSerial = null,
        ?int $timeout = null,
        ?bool $useDeviceWallTime = null,
    ) {
        $config = config('services.hikvision', []);

        $this->baseUrl = rtrim((string) ($baseUrl ?? $config['url'] ?? 'http://192.168.0.69'), '/');
        $this->username = (string) ($username ?? $config['username'] ?? 'admin');
        $this->password = (string) ($password ?? $config['password'] ?? '');
        $this->deviceSerial = (string) ($deviceSerial ?? $config['device_serial'] ?? 'DS-K1T804AEF');
        $this->timeout = (int) ($timeout ?? $config['timeout'] ?? 10);
        $this->useDeviceWallTime = $useDeviceWallTime ?? (bool) ($config['use_device_wall_time'] ?? true);
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
     * Consulta por ISAPI qué medios de verificación soporta la terminal (huella, rostro, tarjeta, PIN).
     *
     * @return array<string, mixed>
     */
    public function detectVerificationCapabilities(): array
    {
        $checkedAt = now()->toIso8601String();

        try {
            $accessCap = $this->fetchCapabilityDocument('/ISAPI/AccessControl/capabilities?format=json', '/ISAPI/AccessControl/capabilities');
        } catch (ConnectionException $e) {
            Log::warning('Hikvision ISAPI capability detection failed (offline/timeout)', [
                'url' => $this->baseUrl,
                'error' => $e->getMessage(),
            ]);

            return [
                'online' => false,
                'device_ip' => $this->getDeviceIp(),
                'checked_at' => $checkedAt,
                'methods' => [
                    'fingerprint' => ['supported' => null, 'source' => null],
                    'face' => ['supported' => null, 'source' => null],
                    'card' => ['supported' => null, 'source' => null],
                    'pin' => ['supported' => null, 'source' => null],
                ],
                'verify_modes_reported' => [],
                'message' => 'Terminal fuera de línea o sin respuesta en '.$this->getDeviceIp().'. No fue posible detectar capacidades.',
            ];
        }

        if ($accessCap['status'] === 401) {
            return [
                'online' => false,
                'device_ip' => $this->getDeviceIp(),
                'checked_at' => $checkedAt,
                'methods' => [
                    'fingerprint' => ['supported' => null, 'source' => null],
                    'face' => ['supported' => null, 'source' => null],
                    'card' => ['supported' => null, 'source' => null],
                    'pin' => ['supported' => null, 'source' => null],
                ],
                'verify_modes_reported' => [],
                'message' => 'Error de autenticación Digest Auth al consultar capacidades de la terminal.',
            ];
        }

        $fpCap = $this->safeFetchCapabilityDocument('/ISAPI/AccessControl/FingerPrint/capabilities?format=json', '/ISAPI/AccessControl/FingerPrint/capabilities');
        $cardCap = $this->safeFetchCapabilityDocument('/ISAPI/AccessControl/CardInfo/capabilities?format=json', '/ISAPI/AccessControl/CardInfo/capabilities');
        $userCap = $this->safeFetchCapabilityDocument('/ISAPI/AccessControl/UserInfo/capabilities?format=json', '/ISAPI/AccessControl/UserInfo/capabilities');
        $faceCap = $this->safeFetchCapabilityDocument('/ISAPI/Intelligent/FDLib/capabilities?format=json', '/ISAPI/Intelligent/FDLib/capabilities');

        $verifyModes = $this->extractVerifyModeValues([
            $accessCap['body'],
            $fpCap['body'],
            $cardCap['body'],
            $userCap['body'],
            $faceCap['body'],
        ]);

        $fingerprintSupported = $this->resolveCapabilityFlag(
            [$accessCap, $fpCap],
            ['isSupportFingerPrint', 'supportFingerPrint', 'FingerPrintCfg', 'FingerPrint', 'CaptureFingerPrint'],
            $verifyModes,
            ['finger', 'fp']
        );

        $faceSupported = $this->resolveCapabilityFlag(
            [$accessCap, $faceCap],
            ['isSupportFace', 'supportFace', 'isSupportFDLib', 'FDLib', 'FaceData', 'isSupportFaceRecognition'],
            $verifyModes,
            ['face']
        );

        $cardSupported = $this->resolveCapabilityFlag(
            [$accessCap, $cardCap],
            ['isSupportCard', 'supportCard', 'CardInfo', 'cardNo'],
            $verifyModes,
            ['card']
        );

        $pinSupported = $this->resolveCapabilityFlag(
            [$accessCap, $userCap],
            ['isSupportVerifyPIN', 'isSupportPassword', 'password', 'pin', 'verifyPIN'],
            $verifyModes,
            ['pw', 'pin', 'password']
        );

        return [
            'online' => true,
            'device_ip' => $this->getDeviceIp(),
            'checked_at' => $checkedAt,
            'methods' => [
                'fingerprint' => ['supported' => $fingerprintSupported, 'endpoint' => '/ISAPI/AccessControl/FingerPrint/capabilities'],
                'face' => ['supported' => $faceSupported, 'endpoint' => '/ISAPI/Intelligent/FDLib/capabilities'],
                'card' => ['supported' => $cardSupported, 'endpoint' => '/ISAPI/AccessControl/CardInfo/capabilities'],
                'pin' => ['supported' => $pinSupported, 'endpoint' => '/ISAPI/AccessControl/UserInfo/capabilities'],
            ],
            'verify_modes_reported' => $verifyModes,
            'message' => 'Capacidades de verificación detectadas correctamente vía ISAPI.',
        ];
    }

    /**
     * Registra o actualiza una tarjeta RFID asociada al empleado en la terminal Hikvision.
     *
     * @return array<string, mixed>
     *
     * @throws HikvisionConnectionException
     */
    public function enrollCardToDevice(Employee $employee, string $cardNo): array
    {
        $cleanCardNo = trim($cardNo);
        $this->syncUserToDevice($employee);

        $endpoint = '/ISAPI/AccessControl/CardInfo/Record?format=json';
        $payload = [
            'CardInfo' => [
                'employeeNo' => (string) $employee->hikvision_employee_no,
                'cardNo' => $cleanCardNo,
                'cardType' => 'normalCard',
            ],
        ];

        $response = $this->sendRequest('post', $endpoint, $payload);
        $body = $this->parseResponseBody($response);

        $subCode = strtolower((string) ($body['subStatusCode'] ?? ''));
        if (! $response->successful() && (str_contains($subCode, 'exist') || str_contains($subCode, 'already') || str_contains($subCode, 'duplicate'))) {
            $endpoint = '/ISAPI/AccessControl/CardInfo/Modify?format=json';
            $response = $this->sendRequest('put', $endpoint, $payload);
            $body = $this->parseResponseBody($response);
        }

        $this->assertIsapiSuccess($response, $body, $endpoint, 'No se pudo asociar la tarjeta RFID en la terminal.');

        $employee->forceFill([
            'card_no' => $cleanCardNo,
            'card_enrolled' => true,
            'device_synced_at' => now(),
        ])->save();

        return [
            'success' => true,
            'employee_no' => $employee->hikvision_employee_no,
            'card_no' => $cleanCardNo,
            'endpoint' => $endpoint,
            'message' => "Tarjeta {$cleanCardNo} asignada en la terminal al empleado {$employee->hikvision_employee_no}.",
        ];
    }

    /**
     * Configura el PIN numérico del empleado directamente en la terminal Hikvision sin almacenarlo en la base.
     *
     * @return array<string, mixed>
     *
     * @throws HikvisionConnectionException
     */
    public function enrollPinToDevice(Employee $employee, string $pin): array
    {
        $cleanPin = trim($pin);
        if (preg_match('/^\d{4,8}$/', $cleanPin) !== 1) {
            throw new \InvalidArgumentException('El PIN debe tener entre 4 y 8 dígitos numéricos.');
        }

        $this->syncUserToDevice($employee);

        $endpoint = '/ISAPI/AccessControl/UserInfo/Modify?format=json';
        $payload = [
            'UserInfo' => [
                'employeeNo' => (string) $employee->hikvision_employee_no,
                'name' => mb_substr(trim($employee->full_name), 0, 32),
                'userType' => 'normal',
                'password' => $cleanPin,
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

        $response = $this->sendRequest('put', $endpoint, $payload);
        $body = $this->parseResponseBody($response);
        $this->assertIsapiSuccess($response, $body, $endpoint, 'No se pudo configurar el PIN en la terminal.');

        // Nunca se guarda el PIN en BD; solo queda la bandera de que se envió al reloj.
        $employee->forceFill([
            'pin_configured' => true,
            'device_synced_at' => now(),
        ])->save();

        return [
            'success' => true,
            'employee_no' => $employee->hikvision_employee_no,
            'pin_configured' => true,
            'endpoint' => $endpoint,
            'message' => "PIN actualizado en la terminal para el empleado {$employee->hikvision_employee_no} (no almacenado en la base).",
        ];
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
    public function triggerRemoteFingerprintCapture(Employee|string $employee, int $fingerNo = 1): array
    {
        $employeeModel = $employee instanceof Employee
            ? $employee
            : Employee::where('hikvision_employee_no', $employee)->first();
        $employeeNo = $employeeModel?->hikvision_employee_no ?? (string) $employee;

        if ($employeeModel !== null) {
            $this->syncUserToDevice($employeeModel);
        }

        $endpoint = '/ISAPI/AccessControl/CaptureFingerPrint';
        $fingerIndex = max(1, min(10, $fingerNo));

        $xmlWithReader = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CaptureFingerPrintCond xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">
    <cardReaderNo>1</cardReaderNo>
    <fingerNo>{$fingerIndex}</fingerNo>
</CaptureFingerPrintCond>
XML;

        $response = $this->sendXmlCaptureRequest($endpoint, $xmlWithReader);
        $body = $this->parseResponseBody($response);

        if ($this->isBadXmlContentResponse($response, $body)) {
            Log::error('Hikvision ISAPI CaptureFingerPrint devolvió 0x50000001 con <cardReaderNo>; reintentando sin <cardReaderNo>', [
                'endpoint' => $endpoint,
                'employee_no' => $employeeNo,
                'finger_no' => $fingerIndex,
                'http_status' => $response->status(),
                'body' => $this->redactSensitive($body),
            ]);

            $xmlWithoutReader = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<CaptureFingerPrintCond xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">
    <fingerNo>{$fingerIndex}</fingerNo>
</CaptureFingerPrintCond>
XML;

            $response = $this->sendXmlCaptureRequest($endpoint, $xmlWithoutReader);
            $body = $this->parseResponseBody($response);
        }

        $this->assertIsapiSuccess($response, $body, $endpoint, 'No se pudo capturar la huella desde el lector óptico.');

        $fingerData = $this->extractNestedValue($body, [
            'fingerData',
            'fingerPrintData.fingerData',
            'CaptureFingerPrint.fingerData',
            'CaptureFingerPrint.fingerPrintData.fingerData',
            'FingerPrintData.fingerData',
        ]);

        $cleanFingerData = is_string($fingerData) ? trim($fingerData) : '';

        // Sin plantilla no hay huella registrada: se falla en lugar de marcarla como capturada (SE-10)
        if ($cleanFingerData === '') {
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

        $this->bindCapturedFingerprint($employeeNo, $fingerIndex, $cleanFingerData);

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
     * @throws HikvisionConnectionException
     */
    private function sendXmlCaptureRequest(string $endpoint, string $xmlPayload): Response
    {
        try {
            return $this->client(max($this->timeout, 35))
                ->withHeaders(['Content-Type' => 'application/xml'])
                ->withBody($xmlPayload, 'application/xml')
                ->post($this->endpoint($endpoint));
        } catch (ConnectionException $e) {
            Log::warning('Hikvision ISAPI CaptureFingerPrint connection failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new HikvisionConnectionException(
                'Tiempo de espera agotado al activar el lector biométrico en '.$this->getDeviceIp().'.',
                null,
                $endpoint,
                $e
            );
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function isBadXmlContentResponse(Response $response, array $body): bool
    {
        $subStatusCode = strtolower((string) ($body['subStatusCode'] ?? ''));
        $errorCode = (string) ($body['errorCode'] ?? '');
        $rawLower = strtolower($response->body());

        return $subStatusCode === 'badxmlcontent'
            || $errorCode === '1342177281'
            || str_contains($rawLower, '0x50000001')
            || str_contains($rawLower, 'badxmlcontent');
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
                'fingerType' => 'normalFP',
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
                    'fingerType' => 'normalFP',
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
        'pin',
        'cardpasswd',
        'cardpassword',
        'userpassword',
        'verifypin',
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

        $punchedAt = $this->parseDeviceTimestamp($timeRaw);
        if ($punchedAt === null) {
            return null;
        }

        $statusValue = isset($item['statusValue']) && is_numeric($item['statusValue'])
            ? (int) $item['statusValue']
            : null;

        return [
            'employee_no' => $employeeNo,
            'device_ip' => $this->getDeviceIp(),
            'punched_at' => $punchedAt,
            'event_type' => $this->mapAttendanceStatus($item['attendanceStatus'] ?? null, $statusValue),
            'verification_mode' => $this->mapVerificationMode($item),
            'raw_payload' => $item,
        ];
    }

    private function parseDeviceTimestamp(string $timeRaw): ?Carbon
    {
        $appTimezone = (string) config('app.timezone', 'America/Mexico_City');

        if ($this->useDeviceWallTime && preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $timeRaw, $matches) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d H:i:s', "{$matches[1]} {$matches[2]}", $appTimezone) ?: null;
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($timeRaw)->setTimezone($appTimezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * En la terminal DS-K1T804AEF el teclado de comidas reporta statusValue=3 (breakIn) al salir a comer
     * y statusValue=4 (breakOut) al regresar de comer; se respeta statusValue cuando está presente.
     */
    private function mapAttendanceStatus(?string $status, ?int $statusValue = null): string
    {
        if ($statusValue !== null) {
            $byCode = match ($statusValue) {
                1 => 'check_in',
                2 => 'check_out',
                3 => 'break_out',
                4 => 'break_in',
                default => null,
            };

            if ($byCode !== null) {
                return $byCode;
            }
        }

        return match ($status) {
            'checkIn' => 'check_in',
            'breakOut' => 'break_out',
            'breakIn' => 'break_in',
            'checkOut' => 'check_out',
            default => 'unclassified',
        };
    }

    /**
     * Normaliza el medio de marcaje combinando currentVerifyMode y el código minor de AcsEvent.
     *
     * Cuando la terminal opera con reglas compuestas (ej. cardOrFp, faceOrFpOrCardOrPw),
     * currentVerifyMode indica la regla habilitada y minor identifica el método real usado.
     *
     * @param  array<string, mixed>  $item
     */
    public function mapVerificationMode(array $item): string
    {
        $rawMode = strtolower(trim((string) ($item['currentVerifyMode'] ?? '')));
        $minor = isset($item['minor']) && is_numeric($item['minor']) ? (int) $item['minor'] : null;

        $isCompoundRule = $rawMode !== ''
            && (str_contains($rawMode, 'or') || str_contains($rawMode, 'and') || str_contains($rawMode, '+'));

        if ($rawMode !== '' && ! $isCompoundRule) {
            if (str_contains($rawMode, 'finger') || $rawMode === 'fp') {
                return 'fingerprint';
            }
            if (str_contains($rawMode, 'face')) {
                return 'face';
            }
            if (str_contains($rawMode, 'card')) {
                return 'card';
            }
            if ($rawMode === 'pw' || $rawMode === 'pin' || str_contains($rawMode, 'password') || str_contains($rawMode, 'pin')) {
                return 'pin';
            }
        }

        if ($minor !== null) {
            $byMinor = match ($minor) {
                1, 2, 3, 4, 5, 6, 7, 9 => 'card',
                10, 21 => 'pin',
                38, 39, 104, 105 => 'fingerprint',
                75 => 'face',
                default => null,
            };

            if ($byMinor !== null) {
                return $byMinor;
            }
        }

        if ($rawMode !== '') {
            if (str_contains($rawMode, 'finger')) {
                return 'fingerprint';
            }
            if (str_contains($rawMode, 'face')) {
                return 'face';
            }
            if (str_contains($rawMode, 'card')) {
                return 'card';
            }
            if (str_contains($rawMode, 'pw') || str_contains($rawMode, 'pin') || str_contains($rawMode, 'password')) {
                return 'pin';
            }

            return mb_substr($rawMode, 0, 32);
        }

        return 'fingerprint';
    }

    /**
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     *
     * @throws ConnectionException
     */
    private function fetchCapabilityDocument(string $jsonPath, string $xmlPath): array
    {
        $response = $this->client(min($this->timeout, 5))
            ->acceptJson()
            ->get($this->endpoint($jsonPath));

        if ($response->status() === 400 || $response->status() === 404) {
            $response = $this->client(min($this->timeout, 5))
                ->get($this->endpoint($xmlPath));
        }

        $body = $response->successful() ? $this->parseResponseBody($response) : [];
        $statusCode = isset($body['statusCode'])
            ? (int) $body['statusCode']
            : (isset($body['ResponseStatus']['statusCode']) ? (int) $body['ResponseStatus']['statusCode'] : null);
        $isIsapiOk = $response->successful() && ($statusCode === null || $statusCode === 1);

        return [
            'ok' => $isIsapiOk,
            'status' => $response->status(),
            'body' => $isIsapiOk ? $body : [],
        ];
    }

    /**
     * @return array{ok: bool, status: int, body: array<string, mixed>}
     */
    private function safeFetchCapabilityDocument(string $jsonPath, string $xmlPath): array
    {
        try {
            return $this->fetchCapabilityDocument($jsonPath, $xmlPath);
        } catch (ConnectionException) {
            return ['ok' => false, 'status' => 0, 'body' => []];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $bodies
     * @return list<string>
     */
    private function extractVerifyModeValues(array $bodies): array
    {
        $found = [];

        $walker = function (array $node) use (&$walker, &$found): void {
            foreach ($node as $key => $value) {
                $keyLower = strtolower((string) $key);
                if (in_array($keyLower, ['verifymode', 'currentverifymode', 'supportverifymode', 'opt', 'enum'], true)) {
                    if (is_string($value)) {
                        foreach (preg_split('/[\s,|]+/', $value) ?: [] as $part) {
                            $part = trim($part);
                            if ($part !== '') {
                                $found[$part] = true;
                            }
                        }
                    } elseif (is_array($value)) {
                        array_walk_recursive($value, function ($leaf) use (&$found): void {
                            if (is_string($leaf) || is_numeric($leaf)) {
                                foreach (preg_split('/[\s,|]+/', (string) $leaf) ?: [] as $part) {
                                    $part = trim($part);
                                    if ($part !== '') {
                                        $found[$part] = true;
                                    }
                                }
                            }
                        });
                    }
                } elseif (is_array($value)) {
                    $walker($value);
                }
            }
        };

        foreach ($bodies as $body) {
            if (is_array($body) && $body !== []) {
                $walker($body);
            }
        }

        return array_values(array_keys($found));
    }

    /**
     * @param  array<int, array{ok: bool, status: int, body: array<string, mixed>}>  $documents
     * @param  list<string>  $keys
     * @param  list<string>  $verifyModes
     * @param  list<string>  $modeNeedles
     */
    private function resolveCapabilityFlag(
        array $documents,
        array $keys,
        array $verifyModes,
        array $modeNeedles,
    ): bool {
        $lowerKeys = array_map('strtolower', $keys);
        $explicitValue = null;

        $inspect = function (array $node) use (&$inspect, $lowerKeys, &$explicitValue): void {
            foreach ($node as $k => $v) {
                if (in_array(strtolower((string) $k), $lowerKeys, true)) {
                    if (is_bool($v)) {
                        $explicitValue = $v;

                        return;
                    }
                    if (is_string($v) && in_array(strtolower($v), ['true', 'false', '0', '1'], true)) {
                        $parsed = filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($parsed !== null) {
                            $explicitValue = $parsed;

                            return;
                        }
                    }
                    if (is_array($v) && $v !== []) {
                        $explicitValue = true;
                    }
                }
                if (is_array($v)) {
                    $inspect($v);
                    if ($explicitValue === false) {
                        return;
                    }
                }
            }
        };

        foreach ($documents as $doc) {
            if ($doc['ok'] && is_array($doc['body'])) {
                $inspect($doc['body']);
                if ($explicitValue === false) {
                    return false;
                }
            }
        }

        if ($explicitValue === true) {
            return true;
        }

        foreach ($verifyModes as $mode) {
            $modeLower = strtolower($mode);
            foreach ($modeNeedles as $needle) {
                if (str_contains($modeLower, $needle)) {
                    return true;
                }
            }
        }

        // Si el endpoint específico de esa capacidad respondió 200 OK sin marca explícita negativa, lo soporta
        return isset($documents[1]) && $documents[1]['ok'] === true;
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
