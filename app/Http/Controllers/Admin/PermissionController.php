<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Catálogo de permisos. Las claves las define el código (App\Support\Permissions) y se cargan con
 * `php artisan permisos:sincronizar`; aquí solo se cambia la descripción y se activa o desactiva.
 * Un permiso desactivado no lo tiene nadie, ni el administrador.
 */
class PermissionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.permissions.index', [
            'lstPermisosPorModulo' => Permission::orderBy('sort_order')->get()->groupBy('module'),
            'dataModulos' => Permissions::MODULES,
        ]);
    }

    public function update(Request $request, Permission $permission): RedirectResponse
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        $dataValidado = $request->validate([
            'description' => ['required', 'string', 'max:150'],
            'details' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ], [
            'description.required' => 'Escribe la descripción del permiso.',
        ]);

        $dataAntes = $permission->only(['description', 'details', 'is_active']);
        $permission->update($dataValidado);

        [$old, $new] = $this->audit->diff($dataAntes, $permission->only(['description', 'details', 'is_active']));
        if ($new !== []) {
            $this->audit->record('permission.updated', $permission, "Cambio en el permiso {$permission->key}", $old, $new);
        }

        return redirect()->route('admin.permissions')->with('status', "Permiso {$permission->key} guardado.");
    }
}
