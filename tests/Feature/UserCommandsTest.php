<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Comandos para administrar cuentas sin registro público (auditoría SE-01).
 */
class UserCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'una frase larga de prueba 2026';

    public function test_create_user_command(): void
    {
        $this->artisan('usuarios:crear')
            ->expectsQuestion('Nombre completo', 'Danny Oblea')
            ->expectsQuestion('Correo', 'Danny@Example.com')
            ->expectsQuestion('Contraseña (mínimo 15 caracteres)', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->expectsOutput('Cuenta creada: danny@example.com (perfil Administrador)')
            ->assertSuccessful();

        $user = User::where('email', 'danny@example.com')->firstOrFail();
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'auditable_id' => $user->id]);
    }

    public function test_create_user_rejects_short_or_weak_passwords(): void
    {
        $this->artisan('usuarios:crear')
            ->expectsQuestion('Nombre completo', 'Danny Oblea')
            ->expectsQuestion('Correo', 'danny@example.com')
            ->expectsQuestion('Contraseña (mínimo 15 caracteres)', 'corta')
            ->expectsQuestion('Confirma la contraseña', 'corta')
            ->expectsOutputToContain('al menos 15 caracteres')
            ->assertFailed();

        $this->artisan('usuarios:crear')
            ->expectsQuestion('Nombre completo', 'Danny Oblea')
            ->expectsQuestion('Correo', 'danny@example.com')
            ->expectsQuestion('Contraseña (mínimo 15 caracteres)', 'mi checador de asistencia')
            ->expectsQuestion('Confirma la contraseña', 'mi checador de asistencia')
            ->expectsOutputToContain('nombre del sistema')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_reset_password_closes_open_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['email' => 'danny@example.com']);

        DB::table('sessions')->insert([
            'id' => 'sesion-de-prueba',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->artisan('usuarios:restablecer', ['email' => 'danny@example.com'])
            ->expectsQuestion('Nueva contraseña (mínimo 15 caracteres)', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'auditable_id' => $user->id]);
    }

    public function test_deactivate_and_activate_user(): void
    {
        $user = User::factory()->create(['email' => 'danny@example.com']);

        $this->artisan('usuarios:estado', ['email' => 'danny@example.com', 'estado' => 'inactivo'])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_active);

        $this->artisan('usuarios:estado', ['email' => 'danny@example.com', 'estado' => 'activo'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_active);

        $this->artisan('usuarios:estado', ['email' => 'danny@example.com', 'estado' => 'otro'])->assertFailed();
    }
}
