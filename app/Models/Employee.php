<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'device_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'work_shift_id' => 'integer',
            'is_active' => 'boolean',
            'fingerprint_enrolled' => 'boolean',
            'device_synced_at' => 'datetime',
        ];
    }

    public function workShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class);
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

