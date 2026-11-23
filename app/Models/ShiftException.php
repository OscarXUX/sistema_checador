<?php

namespace App\Models;

use App\Casts\DateOnlyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Excepción puntual de turno para un empleado en un rango de fechas (máxima prioridad).
 */
class ShiftException extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'work_shift_id',
        'starts_on',
        'ends_on',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'work_shift_id' => 'integer',
            'starts_on' => DateOnlyCast::class,
            'ends_on' => DateOnlyCast::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }
}
