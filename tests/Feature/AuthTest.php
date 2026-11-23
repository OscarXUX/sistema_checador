<?php

namespace Tests\Feature;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Inicio de sesión (auditoría SE-01) y su registro en la bitácora (SE-09).
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_PASSWORD = 'una frase larga de prueba 2026';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function makeUser(array $attributes = []): User
    {
        return User::factory()->create([
            'email' => 'danny@example.com',
            'password' => self::VALID_PASSWORD,
            ...$attributes,
        ]);
    }

    public function test_guest_is_redirected_to_login_from_pages(): void
    {
        foreach (['/', '/employees', '/reports/kardex', '/shifts'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
    }

    public function test_guest_gets_401_on_json_endpoints(): void
    {
        $this->getJson('/employees/list')->assertUnauthorized();
        $this->getJson('/dashboard/data')->assertUnauthorized();
        $this->postJson('/device/sync')->assertUnauthorized();
    }

    public function test_login_page_renders_without_registration_link(): void
    {
        $response = $this->get('/login')
            ->assertOk()
            ->assertSee('Iniciar sesión')
            ->assertSee('id="btnVerContrasena"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertSee('aria-label="Mostrar contraseña"', false)
            ->assertDontSee('Registrarse');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $match);
        $this->assertNotEmpty($match[1] ?? '');
        $response->assertSee('<script nonce="'.$match[1].'">', false);

        $this->get('/register')->assertNotFound();
    }

    public function test_session_expired_notice_is_shown(): void
    {
        $this->get('/login?expirada=1')->assertSee('Tu sesión expiró');
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = $this->makeUser();

        $this->post('/login', ['email' => 'DANNY@example.com ', 'password' => self::VALID_PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_wrong_password_and_unknown_email_get_the_same_message(): void
    {
        $this->makeUser();

        $this->from('/login')->post('/login', ['email' => 'danny@example.com', 'password' => 'incorrecta incorrecta'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => LoginRequest::GENERIC_ERROR]);

        $this->from('/login')->post('/login', ['email' => 'nadie@example.com', 'password' => 'incorrecta incorrecta'])
            ->assertSessionHasErrors(['email' => LoginRequest::GENERIC_ERROR]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(['is_active' => false]);

        $this->post('/login', ['email' => 'danny@example.com', 'password' => self::VALID_PASSWORD])
            ->assertSessionHasErrors(['email' => LoginRequest::GENERIC_ERROR]);

        $this->assertGuest();
    }

    public function test_login_is_locked_after_five_failed_attempts(): void
    {
        $this->makeUser();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->post('/login', ['email' => 'danny@example.com', 'password' => 'incorrecta incorrecta']);
        }

        // Ni con la contraseña correcta entra mientras dure el bloqueo
        $response = $this->post('/login', ['email' => 'danny@example.com', 'password' => self::VALID_PASSWORD]);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Demasiados intentos', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_login_logout_and_failures_are_audited(): void
    {
        $user = $this->makeUser();

        $this->post('/login', ['email' => 'danny@example.com', 'password' => 'incorrecta incorrecta']);
        $this->post('/login', ['email' => 'danny@example.com', 'password' => self::VALID_PASSWORD]);
        $this->post('/logout');

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed', 'user_id' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'user_id' => $user->id]);

        $failed = AuditLog::where('action', 'auth.login_failed')->firstOrFail();
        $this->assertSame('danny@example.com', $failed->new_values['email']);
        $this->assertStringNotContainsString('incorrecta', (string) json_encode($failed->toArray()));
    }
}
