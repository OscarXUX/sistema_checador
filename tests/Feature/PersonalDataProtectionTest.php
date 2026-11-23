<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\PersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC, CURP y NSS enmascarados en pantalla y cifrados en la base (auditoría SE-05).
 */
class PersonalDataProtectionTest extends TestCase
{
    use RefreshDatabase;

    private const RFC = 'HELS940312MDF';

    private const CURP = 'HELS940312MDFRRF02';

    private const NSS = '12345678901';

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
            'hikvision_employee_no' => '7001',
            'full_name' => 'Sofía Herrera León',
            'rfc' => self::RFC,
            'curp' => self::CURP,
            'nss' => self::NSS,
            'department' => 'Calidad',
            'position' => 'Inspectora',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);
    }

    public function test_values_are_encrypted_in_the_database(): void
    {
        $raw = DB::table('employees')->where('id', $this->employee->id)->first();

        $this->assertNotSame(self::RFC, $raw->rfc);
        $this->assertSame(self::RFC, Crypt::decryptString($raw->rfc));
        $this->assertSame(self::CURP, Crypt::decryptString($raw->curp));
        $this->assertSame(self::NSS, Crypt::decryptString($raw->nss));
        $this->assertSame(PersonalData::blindIndex(self::RFC), $raw->rfc_hash);

        // El modelo los entrega descifrados
        $this->assertSame(self::RFC, $this->employee->fresh()->rfc);
    }

    public function test_listing_shows_masked_values_only(): void
    {
        $response = $this->getJson('/employees/list')
            ->assertOk()
            ->assertJsonPath('employees.0.rfc', 'HELS••••••MDF')
            ->assertJsonPath('employees.0.curp', 'HELS••••••••••••02')
            ->assertJsonPath('employees.0.nss', '•••••••8901')
            ->assertJsonPath('employees.0.personal_ids_masked', true)
            ->assertJsonMissingPath('employees.0.rfc_hash');

        $this->assertStringNotContainsString(self::RFC, (string) $response->getContent());
        $this->assertStringNotContainsString(self::CURP, (string) $response->getContent());

        $this->withoutVite();
        $page = $this->get('/employees')->assertOk();
        $this->assertStringNotContainsString(self::RFC, (string) $page->getContent());
    }

    public function test_edit_endpoint_returns_full_values_and_is_audited(): void
    {
        $this->getJson("/employees/{$this->employee->id}")
            ->assertOk()
            ->assertJsonPath('employee.rfc', self::RFC)
            ->assertJsonPath('employee.curp', self::CURP)
            ->assertJsonPath('employee.nss', self::NSS)
            ->assertJsonPath('employee.personal_ids_masked', false);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.personal_data_viewed',
            'auditable_id' => $this->employee->id,
        ]);
    }

    public function test_search_finds_full_rfc_or_curp_but_not_fragments(): void
    {
        $this->getJson('/employees/list?q='.strtolower(self::RFC))->assertJsonPath('meta.total', 1);
        $this->getJson('/employees/list?q='.self::CURP)->assertJsonPath('meta.total', 1);
        $this->getJson('/employees/list?q=940312')->assertJsonPath('meta.total', 0);
    }

    public function test_kardex_masks_personal_ids(): void
    {
        $response = $this->getJson('/reports/kardex/data')->assertOk();

        $this->assertStringNotContainsString(self::RFC, (string) $response->getContent());
    }

    public function test_masking_helpers(): void
    {
        $this->assertSame('VELC••••••MNL', PersonalData::maskRfc('VELC881103MNL'));
        $this->assertSame('VEL8•••••MNL', PersonalData::maskRfc('VEL881103MNL'));
        $this->assertSame('•••••••3456', PersonalData::maskNss('22881103456'));
        $this->assertNull(PersonalData::maskCurp(null));
        $this->assertNull(PersonalData::blindIndex('  '));
        $this->assertSame(PersonalData::blindIndex('velc881103mnl '), PersonalData::blindIndex('VELC881103MNL'));
    }
}
