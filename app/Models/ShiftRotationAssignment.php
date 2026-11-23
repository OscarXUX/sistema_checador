<?php

namespace App\Models;

use App\Casts\DateOnlyCast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación temporal de una rotación de turnos a un empleado, grupo o departamento.
 */
class ShiftRotationAssignment extends Model
{
    use HasFactory;

    public const TARGET_EMPLOYEE = 'employee';

    public const TARGET_GROUP = 'group';

    public const TARGET_DEPARTMENT = 'department';

    public const TARGETS = [
        self::TARGET_EMPLOYEE,
        self::TARGET_GROUP,
        self::TARGET_DEPARTMENT,
    ];

    protected $fillable = [
        'shift_rotation_id',
        'target_type',
        'employee_id',
        'employee_group_id',
        'department',
        'starts_on',
        'ends_on',
        'start_step_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'shift_rotation_id' => 'integer',
            'employee_id' => 'integer',
            'employee_group_id' => 'integer',
            'starts_on' => DateOnlyCast::class,
            'ends_on' => DateOnlyCast::class,
            'start_step_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function rotation(): BelongsTo
    {
        return $this->belongsTo(ShiftRotation::class, 'shift_rotation_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(EmployeeGroup::class, 'employee_group_id');
    }

    public function targetLabel(): string
    {
        return match ($this->target_type) {
            self::TARGET_EMPLOYEE => $this->employee !== null
                ? "[{$this->employee->hikvision_employee_no}] {$this->employee->full_name}"
                : "Empleado #{$this->employee_id}",
            self::TARGET_GROUP => $this->group?->name ?? "Grupo #{$this->employee_group_id}",
            self::TARGET_DEPARTMENT => (string) ($this->department ?? '-'),
            default => '-',
        };
    }

    public static function targetTypeLabel(string $targetType): string
    {
        return match ($targetType) {
            self::TARGET_EMPLOYEE => 'Empleado',
            self::TARGET_GROUP => 'Grupo',
            self::TARGET_DEPARTMENT => 'Departamento',
            default => ucfirst($targetType),
        };
    }
}
