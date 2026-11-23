<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nota 16 / P2 de Oscar: foto y tipo de sangre del empleado.
 */
class EmployeePhotoAndHealthTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(Employee::PHOTO_DISK);

        $shift = WorkShift::create([
            'name' => 'Matutino', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);
        $this->employee = Employee::create([
            'hikvision_employee_no' => '5001', 'full_name' => 'Rosa Ibarra', 'department' => 'Calidad',
            'position' => 'Inspectora', 'work_shift_id' => $shift->id, 'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return [
            'hikvision_employee_no' => '5001', 'full_name' => 'Rosa Ibarra', 'department' => 'Calidad',
            'position' => 'Inspectora', 'work_shift_id' => $this->employee->work_shift_id, 'is_active' => true,
            ...$extra,
        ];
    }

    private function userWithout(string $key): User
    {
        $profile = Profile::create(['name' => 'Capturista', 'is_admin' => false, 'is_active' => true]);
        $profile->permissions()->sync(Permission::where('key', 'like', 'empleados.%')->where('key', '!=', $key)->pluck('id'));

        return User::factory()->create(['profile_id' => $profile->id]);
    }

    public function test_blood_type_is_encrypted_audited_and_only_shown_with_permission(): void
    {
        $this->actingAs(User::factory()->create());
        $this->putJson("/employees/{$this->employee->id}", $this->payload(['blood_type' => 'o+']))->assertOk();

        $raw = DB::table('employees')->where('id', $this->employee->id)->value('blood_type');
        $this->assertNotSame('O+', $raw);
        $this->assertSame('O+', Crypt::decryptString($raw));

        // El cambio queda en la bitácora sin el valor
        $log = AuditLog::where('action', 'employee.updated')->latest('id')->firstOrFail();
        $this->assertSame('[protegido]', $log->new_values['blood_type']);

        // Al abrir la ficha se ve y queda registrada la consulta; la tabla solo sabe que existe
        $this->getJson("/employees/{$this->employee->id}")->assertOk()->assertJsonPath('employee.blood_type', 'O+');
        $this->assertTrue(AuditLog::where('action', 'employee.health_data_viewed')->exists());
        $this->getJson('/employees/list')->assertOk()
            ->assertJsonPath('employees.0.blood_type', null)
            ->assertJsonPath('employees.0.has_blood_type', true);
    }

    public function test_without_permission_blood_type_is_hidden_and_not_changed(): void
    {
        $this->employee->update(['blood_type' => 'A-']);
        $this->actingAs($this->userWithout('empleados.datos_salud'));

        $this->getJson("/employees/{$this->employee->id}")->assertOk()
            ->assertJsonPath('employee.blood_type', null)
            ->assertJsonPath('employee.health_masked', true)
            ->assertJsonPath('employee.has_blood_type', true);

        $this->putJson("/employees/{$this->employee->id}", $this->payload(['blood_type' => 'B+', 'position' => 'Supervisora']))->assertOk();

        $this->employee->refresh();
        $this->assertSame('A-', $this->employee->blood_type);
        $this->assertSame('Supervisora', $this->employee->position);
        $this->assertFalse(AuditLog::where('action', 'employee.health_data_viewed')->exists());
    }

    public function test_invalid_blood_type_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson("/employees/{$this->employee->id}", $this->payload(['blood_type' => 'Z+']))
            ->assertStatus(422)->assertJsonValidationErrors('blood_type');
    }

    public function test_photo_is_resized_stored_privately_and_served_with_permission(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->post("/employees/{$this->employee->id}/photo", [
            'photo' => UploadedFile::fake()->image('foto.png', 1600, 1200),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('success', true);

        $path = $this->employee->fresh()->photo_path;
        $this->assertStringStartsWith(Employee::PHOTO_DIR.'/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        Storage::disk(Employee::PHOTO_DISK)->assertExists($path);

        [$width, $height] = getimagesizefromstring(Storage::disk(Employee::PHOTO_DISK)->get($path));
        $this->assertSame([600, 450], [$width, $height]);

        $this->get($response->json('photo_url'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertTrue(AuditLog::where('action', 'employee.photo_updated')->exists());

        // El perfil Consulta la ve, pero no la cambia
        $this->actingAs(User::factory()->withProfile('Consulta')->create());
        $this->get("/employees/{$this->employee->id}/photo")->assertOk();
        $this->post("/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->image('otra.jpg')], ['Accept' => 'application/json'])
            ->assertForbidden();
    }

    public function test_non_image_files_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post("/employees/{$this->employee->id}/photo", [
            'photo' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertNull($this->employee->fresh()->photo_path);
    }

    public function test_replacing_and_removing_the_photo_deletes_old_files(): void
    {
        $this->actingAs(User::factory()->create());
        $disk = Storage::disk(Employee::PHOTO_DISK);

        $this->post("/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $first = $this->employee->fresh()->photo_path;
        $this->post("/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg')], ['Accept' => 'application/json'])->assertOk();
        $second = $this->employee->fresh()->photo_path;

        $disk->assertMissing($first);
        $disk->assertExists($second);

        $this->deleteJson("/employees/{$this->employee->id}/photo")->assertOk();
        $disk->assertMissing($second);
        $this->assertNull($this->employee->fresh()->photo_path);
        $this->get("/employees/{$this->employee->id}/photo")->assertNotFound();
    }

    public function test_deleting_an_employee_without_history_deletes_the_photo(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post("/employees/{$this->employee->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->employee->fresh()->photo_path;

        $this->deleteJson("/employees/{$this->employee->id}")->assertOk();

        Storage::disk(Employee::PHOTO_DISK)->assertMissing($path);
    }
}
