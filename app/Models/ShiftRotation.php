<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de turno rotativo con pasos ordenados (ej. 1->2->3, 3->2->1) y días por ciclo.
 */
class ShiftRotation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'cycle_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cycle_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ShiftRotationStep::class)->orderBy('step_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftRotationAssignment::class);
    }

    public function resolveShiftForDate(
        CarbonInterface $date,
        CarbonInterface $startsOn,
        int $startStepOrder = 1
    ): ?WorkShift {
        $steps = $this->relationLoaded('steps')
            ? $this->steps->sortBy('step_order')->values()
            : $this->steps()->with('workShift')->get()->values();

        if ($steps->isEmpty()) {
            return null;
        }

        $targetDay = CarbonImmutable::instance($date)->startOfDay();
        $startDay = CarbonImmutable::instance($startsOn)->startOfDay();

        if ($targetDay->lessThan($startDay)) {
            return null;
        }

        $daysElapsed = (int) $startDay->diffInDays($targetDay);
        $cycleDays = max(1, (int) $this->cycle_days);
        $cyclesElapsed = intdiv($daysElapsed, $cycleDays);
        $offset = max(0, $startStepOrder - 1);
        $stepIndex = ($offset + $cyclesElapsed) % $steps->count();

        /** @var ShiftRotationStep|null $step */
        $step = $steps->get($stepIndex);

        return $step?->workShift;
    }

    public function sequenceLabel(): string
    {
        $steps = $this->relationLoaded('steps')
            ? $this->steps->sortBy('step_order')
            : $this->steps()->with('workShift')->get();

        return $steps
            ->map(fn (ShiftRotationStep $s): string => $s->workShift?->name ?? "Turno #{$s->work_shift_id}")
            ->implode(' → ');
    }
}
