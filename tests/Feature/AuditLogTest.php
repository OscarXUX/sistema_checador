<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkShift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Bitácora de acciones de usuarios (auditoría SE-09).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function shift(): WorkShift
    {
        return WorkShift::create([
            'name' => 'Matutino',
            'entry_time' => '09:00:00',
            'exit_time' => '18:00:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 10,
            'max_weekly_hours' => 40,
        ]);
    }

    private function employeePayload(WorkShift $shift, array $overrides = []): array
    {
        return [
            'hikvision_employee_no' => '5100',
            'full_name' => 'Elena Vargas Ruiz',
            'rfc' => 'VARE900101AB1',
            'curp' => 'VARE900101MDFRZL09',
            'nss' => '12345678901',
            'department' => 'Compras',
            'position' => 'Analista',
            'work_shift_id' => $shift->id,
            'is_active' => true,
            ...$overrides,
        ];
    }

    public function test_employee_creation_is_audited_without_personal_ids(): void
    {
        $this->postJson('/employees', $this->employeePayload($this->shift()))->assertCreated();

        $log = AuditLog::where('action', 'employee.created')->firstOrFail();
        $employee = Employee::where('hikvision_employee_no', '5100')->firstOrFail();

        $this->assertSame($this->user->id, $log->user_id);
        $this->assertSame($employee->id, $log->auditable_id);
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('[protegido]', $log->new_values['rfc']);
        $this->assertSame('[protegido]', $log->new_values['curp']);
        $this->assertSame('[protegido]', $log->new_values['nss']);
        $this->assertStringNotContainsString('VARE900101', (string) json_encode($log->toArray()));
    }

    public function test_employee_update_records_only_changed_fields(): void
    {
        $shift = $this->shift();
        $this->postJson('/employees', $this->employeePayload($shift))->assertCreated();
        $employee = Employee::where('hikvision_employee_no', '5100')->firstOrFail();

        $this->putJson("/employees/{$employee->id}", $this->employeePayload($shift, [
            'position' => 'Jefa de Compras',
            'rfc' => 'VARE900101AB2',
        ]))->assertOk();

        $log = AuditLog::where('action', 'employee.updated')->firstOrFail();

        $this->assertEquals(['position' => 'Analista', 'rfc' => '[protegido]'], $log->old_values);
        $this->assertEquals(['position' => 'Jefa de Compras', 'rfc' => '[protegido]'], $log->new_values);
    }

    public function test_employee_deletion_is_audited(): void
    {
        $this->postJson('/employees', $this->employeePayload($this->shift()))->assertCreated();
        $employee = Employee::where('hikvision_employee_no', '5100')->firstOrFail();

        $this->deleteJson("/employees/{$employee->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'employee.deleted',
            'auditable_id' => $employee->id,
            'user_id' => $this->user->id,
        ]);
    }

    public function test_shift_change_is_audited(): void
    {
        $shift = $this->shift();

        $this->put("/shifts/{$shift->id}", [
            'name' => 'Matutino',
            'entry_time' => '08:30',
            'exit_time' => '18:00',
            'meal_duration_minutes' => 60,
            'tolerance_minutes' => 15,
            'max_weekly_hours' => 40,
        ])->assertRedirect();

        $log = AuditLog::where('action', 'shift.updated')->firstOrFail();

        $this->assertSame('09:00:00', $log->old_values['entry_time']);
        $this->assertSame('08:30:00', $log->new_values['entry_time']);
        $this->assertSame(10, $log->old_values['tolerance_minutes']);
        $this->assertSame(15, $log->new_values['tolerance_minutes']);
        $this->assertArrayNotHasKey('exit_time', $log->new_values);
    }

    public function test_audit_log_cannot_be_modified_or_deleted(): void
    {
        $this->postJson('/employees', $this->employeePayload($this->shift()))->assertCreated();
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['description' => 'cambiado']);
            $this->fail('Se esperaba LogicException al modificar');
        } catch (LogicException) {
            // esperado
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
