<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Representa un turno laboral y sus reglas de cumplimiento fiscal y tolerancias.
 */
class WorkShift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'entry_time',
        'exit_time',
        'meal_duration_minutes',
        'tolerance_minutes',
        'max_weekly_hours',
    ];

    protected function casts(): array
    {
        return [
            'meal_duration_minutes' => 'integer',
            'tolerance_minutes' => 'integer',
            'max_weekly_hours' => 'integer',
        ];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function crossesMidnight(): bool
    {
        $entry = CarbonImmutable::parse('2000-01-01 '.$this->entry_time);
        $exit = CarbonImmutable::parse('2000-01-01 '.$this->exit_time);

        return $exit->lessThanOrEqualTo($entry);
    }

    /**
     * Ventana de checadas [inicio, fin] de la jornada iniciada en $workDate.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function shiftWindowForDate(CarbonInterface $workDate): array
    {
        $date = CarbonImmutable::instance($workDate)->startOfDay();

        if (! $this->crossesMidnight()) {
            return [$date->startOfDay(), $date->endOfDay()];
        }

        $entry = CarbonImmutable::parse($date->toDateString().' '.$this->entry_time);
        $exit = CarbonImmutable::parse($date->addDay()->toDateString().' '.$this->exit_time);
        $grossSeconds = $entry->diffInSeconds($exit);
        $offGapSeconds = max(0, 86400 - $grossSeconds);
        $beforeMargin = intdiv($offGapSeconds, 2);
        $afterMargin = $offGapSeconds - $beforeMargin;

        return [
            $entry->subSeconds($beforeMargin),
            $exit->addSeconds($afterMargin)->subSecond(),
        ];
    }

    /**
     * Determina la fecha de inicio de jornada a la que pertenece una checada.
     */
    public function resolveWorkDateForPunch(CarbonInterface $punchedAt): CarbonImmutable
    {
        $instant = CarbonImmutable::instance($punchedAt);

        if (! $this->crossesMidnight()) {
            return $instant->startOfDay();
        }

        foreach ([-1, 0, 1] as $offsetDays) {
            $candidateDate = $instant->startOfDay()->addDays($offsetDays);
            [$windowStart, $windowEnd] = $this->shiftWindowForDate($candidateDate);

            if ($instant->betweenIncluded($windowStart, $windowEnd)) {
                return $candidateDate;
            }
        }

        return $instant->startOfDay();
    }

    /**
     * Calcula los minutos netos de jornada diaria ordinaria (descontando comida).
     */
    public function ordinaryDailyMinutes(): int
    {
        $entry = CarbonImmutable::parse('2000-01-01 '.$this->entry_time);
        $exit = CarbonImmutable::parse('2000-01-01 '.$this->exit_time);

        if ($exit->lessThanOrEqualTo($entry)) {
            $exit = $exit->addDay();
        }

        $grossMinutes = $entry->diffInMinutes($exit);

        return max(0, $grossMinutes - $this->meal_duration_minutes);
    }

    public function getScheduleLabelAttribute(): string
    {
        return substr((string) $this->entry_time, 0, 5).' - '.substr((string) $this->exit_time, 0, 5);
    }
}
