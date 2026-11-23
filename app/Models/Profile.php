<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil de un usuario del sistema (equivale al "puesto" de usuarios del sistema de tickets).
 * No confundir con el puesto de un empleado.
 */
class Profile extends Model
{
    protected $fillable = ['name', 'description', 'is_admin', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'profile_permission');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
