<?php

namespace App\Models;

use Carbon\CarbonImmutable;
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
