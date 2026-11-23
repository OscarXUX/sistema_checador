<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perfiles y permisos con la estructura del sistema de tickets (SE-02): perfil con su lista,
 * excepciones por persona (dar o negar), permiso desactivado y administrador con todo.
 */
class ProfilesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function permissionId(string $key): int
    {
        return (int) Permission::where('key', $key)->value('id');
    }

    private function makeEmployee(): Employee
    {
        $shift = WorkShift::create([
            'name' => 'Matutino', 'entry_time' => '09:00:00', 'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60, 'tolerance_minutes' => 10, 'max_weekly_hours' => 48,
        ]);

        return Employee::create([
            'hikvision_employee_no' => '7001',
            'full_name' => 'Sofía Herrera León',
            'rfc' => 'HELS940312MDF',
            'department' => 'Calidad',
            'position' => 'Inspectora',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);
    }

    public function test_admin_has_every_active_permission(): void
    {
        $admin = User::factory()->create();

        $this->assertTrue($admin->isAdmin());
        $this->assertEqualsCanonicalizing(Permission::pluck('key')->all(), $admin->effectivePermissions());
        $this->assertSame('dashboard', $admin->homeRouteName());
    }

    public function test_query_profile_can_read_but_not_change(): void
    {
        $user = User::factory()->withProfile('Consulta')->create();
        $employee = $this->makeEmployee();

        $this->actingAs($user);
        $this->getJson('/employees/list')->assertOk();
        $this->postJson('/employees', [])->assertForbidden()->assertJsonPath('message', 'No tienes permiso para hacer esta acción.');
        $this->deleteJson("/employees/{$employee->id}")->assertForbidden();
        $this->postJson('/device/sync')->assertForbidden();
        $this->withoutVite()->get('/administracion/usuarios')->assertForbidden();
    }

    public function test_denied_exception_wins_over_profile(): void
    {
        $user = User::factory()->withProfile('Consulta')->create();
        $user->permissionExceptions()->attach($this->permissionId('kardex.consultar'), ['is_denied' => true]);

        $this->assertFalse($user->fresh()->hasPermission('kardex.consultar'));
        $this->actingAs($user)->getJson('/reports/kardex/data')->assertForbidden();
    }

    public function test_granted_exception_adds_a_permission(): void
    {
        $user = User::factory()->withProfile('Consulta')->create();
        $user->permissionExceptions()->attach($this->permissionId('kardex.exportar'), ['is_denied' => false]);

        $this->assertTrue($user->fresh()->hasPermission('kardex.exportar'));
        $this->assertFalse($user->fresh()->hasPermission('empleados.agregar'));
    }

    public function test_disabled_permission_is_removed_even_from_admin(): void
    {
        Permission::where('key', 'terminal.sincronizar')->update(['is_active' => false]);
        $admin = User::factory()->create();

        $this->assertFalse($admin->hasPermission('terminal.sincronizar'));
        $this->actingAs($admin)->postJson('/device/sync')->assertForbidden();
    }

    public function test_user_without_permissions_lands_on_notice_page(): void
    {
        $profile = Profile::create(['name' => 'Vacío', 'is_admin' => false, 'is_active' => true]);
        $user = User::factory()->create(['profile_id' => $profile->id]);

        $this->assertSame('home', $user->homeRouteName());
        $this->actingAs($user)->withoutVite()->get('/inicio')->assertOk()->assertSee('Sin permisos');
        $this->get('/')->assertForbidden();
    }

    public function test_inactive_profile_has_no_permissions(): void
    {
        $user = User::factory()->withProfile('Consulta')->create();
        Profile::where('name', 'Consulta')->update(['is_active' => false]);

        $this->assertSame([], $user->fresh()->effectivePermissions());
    }

    public function test_editing_without_personal_data_permission_keeps_rfc(): void
    {
        $profile = Profile::create(['name' => 'Capturista', 'is_admin' => false, 'is_active' => true]);
        $profile->permissions()->sync(Permission::whereIn('key', ['empleados.consultar', 'empleados.actualizar'])->pluck('id'));
        $user = User::factory()->create(['profile_id' => $profile->id]);
        $employee = $this->makeEmployee();

        $this->actingAs($user)->getJson("/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('employee.personal_ids_masked', true);

        $this->putJson("/employees/{$employee->id}", [
            'hikvision_employee_no' => '7001',
            'full_name' => 'Sofía Herrera Actualizada',
            'rfc' => 'XAXX010101000',
            'department' => 'Calidad',
            'position' => 'Supervisora',
            'work_shift_id' => $employee->work_shift_id,
            'is_active' => true,
        ])->assertOk();

        $employee->refresh();
        $this->assertSame('Supervisora', $employee->position);
        $this->assertSame('HELS940312MDF', $employee->rfc);
    }

    public function test_admin_screens_render(): void
    {
        $this->actingAs(User::factory()->create())->withoutVite();

        $this->get('/administracion/usuarios')->assertOk()->assertSee('Nueva cuenta');
        $this->get('/administracion/perfiles')->assertOk()->assertSee('Consulta');
        $this->get('/administracion/permisos')->assertOk()->assertSee('kardex.exportar');
        $this->get('/administracion/perfiles/'.Profile::where('name', 'Consulta')->value('id'))->assertOk();
    }

    public function test_admin_creates_profile_and_assigns_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/administracion/perfiles', ['name' => 'Supervisor'])->assertRedirect();
        $profile = Profile::where('name', 'Supervisor')->firstOrFail();

        $this->put("/administracion/perfiles/{$profile->id}", [
            'name' => 'Supervisor',
            'is_active' => '1',
            'permissions' => [$this->permissionId('kardex.consultar'), $this->permissionId('kardex.exportar')],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing(['kardex.consultar', 'kardex.exportar'], $profile->permissions()->pluck('key')->all());
        $this->assertTrue(AuditLog::where('action', 'profile.updated')->exists());
    }

    public function test_admin_creates_user_and_sets_exceptions(): void
    {
        $this->actingAs(User::factory()->create());
        $consultaId = Profile::where('name', 'Consulta')->value('id');

        $this->post('/administracion/usuarios', [
            'name' => 'Laura Pérez',
            'email' => 'Laura@Example.com',
            'profile_id' => $consultaId,
            'password' => 'una frase larga de prueba 2026',
            'password_confirmation' => 'una frase larga de prueba 2026',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user = User::where('email', 'laura@example.com')->firstOrFail();

        $this->put("/administracion/usuarios/{$user->id}/permisos", [
            'exceptions' => [
                $this->permissionId('kardex.exportar') => 'grant',
                $this->permissionId('dashboard.consultar') => 'deny',
                $this->permissionId('turnos.consultar') => 'profile',
            ],
        ])->assertRedirect();

        $user = $user->fresh();
        $this->assertTrue($user->hasPermission('kardex.exportar'));
        $this->assertFalse($user->hasPermission('dashboard.consultar'));
        $this->assertTrue($user->hasPermission('turnos.consultar'));
        $this->assertSame('employees.index', $user->homeRouteName());
        $this->assertTrue(AuditLog::where('action', 'user.permissions_updated')->exists());
    }

    public function test_last_admin_cannot_lose_admin_profile_or_be_deactivated(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $consultaId = Profile::where('name', 'Consulta')->value('id');

        $this->put("/administracion/usuarios/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'profile_id' => $consultaId, 'is_active' => '1',
        ])->assertSessionHasErrors('profile_id');

        $this->put("/administracion/usuarios/{$admin->id}", [
            'name' => $admin->name, 'email' => $admin->email, 'profile_id' => $admin->profile_id, 'is_active' => '0',
        ])->assertSessionHasErrors('profile_id');

        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_profile_cannot_be_deleted_or_deactivated(): void
    {
        $this->actingAs(User::factory()->create());
        $adminProfile = Profile::where('is_admin', true)->firstOrFail();

        $this->delete("/administracion/perfiles/{$adminProfile->id}")->assertSessionHasErrors('profile');
        $this->put("/administracion/perfiles/{$adminProfile->id}", ['name' => $adminProfile->name, 'is_active' => '0'])
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($adminProfile->fresh()->is_active);
    }

    public function test_profile_with_users_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        User::factory()->withProfile('Consulta')->create();
        $consulta = Profile::where('name', 'Consulta')->firstOrFail();

        $this->delete("/administracion/perfiles/{$consulta->id}")->assertSessionHasErrors('profile');
        $this->assertNotNull($consulta->fresh());
    }

    public function test_permission_catalog_update_is_logged(): void
    {
        $this->actingAs(User::factory()->create());
        $id = $this->permissionId('kardex.exportar');

        $this->put("/administracion/permisos/{$id}", ['description' => 'Descargar Excel y PDF', 'is_active' => '0'])->assertRedirect();

        $permission = Permission::find($id);
        $this->assertSame('Descargar Excel y PDF', $permission->description);
        $this->assertFalse($permission->is_active);
        $this->assertTrue(AuditLog::where('action', 'permission.updated')->exists());
    }
}
