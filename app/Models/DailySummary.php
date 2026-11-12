<?php

namespace App\Models;

use App\Casts\DateOnlyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kardex diario calculado con tiempos laborados, comidas, retardos y horas extra.
 */
class DailySummary extends Model
{
    use HasFactory;

    public const STATUS_ON_TIME = 'on_time';

    public const STATUS_LATE = 'late';

    public const STATUS_IN_MEAL = 'in_meal';

    public const STATUS_MEAL_EXCEEDED = 'meal_exceeded';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_ABSENT = 'absent';

    public const STATUSES = [
        self::STATUS_ON_TIME,
        self::STATUS_LATE,
        self::STATUS_IN_MEAL,
        self::STATUS_MEAL_EXCEEDED,
        self::STATUS_INCOMPLETE,
        self::STATUS_ABSENT,
    ];

    protected $fillable = [
        'employee_id',
        'work_date',
        'first_check_in',
        'break_out',
        'break_in',
        'last_check_out',
        'worked_minutes',
        'meal_minutes',
        'late_minutes',
        'overtime_minutes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_date' => DateOnlyCast::class,
            'first_check_in' => 'datetime',
            'break_out' => 'datetime',
            'break_in' => 'datetime',
            'last_check_out' => 'datetime',
            'worked_minutes' => 'integer',
            'meal_minutes' => 'integer',
            'late_minutes' => 'integer',
            'overtime_minutes' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_ON_TIME => 'A Tiempo',
            self::STATUS_LATE => 'Retardo',
            self::STATUS_IN_MEAL => 'En Comida',
            self::STATUS_MEAL_EXCEEDED => 'Comida Excedida',
            self::STATUS_INCOMPLETE => 'Incompleto',
            self::STATUS_ABSENT => 'Falta',
            default => ucfirst($status),
        };
    }

    public static function formatMinutes(int $minutes): string
    {
        $hours = intdiv(max(0, $minutes), 60);
        $mins = max(0, $minutes) % 60;

        return sprintf('%02d:%02d', $hours, $mins);
    }
}
