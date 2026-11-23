<?php

namespace Tests\Unit;

use App\Exceptions\HikvisionConnectionException;
use App\Models\Employee;
use App\Models\WorkShift;
use App\Services\HikvisionIsapiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HikvisionIsapiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function createEmployee(string $employeeNo = '1001'): Employee
    {
        $shift = WorkShift::create([
            'name' => 'Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);

        return Employee::create([
            'hikvision_employee_no' => $employeeNo,
            'full_name' => 'Ana Sofía Mendoza',
            'rfc' => 'MERA920415HDF',
            'curp' => 'MERA920415MDFNNS01',
            'nss' => '11920415123',
            'department' => 'Ingeniería',
            'position' => 'Arquitecta',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);
    }

    public function test_test_connection_returns_online_metadata_with_digest_auth(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/System/deviceInfo*' => Http::response([
                'DeviceInfo' => [
                    'deviceName' => 'Terminal Planta',
                    'model' => 'DS-K1T804AEF',
                    'serialNumber' => 'DS-K1T804AEF20260101',
                    'firmwareVersion' => 'V3.2.30',
                    'macAddress' => 'aa:bb:cc:dd:ee:ff',
                ],
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $status = $service->testConnection();

        $this->assertTrue($status['online']);
        $this->assertSame('DS-K1T804AEF', $status['model']);
        $this->assertSame('V3.2.30', $status['firmware_version']);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/ISAPI/System/deviceInfo')
                && $request->method() === 'GET';
        });
    }

    public function test_test_connection_returns_offline_when_unauthorized(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/System/deviceInfo*' => Http::response('Unauthorized', 401),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'wrong', 'DS-K1T804AEF', 5);
        $status = $service->testConnection();

        $this->assertFalse($status['online']);
        $this->assertSame(401, $status['http_status']);
    }

    public function test_sync_user_to_device_creates_and_falls_back_to_modify_if_exists(): void
    {
        $employee = $this->createEmployee('2050');

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 6,
                'statusString' => 'Invalid Content',
                'subStatusCode' => 'deviceUserAlreadyExist',
            ], 400),
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Modify?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $result = $service->syncUserToDevice($employee);

        $this->assertTrue($result['success']);
        $this->assertSame('/ISAPI/AccessControl/UserInfo/Modify?format=json', $result['endpoint']);
        $this->assertNotNull($employee->fresh()->device_synced_at);
    }

    public function test_delete_user_from_device_sends_put_delete_payload(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Delete?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $result = $service->deleteUserFromDevice('2050');

        $this->assertTrue($result['success']);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->method() === 'PUT'
                && ($data['UserInfoDelCond']['EmployeeNoList'][0]['employeeNo'] ?? null) === '2050';
        });
    }

    public function test_trigger_remote_fingerprint_capture_binds_template_and_updates_employee(): void
    {
        $employee = $this->createEmployee('3001');

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?><CaptureFingerPrint version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><fingerNo>2</fingerNo><fingerData>BASE64_FINGERPRINT_TEMPLATE</fingerData></CaptureFingerPrint>',
                200,
                ['Content-Type' => 'application/xml']
            ),
            'http://192.168.0.69/ISAPI/AccessControl/FingerPrint/SetUp?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $result = $service->triggerRemoteFingerprintCapture($employee, 2);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['template_captured']);
        $this->assertTrue($employee->fresh()->fingerprint_enrolled);

        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Record?format=json')
                && ($request->data()['UserInfo']['employeeNo'] ?? null) === '3001';
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/ISAPI/AccessControl/CaptureFingerPrint')) {
                return false;
            }

            $body = $request->body();

            return str_contains($request->header('Content-Type')[0] ?? '', 'application/xml')
                && str_contains($body, '<CaptureFingerPrintCond xmlns="http://www.isapi.org/ver20/XMLSchema" version="2.0">')
                && str_contains($body, '<cardReaderNo>1</cardReaderNo>')
                && str_contains($body, '<fingerNo>2</fingerNo>');
        });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/ISAPI/AccessControl/FingerPrint/SetUp?format=json')) {
                return false;
            }

            $cfg = $request->data()['FingerPrintCfg'] ?? [];

            return ($cfg['employeeNo'] ?? null) === '3001'
                && ($cfg['enableCardReader'] ?? null) === [1]
                && ($cfg['fingerPrintID'] ?? null) === 2
                && ($cfg['fingerType'] ?? null) === 'normalFP'
                && ($cfg['fingerData'] ?? null) === 'BASE64_FINGERPRINT_TEMPLATE';
        });
    }

    public function test_trigger_remote_fingerprint_capture_retries_without_card_reader_on_0x50000001(): void
    {
        Log::spy();
        $employee = $this->createEmployee('3002');
        $captureAttempt = 0;

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => function (Request $request) use (&$captureAttempt) {
                $captureAttempt++;

                if ($captureAttempt === 1) {
                    return Http::response(
                        '<?xml version="1.0" encoding="UTF-8"?><ResponseStatus version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><statusCode>4</statusCode><statusString>Invalid XML Content</statusString><subStatusCode>badXmlContent</subStatusCode><errorCode>0x50000001</errorCode></ResponseStatus>',
                        400,
                        ['Content-Type' => 'application/xml']
                    );
                }

                return Http::response(
                    '<?xml version="1.0" encoding="UTF-8"?><CaptureFingerPrint version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><fingerNo>3</fingerNo><fingerData>RETRY_TEMPLATE_DATA</fingerData></CaptureFingerPrint>',
                    200,
                    ['Content-Type' => 'application/xml']
                );
            },
            'http://192.168.0.69/ISAPI/AccessControl/FingerPrint/SetUp?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $result = $service->triggerRemoteFingerprintCapture($employee, 3);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['template_captured']);
        $this->assertSame(2, $captureAttempt);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, '0x50000001')
                    && ! array_key_exists('raw_body', $context)
                    && ($context['body']['subStatusCode'] ?? null) === 'badXmlContent'
                    && ($context['body']['errorCode'] ?? null) === '0x50000001';
            });

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/ISAPI/AccessControl/CaptureFingerPrint')) {
                return false;
            }

            $body = $request->body();

            return ! str_contains($body, '<cardReaderNo>')
                && str_contains($body, '<fingerNo>3</fingerNo>');
        });
    }

    public function test_fetch_attendance_events_paginates_all_pages(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::sequence()
                ->push([
                    'AcsEvent' => [
                        'responseStatusStrg' => 'MORE',
                        'totalMatches' => 3,
                        'numOfMatches' => 2,
                        'InfoList' => [
                            [
                                'employeeNoString' => '1001',
                                'time' => '2026-11-12T09:02:00-06:00',
                                'attendanceStatus' => 'checkIn',
                                'currentVerifyMode' => 'fingerPrint',
                            ],
                            [
                                'employeeNoString' => '1001',
                                'time' => '2026-11-12T14:00:00-06:00',
                                'attendanceStatus' => 'breakOut',
                                'currentVerifyMode' => 'fingerPrint',
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'AcsEvent' => [
                        'responseStatusStrg' => 'OK',
                        'totalMatches' => 3,
                        'numOfMatches' => 1,
                        'InfoList' => [
                            [
                                'employeeNoString' => '1001',
                                'time' => '2026-11-12T15:00:00-06:00',
                                'attendanceStatus' => 'breakIn',
                                'currentVerifyMode' => 'card',
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $events = $service->fetchAttendanceEvents(
            Carbon::parse('2026-11-12 00:00:00.123456', 'America/Mexico_City'),
            Carbon::parse('2026-11-12 23:59:59.999999', 'America/Mexico_City')
        );

        $this->assertCount(3, $events);
        $this->assertSame('check_in', $events[0]['event_type']);
        $this->assertSame('break_out', $events[1]['event_type']);
        $this->assertSame('break_in', $events[2]['event_type']);
        $this->assertSame('card', $events[2]['verification_mode']);

        Http::assertSentCount(2);

        Http::assertSent(function (Request $request): bool {
            $cond = $request->data()['AcsEventCond'] ?? null;

            return is_array($cond)
                && array_keys($cond) === [
                    'searchID',
                    'searchResultPosition',
                    'maxResults',
                    'major',
                    'minor',
                    'startTime',
                    'endTime',
                ]
                && $cond['searchID'] === '1'
                && $cond['maxResults'] === 20
                && $cond['major'] === 5
                && $cond['minor'] === 0
                && $cond['startTime'] === '2026-11-12T00:00:00-06:00'
                && $cond['endTime'] === '2026-11-12T23:59:59-06:00';
        });
    }

    public function test_logs_full_response_body_when_isapi_status_code_is_not_one(): void
    {
        Log::spy();

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'statusCode' => 6,
                'statusString' => 'Invalid Content',
                'subStatusCode' => 'badJsonContent',
                'errorCode' => 1610612737,
            ], 400),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);

        try {
            $service->fetchAttendanceEvents(
                Carbon::parse('2026-11-12 00:00:00', 'America/Mexico_City'),
                Carbon::parse('2026-11-12 23:59:59', 'America/Mexico_City')
            );
            $this->fail('Se esperaba HikvisionConnectionException');
        } catch (HikvisionConnectionException) {
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function (string $message, array $context): bool {
                    return str_contains($message, 'statusCode != 1')
                        && ($context['status_code'] ?? null) === 6
                        && ($context['body']['errorCode'] ?? null) === 1610612737;
                });
        }
    }

    public function test_delete_user_throws_exception_on_isapi_error(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Delete?format=json' => Http::response([
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'deviceError',
            ], 400),
        ]);

        $this->expectException(HikvisionConnectionException::class);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $service->deleteUserFromDevice('9999');
    }

    public function test_capture_result_does_not_include_fingerprint_template(): void
    {
        $this->createEmployee('3002');

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response([
                'CaptureFingerPrint' => [
                    'fingerPrintData' => [
                        'fingerPrintID' => 1,
                        'fingerData' => 'PLANTILLA_BIOMETRICA_DE_PRUEBA',
                    ],
                ],
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/FingerPrint/SetUp?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);
        $result = $service->triggerRemoteFingerprintCapture('3002', 1);

        $this->assertTrue($result['template_captured']);
        $this->assertArrayNotHasKey('device_response', $result);
        $this->assertStringNotContainsString('PLANTILLA_BIOMETRICA_DE_PRUEBA', (string) json_encode($result));
    }

    public function test_error_log_redacts_fingerprint_template(): void
    {
        Log::spy();

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response([
                'CaptureFingerPrint' => [
                    'fingerPrintData' => [
                        'fingerData' => 'PLANTILLA_BIOMETRICA_DE_PRUEBA',
                    ],
                ],
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'deviceError',
            ], 400),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);

        try {
            $service->triggerRemoteFingerprintCapture('3003', 1);
            $this->fail('Se esperaba HikvisionConnectionException');
        } catch (HikvisionConnectionException) {
            Log::shouldHaveReceived('error')
                ->once()
                ->withArgs(function (string $message, array $context): bool {
                    $encoded = (string) json_encode($context);

                    return ($context['status_code'] ?? null) === 4
                        && ! array_key_exists('raw_body', $context)
                        && ! str_contains($encoded, 'PLANTILLA_BIOMETRICA_DE_PRUEBA')
                        && ($context['body']['CaptureFingerPrint']['fingerPrintData']['fingerData'] ?? null) === '[REDACTADO]';
                });
        }
    }

    public function test_capture_without_template_fails_and_does_not_mark_fingerprint(): void
    {
        $employee = $this->createEmployee('3004');

        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/UserInfo/Record?format=json' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
            'http://192.168.0.69/ISAPI/AccessControl/CaptureFingerPrint' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);

        try {
            $service->triggerRemoteFingerprintCapture('3004', 1);
            $this->fail('Se esperaba HikvisionConnectionException');
        } catch (HikvisionConnectionException $e) {
            $this->assertStringContainsString('no devolvió la huella', $e->getMessage());
        }

        $this->assertFalse($employee->fresh()->fingerprint_enrolled);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'FingerPrint/SetUp'));
    }

    public function test_connection_error_message_hides_technical_detail(): void
    {
        $this->createEmployee('3005');

        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to 192.168.0.69 port 80 after 5001 ms');
        });

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5);

        try {
            $service->syncUserToDevice(Employee::where('hikvision_employee_no', '3005')->firstOrFail());
            $this->fail('Se esperaba HikvisionConnectionException');
        } catch (HikvisionConnectionException $e) {
            $this->assertStringContainsString('No se pudo conectar', $e->getMessage());
            $this->assertStringNotContainsString('cURL', $e->getMessage());
        }
    }

    public function test_maps_ds_k1t804aef_status_value_3_and_4_correctly(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'totalMatches' => 4,
                    'numOfMatches' => 4,
                    'InfoList' => [
                        [
                            'employeeNoString' => '2',
                            'time' => '2026-11-12T09:00:00-06:00',
                            'attendanceStatus' => 'checkIn',
                            'statusValue' => 1,
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '2',
                            'time' => '2026-11-12T14:00:00-06:00',
                            'attendanceStatus' => 'breakIn',
                            'statusValue' => 3,
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '2',
                            'time' => '2026-11-12T14:45:00-06:00',
                            'attendanceStatus' => 'breakOut',
                            'statusValue' => 4,
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                        [
                            'employeeNoString' => '2',
                            'time' => '2026-11-12T18:05:00-06:00',
                            'attendanceStatus' => 'checkOut',
                            'statusValue' => 2,
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $service = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5, true);
        $events = $service->fetchAttendanceEvents(
            Carbon::parse('2026-11-12 00:00:00', 'America/Mexico_City'),
            Carbon::parse('2026-11-12 23:59:59', 'America/Mexico_City')
        );

        $this->assertCount(4, $events);
        $this->assertSame('check_in', $events[0]['event_type']);
        $this->assertSame('break_out', $events[1]['event_type']);
        $this->assertSame('break_in', $events[2]['event_type']);
        $this->assertSame('check_out', $events[3]['event_type']);
    }

    public function test_parses_device_wall_time_when_device_reports_wrong_timezone_offset(): void
    {
        Http::fake([
            'http://192.168.0.69/ISAPI/AccessControl/AcsEvent?format=json' => Http::response([
                'AcsEvent' => [
                    'responseStatusStrg' => 'OK',
                    'totalMatches' => 1,
                    'numOfMatches' => 1,
                    'InfoList' => [
                        [
                            'employeeNoString' => '2',
                            'time' => '2026-11-12T14:30:08+08:00',
                            'attendanceStatus' => 'checkIn',
                            'statusValue' => 1,
                            'currentVerifyMode' => 'fingerPrint',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $wallTimeService = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5, true);
        $wallEvents = $wallTimeService->fetchAttendanceEvents(
            Carbon::parse('2026-11-12 00:00:00', 'America/Mexico_City'),
            Carbon::parse('2026-11-12 23:59:59', 'America/Mexico_City')
        );
        $this->assertSame('2026-11-12 14:30:08', $wallEvents[0]['punched_at']->format('Y-m-d H:i:s'));

        $offsetService = new HikvisionIsapiService('http://192.168.0.69', 'admin', 'secret', 'DS-K1T804AEF', 5, false);
        $offsetEvents = $offsetService->fetchAttendanceEvents(
            Carbon::parse('2026-11-12 00:00:00', 'America/Mexico_City'),
            Carbon::parse('2026-11-12 23:59:59', 'America/Mexico_City')
        );
        $this->assertSame('2026-11-12 00:30:08', $offsetEvents[0]['punched_at']->format('Y-m-d H:i:s'));
    }
}
