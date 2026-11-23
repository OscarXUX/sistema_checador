<?php

namespace App\Support;

use App\Models\Profile;
use App\Models\User;

/**
 * Evita quedarse sin administradores (guía 4.14: proteger registros que el sistema necesita).
 */
class AdminGuard
{
    /**
     * ¿Quedaría al menos un usuario activo con perfil de administrador activo si este usuario
     * pasa al perfil $newProfileId y al estado $newActive?
     */
    public static function keepsAnAdmin(User $user, ?int $newProfileId, bool $newActive): bool
    {
        $newProfile = $newProfileId !== null ? Profile::find($newProfileId) : null;
        $willBeAdmin = $newActive && $newProfile !== null && $newProfile->is_admin && $newProfile->is_active;

        if ($willBeAdmin) {
            return true;
        }

        return User::query()
            ->where('users.id', '!=', $user->id)
            ->where('users.is_active', true)
            ->whereHas('profile', fn ($q) => $q->where('is_admin', true)->where('is_active', true))
            ->exists();
    }
}
