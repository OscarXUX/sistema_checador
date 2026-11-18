<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Escribe la bitácora de acciones (auditoría SE-09, guía 4.15).
 *
 * Guarda quién, qué, cuándo y desde dónde. RFC, CURP y NSS no se copian a la bitácora:
 * solo queda constancia de que cambiaron. Nunca se registran contraseñas ni plantillas de huella.
 */
class AuditLogger
{
    /**
     * Campos que se registran como "[protegido]" en lugar de su valor.
     */
    public const PROTECTED_FIELDS = ['rfc', 'curp', 'nss'];

    /**
     * Campos que nunca se registran.
     */
    public const EXCLUDED_FIELDS = ['password', 'remember_token', 'fingerData', 'created_at', 'updated_at'];

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(
        string $action,
        ?Model $subject,
        string $description,
        array $oldValues = [],
        array $newValues = [],
    ): ?AuditLog {
        try {
            // Petición web si hay una ruta en curso; en comandos de consola no la hay
            $request = request()->route() !== null ? request() : null;

            return AuditLog::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'auditable_type' => $subject !== null ? $subject->getMorphClass() : null,
                'auditable_id' => $subject?->getKey(),
                'description' => Str::limit($description, 250, '…'),
                'old_values' => $this->sanitize($oldValues) ?: null,
                'new_values' => $this->sanitize($newValues) ?: null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 250, '') : 'consola',
            ]);
        } catch (Throwable $e) {
            // La bitácora no debe tumbar la operación principal, pero la falla queda en el log
            Log::error('No se pudo escribir en la bitácora de acciones', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Valores antes y después solo de los campos que cambiaron.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(array $before, array $after): array
    {
        $old = [];
        $new = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;
            if ((string) json_encode($previous) !== (string) json_encode($value)) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }

        return [$old, $new];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array($key, self::EXCLUDED_FIELDS, true)) {
                continue;
            }

            $clean[$key] = in_array($key, self::PROTECTED_FIELDS, true) && $value !== null && $value !== ''
                ? '[protegido]'
                : $value;
        }

        return $clean;
    }
}
