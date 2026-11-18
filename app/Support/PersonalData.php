<?php

namespace App\Support;

/**
 * Enmascarado e índices ciegos de RFC, CURP y NSS (auditoría SE-05, guía 4.8 y 4.21).
 *
 * - Los listados muestran el dato enmascarado; el completo solo se ve al editar al empleado.
 * - En la base van cifrados (cast "encrypted" de Laravel con APP_KEY); para buscar por
 *   RFC o CURP exactos se guarda además un HMAC del valor (índice ciego).
 */
class PersonalData
{
    public static function maskRfc(?string $rfc): ?string
    {
        return self::mask($rfc, 4, 3);
    }

    public static function maskCurp(?string $curp): ?string
    {
        return self::mask($curp, 4, 2);
    }

    public static function maskNss(?string $nss): ?string
    {
        return self::mask($nss, 0, 4);
    }

    /**
     * Índice ciego: HMAC-SHA256 del valor normalizado. Permite buscar coincidencias exactas
     * sin guardar el dato en claro. Cambia si cambia APP_KEY (igual que el cifrado).
     */
    public static function blindIndex(?string $value): ?string
    {
        $normalized = self::normalize($value);

        return $normalized === null ? null : hash_hmac('sha256', $normalized, (string) config('app.key'));
    }

    public static function normalize(?string $value): ?string
    {
        $clean = mb_strtoupper(trim((string) $value));

        return $clean === '' ? null : $clean;
    }

    private static function mask(?string $value, int $visibleStart, int $visibleEnd): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = mb_strlen($value);
        if ($length <= $visibleStart + $visibleEnd) {
            return str_repeat('•', $length);
        }

        return mb_substr($value, 0, $visibleStart)
            .str_repeat('•', $length - $visibleStart - $visibleEnd)
            .mb_substr($value, -$visibleEnd);
    }
}
