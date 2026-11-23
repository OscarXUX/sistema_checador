<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de tipos de evento (nota 4 de Oscar): el nombre que recibe una entrada, salida o comida
 * según sus minutos. Entrada = minutos de retardo; salida = minutos antes de la hora de salida;
 * comida = minutos por encima de la comida permitida.
 */
class AttendanceEventType extends Model
{
    public const APPLIES_TO = [
        'entrada' => 'Entrada (minutos de retardo)',
        'salida' => 'Salida (minutos antes de la hora)',
        'comida' => 'Comida (minutos excedidos)',
    ];

    public const COLORS = [
        'success' => 'Verde',
        'warning' => 'Amarillo',
        'danger' => 'Rojo',
        'info' => 'Azul',
        'secondary' => 'Gris',
    ];

    protected $fillable = ['code', 'name', 'applies_to', 'min_minutes', 'max_minutes', 'color', 'sort_order', 'is_system', 'is_active'];

    protected function casts(): array
    {
        return [
            'min_minutes' => 'integer',
            'max_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function contains(int $minutes): bool
    {
        return $minutes >= $this->min_minutes && ($this->max_minutes === null || $minutes <= $this->max_minutes);
    }

    public function rangeLabel(): string
    {
        return $this->max_minutes === null
            ? "{$this->min_minutes} min o más"
            : ($this->min_minutes === $this->max_minutes ? "{$this->min_minutes} min" : "{$this->min_minutes} a {$this->max_minutes} min");
    }
}
