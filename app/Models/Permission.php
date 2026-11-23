<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de permisos. Las claves vienen de App\Support\Permissions; aquí solo se edita
 * la descripción y se activa o desactiva.
 */
class Permission extends Model
{
    protected $fillable = ['description', 'details', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
