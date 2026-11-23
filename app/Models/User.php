<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'profile_id',
    ];

    /**
     * Permisos efectivos calculados en esta petición.
     *
     * @var list<string>|null
     */
    private ?array $lstPermissionsCache = null;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'profile_id' => 'integer',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Excepciones por persona: permisos dados (is_denied = false) o negados (is_denied = true).
     */
    public function permissionExceptions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'user_permissions')->withPivot('is_denied');
    }

    /**
     * Administrador: su perfil está marcado como administrador y está activo.
     */
    public function isAdmin(): bool
    {
        $this->loadMissing('profile');

        return $this->profile !== null && $this->profile->is_admin && $this->profile->is_active;
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->effectivePermissions(), true);
    }

    /**
     * Claves que tiene: todas las activas si es administrador; si no, las de su perfil + las dadas
     * − las negadas, solo de permisos activos. Misma regla que Usuario::permisosEfectivos de tickets.
     *
     * @return list<string>
     */
    public function effectivePermissions(): array
    {
        if ($this->lstPermissionsCache !== null) {
            return $this->lstPermissionsCache;
        }

        $dataActive = Permission::active()->pluck('key', 'id')->all();   // id => clave

        if ($this->isAdmin()) {
            return $this->lstPermissionsCache = array_values($dataActive);
        }

        $lstFromProfile = [];
        if ($this->profile !== null && $this->profile->is_active) {
            $lstFromProfile = $this->profile->permissions()->pluck('permissions.id')->all();
        }

        $lstOwn = $this->permissionExceptions()->get(['permissions.id']);
        $lstGranted = $lstOwn->filter(fn ($p): bool => ! $p->pivot->is_denied)->pluck('id')->all();
        $lstDenied = $lstOwn->filter(fn ($p): bool => (bool) $p->pivot->is_denied)->pluck('id')->all();

        $lstIds = array_diff(
            array_unique(array_map('intval', [...$lstFromProfile, ...$lstGranted])),
            array_map('intval', $lstDenied)
        );

        return $this->lstPermissionsCache = array_values(array_filter(
            array_map(fn (int $id): ?string => $dataActive[$id] ?? null, $lstIds)
        ));
    }

    /**
     * Primera pantalla a la que puede entrar, para no mandarlo a una que le dé 403 al iniciar sesión.
     */
    public function homeRouteName(): string
    {
        $dataPantallas = [
            'dashboard' => 'dashboard.consultar',
            'employees.index' => 'empleados.consultar',
            'reports.kardex' => 'kardex.consultar',
            'shifts.index' => 'turnos.consultar',
            'marking-methods.index' => 'medios.consultar',
        ];

        foreach ($dataPantallas as $route => $permission) {
            if ($this->hasPermission($permission)) {
                return $route;
            }
        }

        return $this->isAdmin() ? 'admin.users' : 'home';
    }

    public function forgetPermissionsCache(): void
    {
        $this->lstPermissionsCache = null;
        $this->unsetRelation('profile');
    }
}
