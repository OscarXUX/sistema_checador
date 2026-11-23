<?php

namespace Tests\Feature;

use App\Models\AttendanceEventType;
use App\Models\DailySummary;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\PunchClassifier;
use App\Support\PersonalData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class KardexExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private const RFC = 'HELS940312MDF';

    private const CURP = 'HELS940312MDFRRF02';

    private const NSS = '12345678901';

    private Employee $employee;

    private Employee $formulaEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $shift = WorkShift::create([
            'name' => 'Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 48,
        ]);

        $this->employee = Employee::create([
            'hikvision_employee_no' => '1001',
            'full_name' => 'Sofía Herrera León',
            'rfc' => self::RFC,
            'curp' => self::CURP,
            'nss' => self::NSS,
            'department' => 'Calidad',
            'position' => 'Inspectora',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        $this->formulaEmployee = Employee::create([
            'hikvision_employee_no' => '1002',
            'full_name' => '=SUM(A1:A5)',
            'rfc' => 'VELC881103MNL',
            'curp' => 'VELC881103MNL01234',
            'nss' => '22881103456',
            'department' => '+cmd',
            'position' => 'Analista',
            'work_shift_id' => $shift->id,
            'is_active' => true,
        ]);

        $onTimeEventId = AttendanceEventType::where('code', 'a_tiempo')->value('id');

        foreach (['2026-01-15', '2026-02-15', '2026-03-15'] as $workDate) {
            DailySummary::create([
                'employee_id' => $this->employee->id,
                'work_date' => $workDate,
                'first_check_in' => "{$workDate} 09:00:00",
                'break_out' => "{$workDate} 14:00:00",
                'break_in' => "{$workDate} 15:00:00",
                'last_check_out' => "{$workDate} 19:00:00",
                'worked_minutes' => 540,
                'meal_minutes' => 60,
                'late_minutes' => 0,
                'overtime_minutes' => 60,
                'status' => DailySummary::STATUS_ON_TIME,
                'entry_event_type_id' => $workDate === '2026-01-15' ? $onTimeEventId : null,
                'incidents' => $workDate === '2026-01-15'
                    ? [['code' => PunchClassifier::INCIDENT_EXTRA_ENTRY, 'at' => '2026-01-15 09:05:00']]
                    : null,
            ]);
        }

        DailySummary::create([
            'employee_id' => $this->formulaEmployee->id,
            'work_date' => '2026-01-16',
            'first_check_in' => '2026-01-16 09:00:00',
            'last_check_out' => '2026-01-16 17:00:00',
            'worked_minutes' => 480,
            'meal_minutes' => 0,
            'late_minutes' => 0,
            'overtime_minutes' => 0,
            'status' => DailySummary::STATUS_ON_TIME,
        ]);
    }

    public function test_three_months_export_produces_three_sheets_masked_ids_safe_text_and_audit_log(): void
    {
        $response = $this->get('/reports/kardex/export/xlsx?start_date=2026-01-01&end_date=2026-03-31');

        $response->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            (string) $response->headers->get('Content-Type')
        );

        $tmpFile = tempnam(sys_get_temp_dir(), 'kardex_xlsx_').'.xlsx';
        file_put_contents($tmpFile, $response->streamedContent());

        try {
            $spreadsheet = IOFactory::load($tmpFile);

            $this->assertSame(3, $spreadsheet->getSheetCount());
            $this->assertSame(['2026-01', '2026-02', '2026-03'], $spreadsheet->getSheetNames());

            $janSheet = $spreadsheet->getSheetByName('2026-01');
            $this->assertNotNull($janSheet);

            // Encabezado en negritas, primera fila congelada y autofiltro activo
            $this->assertTrue($janSheet->getStyle('A1:T1')->getFont()->getBold());
            $this->assertSame('Eventos', $janSheet->getCell('S1')->getValue());
            $this->assertSame('Incidencias', $janSheet->getCell('T1')->getValue());
            $this->assertSame('A2', $janSheet->getFreezePane());
            $this->assertNotSame('', $janSheet->getAutoFilter()->getRange());
            $this->assertGreaterThan(10, $janSheet->getColumnDimension('B')->getWidth());

            // Formatos requeridos: fechas dd/mm/yyyy (dd/mm/aaaa) y horas 0.00
            $this->assertSame('dd/mm/yyyy', $janSheet->getStyle('H2')->getNumberFormat()->getFormatCode());
            $this->assertSame('0.00', $janSheet->getStyle('Q2')->getNumberFormat()->getFormatCode());
            $this->assertSame('A tiempo', $janSheet->getCell('S2')->getValue());
            $this->assertSame('Entrada repetida (09:05:00)', $janSheet->getCell('T2')->getValue());

            // Verificar que los valores crudos sensibles NUNCA aparecen en ninguna hoja
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $rows = $sheet->toArray();
                $flat = json_encode($rows, JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString(self::RFC, (string) $flat);
                $this->assertStringNotContainsString(self::CURP, (string) $flat);
                $this->assertStringNotContainsString(self::NSS, (string) $flat);
            }

            // RFC enmascarado y texto con '=' escrito explícitamente como STRING (nunca fórmula)
            $this->assertSame(PersonalData::maskRfc(self::RFC), $janSheet->getCell('C2')->getValue());
            $this->assertSame('TOTAL Sofía Herrera León', $janSheet->getCell('B3')->getValue());
            $this->assertTrue($janSheet->getStyle('A3:T3')->getFont()->getBold());
            $this->assertSame('1 incidencia', $janSheet->getCell('T3')->getValue());
            $this->assertEqualsWithDelta(9.0, (float) $janSheet->getCell('Q3')->getValue(), 0.001);

            $formulaCell = $janSheet->getCell('B4');
            $this->assertSame(DataType::TYPE_STRING, $formulaCell->getDataType());
            $this->assertSame('=SUM(A1:A5)', $formulaCell->getValue());
        } finally {
            @unlink($tmpFile);
        }

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'kardex.exported_xlsx',
        ]);
    }

    public function test_thirteen_months_range_is_rejected_with_422(): void
    {
        $this->get('/reports/kardex/export/xlsx?start_date=2025-01-01&end_date=2026-01-31')
            ->assertStatus(422);

        $this->getJson('/reports/kardex/export/xlsx?start_date=2025-01-01&end_date=2026-01-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['end_date']);
    }

    public function test_screen_kardex_stays_capped_at_93_days(): void
    {
        $this->getJson('/reports/kardex/data?start_date=2025-01-01&end_date=2026-01-31')
            ->assertOk()
            ->assertJsonPath('filters.range_adjusted', true)
            ->assertJsonPath('filters.max_range_days', 93);
    }
}
