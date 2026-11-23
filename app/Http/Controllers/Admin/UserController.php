<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\AdminGuard;
use App\Support\PasswordPolicy;
use App\Support\Permissions;
use App\Support\UserSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catálogo de usuarios del sistema: alta, perfil, estado, contraseña y excepciones de permisos
 * (dar o negar a una persona), como en el sistema de tickets. Solo administradores.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index(): View
    {
        return view('admin.users.index', [
            'lstUsuarios' => User::with('profile')->withCount('permissionExceptions')->orderBy('name')->get(),
            'lstPerfiles' => Profile::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);

        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'profile_id' => ['required', 'integer', Rule::exists('profiles', 'id')->where('is_active', true)],
            'password' => [...PasswordPolicy::rules($email), 'confirmed'],
        ], $this->messages());

        $user = User::create([...$dataValidado, 'is_active' => true]);
        $user->load('profile');

        $this->audit->record('user.created', $user, "Cuenta creada desde Administración: {$user->email}", [], [
            'name' => $user->name,
            'email' => $user->email,
            'profile' => $user->profile?->name,
        ]);

        return redirect()->route('admin.users.edit', $user)->with('status', "Cuenta {$user->email} creada. Comparte la contraseña por un medio seguro.");
    }

    public function edit(User $user): View
    {
        $user->load(['profile.permissions', 'permissionExceptions']);

        $dataExcepciones = $user->permissionExceptions
            ->mapWithKeys(fn (Permission $p): array => [$p->id => $p->pivot->is_denied ? 'deny' : 'grant'])
            ->all();

        return view('admin.users.edit', [
            'user' => $user,
            'lstPerfiles' => Profile::orderBy('name')->get(),
            'lstPermisosPorModulo' => Permission::orderBy('sort_order')->get()->groupBy('module'),
            'dataModulos' => Permissions::MODULES,
            'lstDelPerfil' => $user->profile?->permissions->pluck('id')->all() ?? [],
            'dataExcepciones' => $dataExcepciones,
            'lstEfectivos' => $user->effectivePermissions(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email, 'is_active' => $request->boolean('is_active')]);

        $dataValidado = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'is_active' => ['boolean'],
            'password' => $request->filled('password') ? [...PasswordPolicy::rules($email), 'confirmed'] : ['nullable'],
        ], $this->messages());

        if (! AdminGuard::keepsAnAdmin($user, (int) $dataValidado['profile_id'], (bool) $dataValidado['is_active'])) {
            return back()->withInput()->withErrors([
                'profile_id' => 'Debe quedar al menos un administrador activo. Asigna otro administrador antes de cambiar esta cuenta.',
            ]);
        }

        $dataAntes = ['name' => $user->name, 'email' => $user->email, 'profile' => $user->profile?->name, 'is_active' => $user->is_active];

        $user->fill([
            'name' => $dataValidado['name'],
            'email' => $dataValidado['email'],
            'profile_id' => $dataValidado['profile_id'],
            'is_active' => $dataValidado['is_active'],
        ]);

        $passwordChanged = $request->filled('password');
        if ($passwordChanged) {
            $user->password = $dataValidado['password'];
            $user->remember_token = Str::random(60);
        }

        $user->save();
        $user->load('profile');

        // Al desactivar o cambiar la contraseña se cierran sus sesiones abiertas
        $closed = (! $user->is_active || $passwordChanged) && $user->id !== $request->user()->id
            ? UserSessions::closeAll($user)
            : 0;

        $dataDespues = ['name' => $user->name, 'email' => $user->email, 'profile' => $user->profile?->name, 'is_active' => $user->is_active];
        [$old, $new] = $this->audit->diff($dataAntes, $dataDespues);
        if ($passwordChanged) {
            $new['password'] = '[cambiada]';
        }
        if ($new !== []) {
            $this->audit->record('user.updated', $user, "Cambio en la cuenta {$user->email}", $old, $new);
        }

        $message = "Cuenta {$user->email} actualizada.".($closed > 0 ? " Se cerraron {$closed} sesión(es)." : '');

        return redirect()->route('admin.users.edit', $user)->with('status', $message);
    }

    /**
     * Excepciones por persona: para cada permiso, "según perfil", "dar" o "negar".
     */
    public function permissions(Request $request, User $user): RedirectResponse
    {
        $dataValidado = $request->validate([
            'exceptions' => ['array'],
            'exceptions.*' => ['in:profile,grant,deny'],
        ]);

        $lstIdsValidos = Permission::pluck('id')->all();
        $dataNuevas = collect($dataValidado['exceptions'] ?? [])
            ->filter(fn (string $mode, $id): bool => $mode !== 'profile' && in_array((int) $id, $lstIdsValidos, true))
            ->mapWithKeys(fn (string $mode, $id): array => [(int) $id => ['is_denied' => $mode === 'deny']])
            ->all();

        $dataAntes = $this->exceptionKeys($user);

        DB::transaction(fn () => $user->permissionExceptions()->sync($dataNuevas));

        $dataDespues = $this->exceptionKeys($user->fresh());
        if ($dataAntes !== $dataDespues) {
            $this->audit->record('user.permissions_updated', $user, "Excepciones de permisos de {$user->email}", $dataAntes, $dataDespues);
        }

        return redirect()->route('admin.users.edit', $user)->with('status', 'Excepciones de permisos guardadas.');
    }

    /**
     * @return array<string, list<string>>
     */
    private function exceptionKeys(User $user): array
    {
        $lstExcepciones = $user->permissionExceptions()->orderBy('key')->get();

        return [
            'dados' => $lstExcepciones->filter(fn ($p): bool => ! $p->pivot->is_denied)->pluck('key')->values()->all(),
            'negados' => $lstExcepciones->filter(fn ($p): bool => (bool) $p->pivot->is_denied)->pluck('key')->values()->all(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            ...PasswordPolicy::messages(),
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.email' => 'Escribe un correo válido.',
            'email.unique' => 'Ya existe una cuenta con ese correo.',
            'profile_id.required' => 'Elige un perfil.',
            'profile_id.exists' => 'El perfil no existe o está inactivo.',
        ];
    }
}
