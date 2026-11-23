<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MarkingMethod;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarkingMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $shift = WorkShift::create([
            'name' => 'Matutino', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);

        $this->employee = Employee::create([
            'hikvision_employee_no' => '3001',
            'full_name' => 'Diego Navarro',
            'department' => 'Operaciones',
            'position' => 'Técnico',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);
    }

    public function test_isapi_capability_detection_updates_catalog(): void
    {
        Http::fake([
            '*/ISAPI/AccessControl/capabilities*' => Http::response([
                'AccessControl' => [
                    'isSupportFingerPrint' => true,
                    'isSupportCard' => true,
                    'isSupportVerifyPIN' => true,
                    'isSupportFace' => false,
                ],
            ], 200),
            '*/ISAPI/AccessControl/FingerPrint/capabilities*' => Http::response(['FingerPrintCap' => ['maxFingerPrintNum' => 3000]], 200),
            '*/ISAPI/AccessControl/CardInfo/capabilities*' => Http::response(['CardInfoCap' => ['maxCardNum' => 3000]], 200),
            '*/ISAPI/AccessControl/UserInfo/capabilities*' => Http::response(['UserInfoCap' => ['password' => ['minLen' => 4]]], 200),
            '*/ISAPI/Intelligent/FDLib/capabilities*' => Http::response(['statusCode' => 4, 'subStatusCode' => 'notSupport'], 404),
        ]);

        $this->postJson('/marking-methods/detect')
            ->assertOk()
            ->assertJsonPath('online', true)
            ->assertJsonPath('detection.methods.fingerprint.supported', true)
            ->assertJsonPath('detection.methods.card.supported', true)
            ->assertJsonPath('detection.methods.pin.supported', true)
            ->assertJsonPath('detection.methods.face.supported', false);

        $this->assertTrue((bool) MarkingMethod::where('code', 'fingerprint')->value('device_supported'));
        $this->assertTrue((bool) MarkingMethod::where('code', 'card')->value('device_supported'));
        $this->assertTrue((bool) MarkingMethod::where('code', 'pin')->value('device_supported'));
        $this->assertFalse((bool) MarkingMethod::where('code', 'face')->value('device_supported'));

        $this->assertDatabaseHas('audit_logs', ['action' => 'marking_methods.detected']);
    }

    public function test_catalog_toggle_and_te02_privacy_block_on_face(): void
    {
        $card = MarkingMethod::where('code', 'card')->firstOrFail();
        $face = MarkingMethod::where('code', 'face')->firstOrFail();

        $this->patchJson("/marking-methods/{$card->id}", ['is_enabled' => false])
            ->assertOk()
            ->assertJsonPath('method.is_enabled', false);

        $this->postJson("/employees/{$this->employee->id}/enroll-card", ['card_no' => '99887766'])
            ->assertStatus(422);

        $this->patchJson("/marking-methods/{$face->id}", ['is_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_enabled']);

        $this->assertFalse((bool) $face->fresh()->is_enabled);

        $this->postJson("/employees/{$this->employee->id}/enroll-face")
            ->assertStatus(422)
            ->assertJsonPath('privacy_notice', 'TE-02');
    }

    public function test_card_and_pin_enrollment_send_to_isapi_and_never_store_or_log_pin(): void
    {
        Http::fake([
            '*/ISAPI/AccessControl/UserInfo/Record*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
            '*/ISAPI/AccessControl/UserInfo/Modify*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
            '*/ISAPI/AccessControl/CardInfo/Record*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);

        // Alta de tarjeta RFID
        $this->postJson("/employees/{$this->employee->id}/enroll-card", ['card_no' => 'RFID123456'])
            ->assertOk()
            ->assertJsonPath('employee.card_enrolled', true)
            ->assertJsonPath('employee.card_no', 'RFID123456');

        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), 'CardInfo/Record')
            && data_get($req->data(), 'CardInfo.cardNo') === 'RFID123456');

        // Alta de PIN: se manda al reloj pero NUNCA queda en BD ni audit_logs ni respuesta
        $secretPin = '482910';
        $pinResponse = $this->postJson("/employees/{$this->employee->id}/enroll-pin", ['pin' => $secretPin])
            ->assertOk()
            ->assertJsonPath('pin_configured', true)
            ->assertJsonPath('employee.pin_configured', true);

        $this->assertStringNotContainsString($secretPin, (string) $pinResponse->getContent());

        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), 'UserInfo/Modify')
            && data_get($req->data(), 'UserInfo.password') === $secretPin);

        $rawEmployee = json_encode(DB::table('employees')->where('id', $this->employee->id)->first());
        $rawAuditLogs = json_encode(DB::table('audit_logs')->get());

        $this->assertStringNotContainsString($secretPin, (string) $rawEmployee);
        $this->assertStringNotContainsString($secretPin, (string) $rawAuditLogs);
        $this->assertDatabaseHas('audit_logs', ['action' => 'device.pin_enrolled']);
    }

    public function test_map_verification_mode_covers_simple_compound_and_minor_codes(): void
    {
        $isapi = new HikvisionIsapiService;

        $this->assertSame('fingerprint', $isapi->mapVerificationMode(['currentVerifyMode' => 'fingerPrint']));
        $this->assertSame('face', $isapi->mapVerificationMode(['currentVerifyMode' => 'face']));
        $this->assertSame('card', $isapi->mapVerificationMode(['currentVerifyMode' => 'card']));
        $this->assertSame('pin', $isapi->mapVerificationMode(['currentVerifyMode' => 'pw']));

        // Regla compuesta (Or): minor determina el medio real usado en esa checada
        $this->assertSame('card', $isapi->mapVerificationMode(['currentVerifyMode' => 'cardOrFp', 'minor' => 1]));
        $this->assertSame('fingerprint', $isapi->mapVerificationMode(['currentVerifyMode' => 'cardOrFp', 'minor' => 38]));
        $this->assertSame('pin', $isapi->mapVerificationMode(['currentVerifyMode' => 'faceOrFpOrCardOrPw', 'minor' => 10]));
        $this->assertSame('face', $isapi->mapVerificationMode(['currentVerifyMode' => 'cardOrFace', 'minor' => 75]));

        // Sin currentVerifyMode (firmware DS-K1T804AEF que solo manda minor)
        $this->assertSame('fingerprint', $isapi->mapVerificationMode(['minor' => 38]));
    }
}
