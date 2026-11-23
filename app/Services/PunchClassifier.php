<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Clasifica las checadas de una jornada por hora (nota 6 de Oscar: "que sea por hora y no por orden")
 * y detecta las repetidas o fuera de orden (nota 3). La bitácora no se toca: las checadas que no
 * cuentan solo quedan anotadas como incidencia en el resumen diario.
 *
 * Reglas, en este orden:
 * 1. Una checada a menos de N minutos de la anterior (N = repeat_threshold_minutes del turno) es repetida y se ignora.
 * 2. Teclas de comida del reloj (break_out / break_in) cuentan como comida.
 * 3. Dentro de la ventana de entrada es entrada; dentro de la de salida, salida.
 * 4. Entre las dos ventanas: la tecla de salida cuenta como salida; si aún no hay entrada es una
 *    llegada tarde (entrada); si no, es comida.
 * 5. Antes de la ventana de entrada o después de la de salida: incidencia "fuera de ventana" y se usa
 *    la tecla o, sin tecla, entrada (antes) o salida (después).
 * 6. Si la jornada ya cerró, no hay salida y las checadas de comida son impares, la última es la salida
 *    (salida anticipada).
 */
class PunchClassifier
{
    public const INCIDENT_REPEATED = 'checada_repetida';

    public const INCIDENT_EXTRA_ENTRY = 'entrada_repetida';

    public const INCIDENT_EXTRA_EXIT = 'salida_repetida';

    public const INCIDENT_EXIT_WITHOUT_ENTRY = 'salida_sin_entrada';

    public const INCIDENT_OUT_OF_WINDOW = 'fuera_de_ventana';

    public const INCIDENT_EXTRA_MEAL = 'comida_extra';

    public const INCIDENT_LABELS = [
        self::INCIDENT_REPEATED => 'Checada repetida',
        self::INCIDENT_EXTRA_ENTRY => 'Entrada repetida',
        self::INCIDENT_EXTRA_EXIT => 'Salida repetida',
        self::INCIDENT_EXIT_WITHOUT_ENTRY => 'Salida sin entrada',
        self::INCIDENT_OUT_OF_WINDOW => 'Fuera de ventana de horario',
        self::INCIDENT_EXTRA_MEAL => 'Checada de comida de más',
    ];

    /**
     * @param  Collection<int, AttendanceLog>  $logs  ordenadas por hora
     * @return array{check_in: ?CarbonImmutable, break_out: ?CarbonImmutable, break_in: ?CarbonImmutable, check_out: ?CarbonImmutable, incidents: list<array{code: string, at: string}>}
     */
    public function classify(Collection $logs, WorkShift $shift, CarbonImmutable $workDate, bool $isActiveShiftWindow): array
    {
        $incidents = [];
        $note = function (string $code, CarbonImmutable $at) use (&$incidents): void {
            $incidents[] = ['code' => $code, 'at' => $at->format('Y-m-d H:i:s')];
        };

        // 1. Repetidas
        $threshold = max(0, (int) ($shift->repeat_threshold_minutes ?? 3)) * 60;
        $lstKept = [];
        $previous = null;
        foreach ($logs as $log) {
            $at = CarbonImmutable::instance($log->punched_at);
            if ($previous !== null && $threshold > 0 && $previous->diffInSeconds($at, true) < $threshold) {
                $note(self::INCIDENT_REPEATED, $at);

                continue;
            }
            $previous = $at;
            $lstKept[] = [$at, (string) $log->event_type];
        }

        // 2 a 5. Clasificación por ventana
        $windows = $shift->classificationWindows($workDate);
        [$entryStart, $entryEnd] = $windows['entrada'];
        [$exitStart, $exitEnd] = $windows['salida'];
        $lstEntradas = [];
        $lstSalidas = [];
        $lstComidas = [];

        foreach ($lstKept as [$at, $key]) {
            if ($key === AttendanceLog::EVENT_BREAK_OUT || $key === AttendanceLog::EVENT_BREAK_IN) {
                $lstComidas[] = $at;
            } elseif ($at->betweenIncluded($entryStart, $entryEnd)) {
                $lstEntradas[] = $at;
            } elseif ($at->betweenIncluded($exitStart, $exitEnd)) {
                $lstSalidas[] = $at;
            } elseif ($at->greaterThan($entryEnd) && $at->lessThan($exitStart)) {
                if ($key === AttendanceLog::EVENT_CHECK_OUT) {
                    $lstSalidas[] = $at;
                } elseif ($lstEntradas === [] && $lstComidas === []) {
                    $lstEntradas[] = $at;
                } else {
                    $lstComidas[] = $at;
                }
            } elseif ($at->lessThan($entryStart)) {
                $note(self::INCIDENT_OUT_OF_WINDOW, $at);
                if ($key === AttendanceLog::EVENT_CHECK_OUT) {
                    $lstSalidas[] = $at;
                } else {
                    $lstEntradas[] = $at;
                }
            } else {
                $note(self::INCIDENT_OUT_OF_WINDOW, $at);
                if ($key === AttendanceLog::EVENT_CHECK_IN && $lstEntradas === []) {
                    $lstEntradas[] = $at;
                } else {
                    $lstSalidas[] = $at;
                }
            }
        }

        // Entrada: la primera; las demás son repetidas
        $checkIn = $lstEntradas[0] ?? null;
        foreach (array_slice($lstEntradas, 1) as $at) {
            $note(self::INCIDENT_EXTRA_ENTRY, $at);
        }

        // Salida: la última; las anteriores son repetidas
        $checkOut = $lstSalidas === [] ? null : $lstSalidas[count($lstSalidas) - 1];
        foreach (array_slice($lstSalidas, 0, -1) as $at) {
            $note(self::INCIDENT_EXTRA_EXIT, $at);
        }

        // 6. Jornada cerrada sin salida y comida impar: la última checada fue la salida
        if ($checkOut === null && ! $isActiveShiftWindow && count($lstComidas) % 2 === 1) {
            $checkOut = array_pop($lstComidas);
        }

        sort($lstComidas);
        $breakOut = $lstComidas[0] ?? null;
        $breakIn = count($lstComidas) >= 2 ? $lstComidas[count($lstComidas) - 1] : null;
        foreach (array_slice($lstComidas, 1, max(0, count($lstComidas) - 2)) as $at) {
            $note(self::INCIDENT_EXTRA_MEAL, $at);
        }

        if ($checkOut !== null && $checkIn === null) {
            $note(self::INCIDENT_EXIT_WITHOUT_ENTRY, $checkOut);
        }

        usort($incidents, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return [
            'check_in' => $checkIn,
            'break_out' => $breakOut,
            'break_in' => $breakIn,
            'check_out' => $checkOut,
            'incidents' => $incidents,
        ];
    }
}
