<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cuadrilla o grupo de empleados para asignar turnos rotativos en conjunto.
 */
class EmployeeGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_group_employee')->withTimestamps();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftRotationAssignment::class);
    }
}
