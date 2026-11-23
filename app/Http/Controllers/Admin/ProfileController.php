<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Profile;
use App\Services\AuditLogger;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo de perfiles y sus permisos (equivale a "puestos" en el sistema de tickets).
 * El perfil de administrador tiene todos los permisos siempre: no se edita su lista,
 * no se desactiva ni se borra.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.profiles.index', [
            'lstPerfiles' => Profile::withCount(['users', 'permissions'])->orderByDesc('is_admin')->orderBy('name')->get(),
            'totalPermisos' => Permission::active()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:profiles,name'],
            'description' => ['nullable', 'string', 'max:255'],
        ], $this->messages());

        $profile = Profile::create([...$dataValidado, 'is_admin' => false, 'is_active' => true]);

        $this->audit->record('profile.created', $profile, "Perfil creado: {$profile->name}", [], [
            'name' => $profile->name,
            'description' => $profile->description,
        ]);

        return redirect()->route('admin.profiles.edit', $profile)->with('status', "Perfil {$profile->name} creado. Marca sus permisos.");
    }

    public function edit(Profile $profile): View
    {
        $profile->load('permissions')->loadCount('users');

        return view('admin.profiles.edit', [
            'profile' => $profile,
            'lstPermisosPorModulo' => Permission::orderBy('sort_order')->get()->groupBy('module'),
            'dataModulos' => Permissions::MODULES,
            'lstMarcados' => $profile->permissions->pluck('id')->all(),
        ]);
    }

    public function update(Request $request, Profile $profile): RedirectResponse
    {
        $request->merge(['is_active' => $request->boolean('is_active')]);

        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('profiles', 'name')->ignore($profile->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ], $this->messages());

        if ($profile->is_admin && ! $dataValidado['is_active']) {
            return back()->withInput()->withErrors(['is_active' => 'El perfil de administrador no se puede desactivar.']);
        }

        $dataAntes = $this->snapshot($profile);

        DB::transaction(function () use ($profile, $dataValidado): void {
            $profile->update([
                'name' => $dataValidado['name'],
                'description' => $dataValidado['description'] ?? null,
                'is_active' => $dataValidado['is_active'],
            ]);

            // El administrador tiene todo por definición; su lista no se guarda
            if (! $profile->is_admin) {
                $profile->permissions()->sync($dataValidado['permissions'] ?? []);
            }
        });

        [$old, $new] = $this->audit->diff($dataAntes, $this->snapshot($profile->fresh('permissions')));
        if ($new !== []) {
            $this->audit->record('profile.updated', $profile, "Cambio en el perfil {$profile->name}", $old, $new);
        }

        return redirect()->route('admin.profiles.edit', $profile)->with('status', 'Perfil guardado.');
    }

    public function destroy(Profile $profile): RedirectResponse
    {
        if ($profile->is_admin) {
            return back()->withErrors(['profile' => 'El perfil de administrador no se puede eliminar.']);
        }

        if ($profile->users()->exists()) {
            return back()->withErrors(['profile' => "El perfil {$profile->name} tiene usuarios. Cámbialos de perfil o desactívalo en lugar de eliminarlo."]);
        }

        $name = $profile->name;
        $profile->delete();

        $this->audit->record('profile.deleted', null, "Perfil eliminado: {$name}", ['name' => $name]);

        return redirect()->route('admin.profiles')->with('status', "Perfil {$name} eliminado.");
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Profile $profile): array
    {
        return [
            'name' => $profile->name,
            'description' => $profile->description,
            'is_active' => $profile->is_active,
            'permissions' => $profile->permissions->pluck('key')->sort()->values()->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => 'Escribe el nombre del perfil.',
            'name.unique' => 'Ya existe un perfil con ese nombre.',
            'name.max' => 'El nombre admite hasta 80 caracteres.',
        ];
    }
}
