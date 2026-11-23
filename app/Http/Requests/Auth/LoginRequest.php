<?php

namespace App\Http\Requests\Auth;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inicio de sesión con límite de intentos y mensaje genérico (auditoría SE-01, guía 4.2).
 */
class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const GENERIC_ERROR = 'Correo o contraseña incorrectos.';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Escribe tu correo.',
            'email.email' => 'Escribe un correo válido.',
            'password.required' => 'Escribe tu contraseña.',
        ];
    }

    /**
     * Valida las credenciales; solo entran cuentas activas.
     *
     * @throws ValidationException
     */
    public function authenticate(AuditLogger $audit): void
    {
        $this->ensureIsNotRateLimited($audit);

        $dataCredenciales = [
            'email' => $this->string('email')->lower()->trim()->toString(),
            'password' => $this->string('password')->toString(),
            'is_active' => true,
        ];

        if (! Auth::attempt($dataCredenciales)) {
            RateLimiter::hit($this->throttleKey(), 60);

            $audit->record('auth.login_failed', null, 'Intento fallido de inicio de sesión', [], [
                'email' => $dataCredenciales['email'],
            ]);

            // Mismo mensaje exista o no la cuenta, esté o no activa
            throw ValidationException::withMessages([
                'email' => self::GENERIC_ERROR,
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(AuditLogger $audit): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        $audit->record('auth.lockout', null, 'Bloqueo temporal por intentos fallidos', [], [
            'email' => $this->string('email')->lower()->trim()->toString(),
        ]);

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos. Intenta de nuevo en {$seconds} segundos.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate($this->string('email')->lower()->trim()->toString().'|'.$this->ip());
    }
}
