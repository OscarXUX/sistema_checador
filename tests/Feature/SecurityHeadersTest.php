<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cabeceras de seguridad (auditoría SE-08, guía 4.10).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_basic_security_headers_are_present(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $response->assertHeaderMissing('X-Powered-By');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_csp_only_allows_scripts_with_the_page_nonce(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/shifts')->assertOk();
        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9+\/=]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $match);

        // El bloque <script> de la página lleva el mismo nonce
        $response->assertSee('<script nonce="'.$match[1].'">', false);

        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_private_pages_are_not_cached(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertStringContainsString('no-store', (string) $this->get('/shifts')->headers->get('Cache-Control'));
    }

    public function test_hsts_is_sent_over_https(): void
    {
        $this->get('https://localhost/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }
}
