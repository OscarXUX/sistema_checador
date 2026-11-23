<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserSessions
{
    /**
     * Cierra todas las sesiones abiertas de la cuenta. Requiere SESSION_DRIVER=database.
     */
    public static function closeAll(User $user): int
    {
        if (config('session.driver') !== 'database') {
            return 0;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }
}
