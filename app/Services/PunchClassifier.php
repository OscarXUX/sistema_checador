<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\AttendanceLogCorrection;
use App\Models\WorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Clasifica las checadas de una jornada por hora (nota 6 de Oscar: "que sea por hora y no por orden")
 * y detecta las repetidas o fuera de orden (nota 3). La bitácora no se toca: las checadas que no
 * cuentan solo quedan anotadas como incidencia en el resumen diario.
 *
 * Reglas, en este orden:
 * 0. Una corrección manual manda: "No cuenta" la quita y un tipo la fija (gana a las ventanas).
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

    /** Qué fue cada checada (para la pantalla de corrección) */
    public const ROLE_LABELS = [
        AttendanceLog::EVENT_CHECK_IN => 'Entrada',
        AttendanceLog::EVENT_BREAK_OUT => 'Salida a comer',
        AttendanceLog::EVENT_BREAK_IN => 'Regreso de comer',
        AttendanceLog::EVENT_CHECK_OUT => 'Salida',
        'not_counted' => 'No cuenta',
    ];

    /**
     * @param  Collection<int, AttendanceLog>  $logs  ordenadas por hora (con la relación correction cargada si existe)
     * @return array{check_in: ?CarbonImmutable, break_out: ?CarbonImmutable, break_in: ?CarbonImmutable, check_out: ?CarbonImmutable, incidents: list<array{code: string, at: string}>, roles: array<int, string>, corrected: int}
     */
    public function classify(Collection $logs, WorkShift $shift, CarbonImmutable $workDate, bool $isActiveShiftWindow): array
    {
        $incidents = [];
        $roles = [];
        $note = function (string $code, array $item) use (&$incidents, &$roles): void {
            $incidents[] = ['code' => $code, 'at' => $item['at']->format('Y-m-d H:i:s')];
            $roles[$item['id']] = 'not_counted';
        };

        $windows = $shift->classificationWindows($workDate);
        [$entryStart, $entryEnd] = $windows['entrada'];
        [$exitStart, $exitEnd] = $windows['salida'];

        $threshold = max(0, (int) ($shift->repeat_threshold_minutes ?? 3)) * 60;
        $lstEntradas = [];
        $lstSalidas = [];
        $lstComidas = [];
        $dataFijas = [AttendanceLog::EVENT_CHECK_IN => [], AttendanceLog::EVENT_CHECK_OUT => []];
        $corrected = 0;
        $previous = null;

        foreach ($logs as $log) {
            $item = ['at' => CarbonImmutable::instance($log->punched_at), 'id' => (int) $log->id];
            $key = (string) $log->event_type;
            $correction = $log->relationLoaded('correction') ? $log->correction : null;

            // 0. Corrección manual
            if ($correction !== null) {
                $corrected++;
                $type = $correction->corrected_type;
                if ($type === AttendanceLogCorrection::TYPE_IGNORE) {
                    $roles[$item['id']] = 'not_counted';
                } elseif (isset($dataFijas[$type])) {
                    $dataFijas[$type][] = $item;
                } else {
                    $lstComidas[] = $item;
                }
                $previous = $type === AttendanceLogCorrection::TYPE_IGNORE ? $previous : $item['at'];

                continue;
            }

            // 1. Repetidas
            if ($previous !== null && $threshold > 0 && $previous->diffInSeconds($item['at'], true) < $threshold) {
                $note(self::INCIDENT_REPEATED, $item);

                continue;
            }
            $previous = $item['at'];
            $at = $item['at'];

            // 2 a 5. Clasificación por ventana
            if ($key === AttendanceLog::EVENT_BREAK_OUT || $key === AttendanceLog::EVENT_BREAK_IN) {
                $lstComidas[] = $item;
            } elseif ($at->betweenIncluded($entryStart, $entryEnd)) {
                $lstEntradas[] = $item;
            } elseif ($at->betweenIncluded($exitStart, $exitEnd)) {
                $lstSalidas[] = $item;
            } elseif ($at->greaterThan($entryEnd) && $at->lessThan($exitStart)) {
                if ($key === AttendanceLog::EVENT_CHECK_OUT) {
                    $lstSalidas[] = $item;
                } elseif ($lstEntradas === [] && $lstComidas === [] && $dataFijas[AttendanceLog::EVENT_CHECK_IN] === []) {
                    $lstEntradas[] = $item;
                } else {
                    $lstComidas[] = $item;
                }
            } elseif ($at->lessThan($entryStart)) {
                $incidents[] = ['code' => self::INCIDENT_OUT_OF_WINDOW, 'at' => $at->format('Y-m-d H:i:s')];
                if ($key === AttendanceLog::EVENT_CHECK_OUT) {
                    $lstSalidas[] = $item;
                } else {
                    $lstEntradas[] = $item;
                }
            } else {
                $incidents[] = ['code' => self::INCIDENT_OUT_OF_WINDOW, 'at' => $at->format('Y-m-d H:i:s')];
                if ($key === AttendanceLog::EVENT_CHECK_IN && $lstEntradas === []) {
                    $lstEntradas[] = $item;
                } else {
                    $lstSalidas[] = $item;
                }
            }
        }

        // Entrada: la corregida si la hay; si no, la primera. Las demás son repetidas
        if ($dataFijas[AttendanceLog::EVENT_CHECK_IN] !== []) {
            $checkIn = array_shift($dataFijas[AttendanceLog::EVENT_CHECK_IN]);
            $lstSobran = [...$dataFijas[AttendanceLog::EVENT_CHECK_IN], ...$lstEntradas];
        } else {
            $checkIn = array_shift($lstEntradas);
            $lstSobran = $lstEntradas;
        }
        foreach ($lstSobran as $item) {
            $note(self::INCIDENT_EXTRA_ENTRY, $item);
        }

        // Salida: la corregida si la hay; si no, la última. Las anteriores son repetidas
        if ($dataFijas[AttendanceLog::EVENT_CHECK_OUT] !== []) {
            $checkOut = array_pop($dataFijas[AttendanceLog::EVENT_CHECK_OUT]);
            $lstSobran = [...$dataFijas[AttendanceLog::EVENT_CHECK_OUT], ...$lstSalidas];
        } else {
            $checkOut = array_pop($lstSalidas);
            $lstSobran = $lstSalidas;
        }
        foreach ($lstSobran as $item) {
            $note(self::INCIDENT_EXTRA_EXIT, $item);
        }

        // 6. Jornada cerrada sin salida y comida impar: la última checada fue la salida
        usort($lstComidas, fn (array $a, array $b): int => $a['at'] <=> $b['at']);
        if ($checkOut === null && ! $isActiveShiftWindow && count($lstComidas) % 2 === 1) {
            $checkOut = array_pop($lstComidas);
        }

        $breakOut = $lstComidas[0] ?? null;
        $breakIn = count($lstComidas) >= 2 ? $lstComidas[count($lstComidas) - 1] : null;
        foreach (array_slice($lstComidas, 1, max(0, count($lstComidas) - 2)) as $item) {
            $note(self::INCIDENT_EXTRA_MEAL, $item);
        }

        if ($checkOut !== null && $checkIn === null) {
            $incidents[] = ['code' => self::INCIDENT_EXIT_WITHOUT_ENTRY, 'at' => $checkOut['at']->format('Y-m-d H:i:s')];
        }

        foreach ([
            AttendanceLog::EVENT_CHECK_IN => $checkIn,
            AttendanceLog::EVENT_BREAK_OUT => $breakOut,
            AttendanceLog::EVENT_BREAK_IN => $breakIn,
            AttendanceLog::EVENT_CHECK_OUT => $checkOut,
        ] as $role => $item) {
            if ($item !== null) {
                $roles[$item['id']] = $role;
            }
        }

        usort($incidents, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return [
            'check_in' => $checkIn['at'] ?? null,
            'break_out' => $breakOut['at'] ?? null,
            'break_in' => $breakIn['at'] ?? null,
            'check_out' => $checkOut['at'] ?? null,
            'incidents' => $incidents,
            'roles' => $roles,
            'corrected' => $corrected,
        ];
    }
}
