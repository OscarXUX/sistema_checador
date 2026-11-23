<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paso ordenado dentro de un patrón de rotación de turnos.
 */
class ShiftRotationStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'shift_rotation_id',
        'work_shift_id',
        'step_order',
    ];

    protected function casts(): array
    {
        return [
            'shift_rotation_id' => 'integer',
            'work_shift_id' => 'integer',
            'step_order' => 'integer',
        ];
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }
}
