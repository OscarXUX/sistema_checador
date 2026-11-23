<?php

namespace App\Services;

use App\Models\AttendanceEventType;
use App\Models\DailySummary;
use Illuminate\Support\Collection;

/**
 * Asigna el tipo de evento del catálogo (nota 4) a la entrada, la salida y la comida de un resumen
 * diario según sus minutos. Si se edita el catálogo, reclassifyAll() lo vuelve a aplicar sin
 * recalcular checadas.
 */
class EventTypeClassifier
{
    /** @var Collection<string, Collection<int, AttendanceEventType>>|null */
    private ?Collection $lstPorTipo = null;

    public function forget(): void
    {
        $this->lstPorTipo = null;
    }

    public function match(string $appliesTo, int $minutes): ?AttendanceEventType
    {
        $this->lstPorTipo ??= AttendanceEventType::active()->orderBy('sort_order')->orderBy('id')->get()->groupBy('applies_to');

        return ($this->lstPorTipo->get($appliesTo) ?? collect())
            ->first(fn (AttendanceEventType $type): bool => $type->contains($minutes));
    }

    /**
     * @return array{entry_event_type_id: ?int, exit_event_type_id: ?int, meal_event_type_id: ?int}
     */
    public function idsFor(bool $hasEntry, int $lateMinutes, bool $hasExit, int $earlyExitMinutes, bool $hasMeal, int $mealExceededMinutes): array
    {
        return [
            'entry_event_type_id' => $hasEntry ? $this->match('entrada', $lateMinutes)?->id : null,
            'exit_event_type_id' => $hasExit ? $this->match('salida', $earlyExitMinutes)?->id : null,
            'meal_event_type_id' => $hasMeal ? $this->match('comida', $mealExceededMinutes)?->id : null,
        ];
    }

    /**
     * Vuelve a asignar los tipos de evento a todos los resúmenes (después de editar el catálogo).
     */
    public function reclassifyAll(): int
    {
        $this->forget();
        $count = 0;

        DailySummary::query()
            ->where(fn ($q) => $q->whereNotNull('first_check_in')->orWhereNotNull('last_check_out')->orWhereNotNull('break_in'))
            ->chunkById(500, function (Collection $lstResumenes) use (&$count): void {
                foreach ($lstResumenes as $summary) {
                    $ids = $this->idsFor(
                        $summary->first_check_in !== null,
                        (int) $summary->late_minutes,
                        $summary->last_check_out !== null,
                        (int) $summary->early_exit_minutes,
                        $summary->break_in !== null,
                        (int) $summary->meal_exceeded_minutes,
                    );
                    $summary->forceFill($ids);
                    if ($summary->isDirty()) {
                        $summary->saveQuietly();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
