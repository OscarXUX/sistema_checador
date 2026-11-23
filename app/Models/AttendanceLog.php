<?php

namespace App\Models;

use App\Services\AttendanceCalculatorService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bitácora cruda e inmutable de checadas biométricas para auditoría fiscal.
 */
class AttendanceLog extends Model
{
    use HasFactory;

    public const EVENT_CHECK_IN = 'check_in';

    public const EVENT_BREAK_OUT = 'break_out';

    public const EVENT_BREAK_IN = 'break_in';

    public const EVENT_CHECK_OUT = 'check_out';

    public const EVENT_UNCLASSIFIED = 'unclassified';

    public const EVENT_TYPES = [
        self::EVENT_CHECK_IN,
        self::EVENT_BREAK_OUT,
        self::EVENT_BREAK_IN,
        self::EVENT_CHECK_OUT,
        self::EVENT_UNCLASSIFIED,
    ];

    protected $fillable = [
        'employee_id',
        'device_ip',
        'punched_at',
        'event_type',
        'verification_mode',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'punched_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (AttendanceLog $log): void {
            if ($log->employee !== null) {
                app(AttendanceCalculatorService::class)
                    ->recalculateForPunch($log->employee, $log->punched_at);
            }
        });

        static::updating(function (): void {
            throw new LogicException('La bitácora de asistencia es inmutable para auditoría fiscal.');
        });
    }

    /**
     * Corrección manual (la checada sigue igual; la corrección dice cómo debe contar).
     */
    public function correction(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AttendanceLogCorrection::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function eventTypeLabel(string $eventType): string
    {
        return match ($eventType) {
            self::EVENT_CHECK_IN => 'Entrada',
            self::EVENT_BREAK_OUT => 'Salida a Comida',
            self::EVENT_BREAK_IN => 'Regreso de Comida',
            self::EVENT_CHECK_OUT => 'Salida',
            default => 'Sin Clasificar',
        };
    }

    public static function verificationModeLabel(string $mode): string
    {
        return match ($mode) {
            'fingerprint' => 'Huella Dactilar',
            'card' => 'Tarjeta RFID',
            'pin' => 'PIN / Contraseña',
            'face' => 'Reconocimiento Facial',
            default => ucfirst($mode),
        };
    }
}
