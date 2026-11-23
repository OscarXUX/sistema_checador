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
    public const PROTECTED_FIELDS = ['rfc', 'curp', 'nss', 'blood_type'];

    /**
     * Campos que nunca se registran.
     */
    public const EXCLUDED_FIELDS = [
        'password',
        'remember_token',
        'fingerData',
        'pin',
        'pin_confirmation',
        'card_password',
        'cardPasswd',
        'created_at',
        'updated_at',
    ];

    /**
     * @param  array<string, mixed>  $dataValoresAnteriores
     * @param  array<string, mixed>  $dataValoresNuevos
     */
    public function record(
        string $action,
        ?Model $subject,
        string $description,
        array $dataValoresAnteriores = [],
        array $dataValoresNuevos = [],
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
                'old_values' => $this->sanitize($dataValoresAnteriores) ?: null,
                'new_values' => $this->sanitize($dataValoresNuevos) ?: null,
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
     * @param  array<string, mixed>  $dataAntes
     * @param  array<string, mixed>  $dataDespues
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(array $dataAntes, array $dataDespues): array
    {
        $dataAnterior = [];
        $dataNuevo = [];

        foreach ($dataDespues as $key => $value) {
            $previous = $dataAntes[$key] ?? null;
            if ((string) json_encode($previous) !== (string) json_encode($value)) {
                $dataAnterior[$key] = $previous;
                $dataNuevo[$key] = $value;
            }
        }

        return [$dataAnterior, $dataNuevo];
    }

    /**
     * @param  array<string, mixed>  $dataValores
     * @return array<string, mixed>
     */
    private function sanitize(array $dataValores): array
    {
        $dataLimpio = [];

        foreach ($dataValores as $key => $value) {
            if (in_array($key, self::EXCLUDED_FIELDS, true)) {
                continue;
            }

            $dataLimpio[$key] = in_array($key, self::PROTECTED_FIELDS, true) && $value !== null && $value !== ''
                ? '[protegido]'
                : $value;
        }

        return $dataLimpio;
    }
}
