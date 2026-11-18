<?php

namespace App\Support;

use Closure;

/**
 * Política de contraseñas según NIST 800-63B rev. 4 (guía de seguridad 4.2, auditoría SE-01).
 *
 * La contraseña es el único factor, así que el mínimo es 15. No se exigen mayúsculas,
 * números ni símbolos, y se acepta cualquier carácter (también espacios).
 */
class PasswordPolicy
{
    public const MIN_LENGTH = 15;

    public const MAX_LENGTH = 255;

    /**
     * Palabras que no puede contener la contraseña (nombre del sistema y del aparato).
     */
    private const BLOCKED_WORDS = ['checador', 'hikvision', 'password', 'contraseña', '123456789'];

    /**
     * @return array<int, mixed>
     */
    public static function rules(?string $email = null): array
    {
        return [
            'required',
            'string',
            'min:'.self::MIN_LENGTH,
            'max:'.self::MAX_LENGTH,
            function (string $attribute, mixed $value, Closure $fail) use ($email): void {
                $lower = mb_strtolower((string) $value);

                foreach (self::BLOCKED_WORDS as $word) {
                    if (str_contains($lower, $word)) {
                        $fail('La contraseña no puede contener el nombre del sistema ni palabras comunes.');

                        return;
                    }
                }

                $localPart = $email !== null ? mb_strtolower((string) strstr($email, '@', true)) : '';
                if ($localPart !== '' && mb_strlen($localPart) >= 3 && str_contains($lower, $localPart)) {
                    $fail('La contraseña no puede contener tu usuario de correo.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.required' => 'Escribe la contraseña.',
            'password.min' => 'La contraseña debe tener al menos '.self::MIN_LENGTH.' caracteres. Puede ser una frase.',
            'password.max' => 'La contraseña no puede pasar de '.self::MAX_LENGTH.' caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ];
    }
}
