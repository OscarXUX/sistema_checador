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
        'entry_window_start',
        'entry_window_end',
        'exit_window_start',
        'exit_window_end',
        'repeat_threshold_minutes',
    ];

    /** Ventanas por omisión (minutos antes y después de la hora del turno) cuando no se configuran (P6). */
    public const DEFAULT_ENTRY_WINDOW = [60, 120];

    public const DEFAULT_EXIT_WINDOW = [60, 180];

    protected function casts(): array
    {
        return [
            'meal_duration_minutes' => 'integer',
            'tolerance_minutes' => 'integer',
            'max_weekly_hours' => 'integer',
            'repeat_threshold_minutes' => 'integer',
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

    public function hasCustomWindows(): bool
    {
        return $this->entry_window_start !== null && $this->entry_window_end !== null
            && $this->exit_window_start !== null && $this->exit_window_end !== null;
    }

    /**
     * Horas (HH:MM) de las ventanas de entrada y salida: las configuradas o las de omisión.
     *
     * @return array{entrada: array{0: string, 1: string}, salida: array{0: string, 1: string}}
     */
    public function windowTimes(): array
    {
        if ($this->hasCustomWindows()) {
            return [
                'entrada' => [substr((string) $this->entry_window_start, 0, 5), substr((string) $this->entry_window_end, 0, 5)],
                'salida' => [substr((string) $this->exit_window_start, 0, 5), substr((string) $this->exit_window_end, 0, 5)],
            ];
        }

        $entry = CarbonImmutable::parse('2000-01-01 '.$this->entry_time);
        $exit = CarbonImmutable::parse('2000-01-01 '.$this->exit_time);

        return [
            'entrada' => [$entry->subMinutes(self::DEFAULT_ENTRY_WINDOW[0])->format('H:i'), $entry->addMinutes(self::DEFAULT_ENTRY_WINDOW[1])->format('H:i')],
            'salida' => [$exit->subMinutes(self::DEFAULT_EXIT_WINDOW[0])->format('H:i'), $exit->addMinutes(self::DEFAULT_EXIT_WINDOW[1])->format('H:i')],
        ];
    }

    /**
     * Ventanas de entrada y salida como fechas reales para la jornada que inicia en $workDate
     * (anclada a la hora de entrada y a la de salida, también en turnos que cruzan la medianoche).
     * Si se enciman, se parten a la mitad para que una checada nunca caiga en las dos.
     *
     * @return array{entrada: array{0: CarbonImmutable, 1: CarbonImmutable}, salida: array{0: CarbonImmutable, 1: CarbonImmutable}}
     */
    public function classificationWindows(CarbonInterface $workDate): array
    {
        $date = CarbonImmutable::instance($workDate)->startOfDay();
        $scheduledEntry = CarbonImmutable::parse($date->toDateString().' '.$this->entry_time);
        $scheduledExit = CarbonImmutable::parse(($this->crossesMidnight() ? $date->addDay() : $date)->toDateString().' '.$this->exit_time);
        $lstHoras = $this->windowTimes();

        $entry = self::anchorWindow($lstHoras['entrada'], $scheduledEntry);
        $exit = self::anchorWindow($lstHoras['salida'], $scheduledExit);

        if ($entry[1]->greaterThanOrEqualTo($exit[0])) {
            $midpoint = $scheduledEntry->addSeconds(intdiv((int) $scheduledEntry->diffInSeconds($scheduledExit), 2));
            $entry[1] = $midpoint;
            $exit[0] = $midpoint->addSecond();
        }

        return ['entrada' => $entry, 'salida' => $exit];
    }

    /**
     * Convierte un rango de horas en fechas alrededor de la hora del turno: el inicio queda a menos de
     * 12 horas antes de $anchor y el fin después del inicio (si es menor, es del día siguiente).
     *
     * @param  array{0: string, 1: string}  $lstHoras
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function anchorWindow(array $lstHoras, CarbonImmutable $anchor): array
    {
        $start = CarbonImmutable::parse($anchor->toDateString().' '.$lstHoras[0]);
        if ($start->greaterThan($anchor)) {
            $start = $start->subDay();
        }
        if ($anchor->diffInMinutes($start, true) >= 720) {
            $start = $start->addDay();
        }

        $end = CarbonImmutable::parse($start->toDateString().' '.$lstHoras[1]);
        if ($end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start, $end->addSeconds(59)];
    }

    public function getScheduleLabelAttribute(): string
    {
        return substr((string) $this->entry_time, 0, 5).' - '.substr((string) $this->exit_time, 0, 5);
    }
}
