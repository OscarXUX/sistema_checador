<?php

namespace App\Models;

use App\Support\PersonalData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Empleado sincronizado con la terminal biométrica Hikvision DS-K1T804AEF.
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'hikvision_employee_no',
        'full_name',
        'rfc',
        'curp',
        'nss',
        'department',
        'position',
        'work_shift_id',
        'is_active',
        'fingerprint_enrolled',
        'card_no',
        'card_enrolled',
        'pin_configured',
        'device_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'work_shift_id' => 'integer',
            'is_active' => 'boolean',
            'fingerprint_enrolled' => 'boolean',
            'card_enrolled' => 'boolean',
            'pin_configured' => 'boolean',
            'device_synced_at' => 'datetime',
            // Cifrados en la base con APP_KEY (auditoría SE-05)
            'rfc' => 'encrypted',
            'curp' => 'encrypted',
            'nss' => 'encrypted',
        ];
    }

    /**
     * Columnas que nunca salen en JSON por accidente.
     *
     * @var list<string>
     */
    protected $hidden = ['rfc_hash', 'curp_hash'];

    protected static function booted(): void
    {
        // Índice ciego para buscar RFC o CURP exactos sin descifrar toda la tabla
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty('rfc')) {
                $employee->rfc_hash = PersonalData::blindIndex($employee->rfc);
            }
            if ($employee->isDirty('curp')) {
                $employee->curp_hash = PersonalData::blindIndex($employee->curp);
            }
        });
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(EmployeeGroup::class, 'employee_group_employee')->withTimestamps();
    }

    public function shiftExceptions(): HasMany
    {
        return $this->hasMany(ShiftException::class);
    }

    public function rotationAssignments(): HasMany
    {
        return $this->hasMany(ShiftRotationAssignment::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function dailySummaries(): HasMany
    {
        return $this->hasMany(DailySummary::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDepartment(Builder $query, ?string $department): Builder
    {
        if ($department === null || $department === '') {
            return $query;
        }

        return $query->where('department', $department);
    }
}
