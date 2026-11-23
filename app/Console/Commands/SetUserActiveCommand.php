<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\UserSessions;
use Illuminate\Console\Command;

/**
 * Activa o desactiva una cuenta sin borrarla (auditoría SE-01).
 */
class SetUserActiveCommand extends Command
{
    protected $signature = 'usuarios:estado
        {email : Correo de la cuenta}
        {estado : activo o inactivo}';

    protected $description = 'Activa o desactiva una cuenta; al desactivarla cierra sus sesiones.';

    public function handle(AuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $state = mb_strtolower(trim((string) $this->argument('estado')));

        if (! in_array($state, ['activo', 'inactivo'], true)) {
            $this->error('El estado debe ser "activo" o "inactivo".');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No existe una cuenta con el correo {$email}.");

            return self::FAILURE;
        }

        $active = $state === 'activo';
        $user->forceFill(['is_active' => $active])->save();

        $closed = $active ? 0 : UserSessions::closeAll($user);

        $audit->record(
            $active ? 'user.activated' : 'user.deactivated',
            $user,
            ($active ? 'Cuenta activada' : 'Cuenta desactivada')." por consola: {$email}"
        );

        $this->info($active
            ? "Cuenta {$email} activada."
            : "Cuenta {$email} desactivada. Sesiones cerradas: {$closed}.");

        return self::SUCCESS;
    }
}
