<?php

namespace App\Services;

use App\Models\DailySummary;
use App\Support\PersonalData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Genera el libro Excel (.xlsx) del Kardex Fiscal con una hoja por mes calendario.
 * Se construye íntegramente en el servidor (PhpSpreadsheet) sin depender de Wijmo.
 */
class KardexExcelExportService
{
    public const MAX_MONTHS = 12;

    /**
     * @var array<string, array{0: string, 1: int}>
     */
    private const COLUMNS = [
        'A' => ['ID Reloj', 12],
        'B' => ['Empleado', 28],
        'C' => ['RFC', 16],
        'D' => ['CURP', 22],
        'E' => ['NSS', 15],
        'F' => ['Departamento', 18],
        'G' => ['Turno', 20],
        'H' => ['Fecha', 14],
        'I' => ['Entrada', 12],
        'J' => ['Salida Comida', 14],
        'K' => ['Regreso Comida', 15],
        'L' => ['Salida', 12],
        'M' => ['Comida (min)', 14],
        'N' => ['Retardo (min)', 14],
        'O' => ['Horas Ordinarias', 17],
        'P' => ['Horas Extra', 14],
        'Q' => ['Horas Totales', 15],
        'R' => ['Estatus Fiscal', 18],
        'S' => ['Eventos', 32],
        'T' => ['Incidencias', 36],
    ];

    /**
     * Devuelve la lista ordenada de meses calendario ('Y-m') entre dos fechas.
     *
     * @return list<string>
     */
    public function resolveMonths(CarbonInterface $start, CarbonInterface $end): array
    {
        $startMonth = CarbonImmutable::instance($start)->startOfMonth();
        $endMonth = CarbonImmutable::instance($end)->startOfMonth();

        if ($startMonth->greaterThan($endMonth)) {
            [$startMonth, $endMonth] = [$endMonth, $startMonth];
        }

        $months = [];
        $cursor = $startMonth;

        while ($cursor->lessThanOrEqualTo($endMonth)) {
            $months[] = $cursor->format('Y-m');

            if (count($months) > self::MAX_MONTHS) {
                throw new InvalidArgumentException(
                    sprintf('El rango máximo para exportar a Excel es de %d meses.', self::MAX_MONTHS)
                );
            }

            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * Construye el Spreadsheet con una pestaña por cada mes del rango y totales por empleado.
     *
     * @return array{spreadsheet: Spreadsheet, months: list<string>, row_count: int}
     */
    public function buildSpreadsheet(
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $department = null,
        ?int $employeeId = null,
    ): array {
        $startDay = CarbonImmutable::instance($start)->startOfDay();
        $endDay = CarbonImmutable::instance($end)->startOfDay();

        if ($startDay->greaterThan($endDay)) {
            [$startDay, $endDay] = [$endDay, $startDay];
        }

        $months = $this->resolveMonths($startDay, $endDay);
        $resolver = app(ShiftScheduleResolver::class);

        $departmentFilter = trim((string) $department);

        $summaries = DailySummary::with([
            'employee.workShift',
            'employee.groups:id',
            'entryEventType',
            'exitEventType',
            'mealEventType',
        ])
            ->whereBetween('work_date', [$startDay->toDateString(), $endDay->toDateString()])
            ->whereHas('employee', function ($q) use ($departmentFilter, $employeeId): void {
                if ($departmentFilter !== '') {
                    $q->where('department', $departmentFilter);
                }
                if ($employeeId !== null) {
                    $q->where('id', $employeeId);
                }
            })
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get();

        $employees = $summaries->pluck('employee')->filter()->unique('id')->values();
        $scheduleContext = $employees->isNotEmpty()
            ? $resolver->loadBatchContext($employees, $startDay, $endDay)
            : null;

        $byMonth = $summaries->groupBy(fn (DailySummary $s): string => $s->work_date->format('Y-m'));

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('Sistema Checador ISAPI')
            ->setTitle("Kardex Fiscal {$startDay->toDateString()} a {$endDay->toDateString()}")
            ->setSubject('Kardex Fiscal Mensual');

        foreach ($months as $index => $monthKey) {
            $sheet = $index === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($index);

            $sheet->setTitle($monthKey);
            $monthRows = $byMonth->get($monthKey, collect());
            $this->populateMonthSheet($sheet, $monthRows, $resolver, $scheduleContext);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return [
            'spreadsheet' => $spreadsheet,
            'months' => $months,
            'row_count' => $summaries->count(),
        ];
    }

    /**
     * @param  Collection<int, DailySummary>  $summaries
     * @param  array<string, mixed>|null  $scheduleContext
     */
    private function populateMonthSheet(
        Worksheet $sheet,
        Collection $summaries,
        ShiftScheduleResolver $resolver,
        ?array $scheduleContext,
    ): void {
        // Encabezados en negritas, texto explícito y anchos definidos
        foreach (self::COLUMNS as $col => [$label, $width]) {
            $sheet->setCellValueExplicit("{$col}1", $label, DataType::TYPE_STRING);
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $sheet->getStyle('A1:T1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['argb' => 'FFF8FAFC'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FF0F172A'],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        // Primera fila fija (headers visibles al desplazarse)
        $sheet->freezePane('A2');

        $row = 2;

        if ($summaries->isEmpty()) {
            $sheet->setCellValueExplicit('A2', '-', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B2', 'Sin registros en este mes', DataType::TYPE_STRING);
            $sheet->getStyle('B2')->getFont()->setItalic(true);
            $row = 3;
        } else {
            $groupedByEmployee = $summaries->groupBy('employee_id');

            foreach ($groupedByEmployee as $employeeSummaries) {
                $first = $employeeSummaries->first();
                $emp = $first?->employee;
                $empName = $emp?->full_name ?? 'N/D';
                $empNo = (string) ($emp?->hikvision_employee_no ?? '-');
                $maskedRfc = PersonalData::maskRfc($emp?->rfc) ?? '-';
                $maskedCurp = PersonalData::maskCurp($emp?->curp) ?? '-';
                $maskedNss = PersonalData::maskNss($emp?->nss) ?? '-';
                $dept = (string) ($emp?->department ?? '-');

                $totalMealMinutes = 0;
                $totalLateMinutes = 0;
                $totalOrdinaryMinutes = 0;
                $totalOvertimeMinutes = 0;
                $totalWorkedMinutes = 0;
                $totalIncidents = 0;
                $daysCount = 0;

                foreach ($employeeSummaries as $summary) {
                    $resolvedShift = ($emp !== null && $scheduleContext !== null)
                        ? $resolver->resolveDetailFromContext($emp, CarbonImmutable::instance($summary->work_date)->startOfDay(), $scheduleContext)['shift']
                        : $emp?->workShift;
                    $shiftName = (string) ($resolvedShift?->name ?? $emp?->workShift?->name ?? '-');
                    $ordinaryMinutes = max(0, $summary->worked_minutes - $summary->overtime_minutes);
                    $eventsLabel = collect([$summary->entryEventType, $summary->mealEventType, $summary->exitEventType])
                        ->filter()
                        ->pluck('name')
                        ->implode(', ');
                    $incidents = $summary->incidentList();
                    $incidentsLabel = collect($incidents)
                        ->map(fn (array $i): string => "{$i['label']} ({$i['at']})")
                        ->implode('; ');

                    $totalMealMinutes += $summary->meal_minutes;
                    $totalLateMinutes += $summary->late_minutes;
                    $totalOrdinaryMinutes += $ordinaryMinutes;
                    $totalOvertimeMinutes += $summary->overtime_minutes;
                    $totalWorkedMinutes += $summary->worked_minutes;
                    $totalIncidents += count($incidents);
                    $daysCount++;

                    // Todos los textos se escriben explícitamente como STRING para impedir ejecución de fórmulas (=, +, -, @)
                    $this->writeStringCell($sheet, "A{$row}", $empNo);
                    $this->writeStringCell($sheet, "B{$row}", $empName);
                    $this->writeStringCell($sheet, "C{$row}", $maskedRfc);
                    $this->writeStringCell($sheet, "D{$row}", $maskedCurp);
                    $this->writeStringCell($sheet, "E{$row}", $maskedNss);
                    $this->writeStringCell($sheet, "F{$row}", $dept);
                    $this->writeStringCell($sheet, "G{$row}", $shiftName);

                    $excelDate = ExcelDate::PHPToExcel(
                        new DateTimeImmutable($summary->work_date->toDateString(), new DateTimeZone('UTC'))
                    );
                    $sheet->setCellValueExplicit("H{$row}", $excelDate, DataType::TYPE_NUMERIC);
                    $sheet->getStyle("H{$row}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');

                    $this->writeStringCell($sheet, "I{$row}", $summary->first_check_in?->format('H:i:s') ?? '--:--');
                    $this->writeStringCell($sheet, "J{$row}", $summary->break_out?->format('H:i:s') ?? '--:--');
                    $this->writeStringCell($sheet, "K{$row}", $summary->break_in?->format('H:i:s') ?? '--:--');
                    $this->writeStringCell($sheet, "L{$row}", $summary->last_check_out?->format('H:i:s') ?? '--:--');

                    $sheet->setCellValueExplicit("M{$row}", (int) $summary->meal_minutes, DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit("N{$row}", (int) $summary->late_minutes, DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit("O{$row}", round($ordinaryMinutes / 60, 2), DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit("P{$row}", round($summary->overtime_minutes / 60, 2), DataType::TYPE_NUMERIC);
                    $sheet->setCellValueExplicit("Q{$row}", round($summary->worked_minutes / 60, 2), DataType::TYPE_NUMERIC);

                    $sheet->getStyle("M{$row}:N{$row}")->getNumberFormat()->setFormatCode('0');
                    $sheet->getStyle("O{$row}:Q{$row}")->getNumberFormat()->setFormatCode('0.00');

                    $this->writeStringCell($sheet, "R{$row}", DailySummary::statusLabel($summary->status));
                    $this->writeStringCell($sheet, "S{$row}", $eventsLabel);
                    $this->writeStringCell($sheet, "T{$row}", $incidentsLabel);

                    $row++;
                }

                // Fila de totales por empleado en el mes
                $this->writeStringCell($sheet, "A{$row}", $empNo);
                $this->writeStringCell($sheet, "B{$row}", "TOTAL {$empName}");
                foreach (range('C', 'L') as $col) {
                    $this->writeStringCell($sheet, "{$col}{$row}", '');
                }

                $sheet->setCellValueExplicit("M{$row}", $totalMealMinutes, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("N{$row}", $totalLateMinutes, DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("O{$row}", round($totalOrdinaryMinutes / 60, 2), DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("P{$row}", round($totalOvertimeMinutes / 60, 2), DataType::TYPE_NUMERIC);
                $sheet->setCellValueExplicit("Q{$row}", round($totalWorkedMinutes / 60, 2), DataType::TYPE_NUMERIC);
                $this->writeStringCell($sheet, "R{$row}", "{$daysCount} días");
                $this->writeStringCell($sheet, "S{$row}", '');
                $this->writeStringCell($sheet, "T{$row}", $totalIncidents === 1 ? '1 incidencia' : "{$totalIncidents} incidencias");

                $sheet->getStyle("M{$row}:N{$row}")->getNumberFormat()->setFormatCode('0');
                $sheet->getStyle("O{$row}:Q{$row}")->getNumberFormat()->setFormatCode('0.00');
                $sheet->getStyle("A{$row}:T{$row}")->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'color' => ['argb' => 'FF0F172A'],
                    ],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['argb' => 'FFE2E8F0'],
                    ],
                    'borders' => [
                        'top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF94A3B8']],
                        'bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF94A3B8']],
                    ],
                ]);

                $row++;
            }
        }

        $lastRow = max(1, $row - 1);
        $sheet->setAutoFilter("A1:T{$lastRow}");
    }

    private function writeStringCell(Worksheet $sheet, string $coordinate, string $value): void
    {
        $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('@');
    }
}
