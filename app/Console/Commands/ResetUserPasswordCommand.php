<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PasswordPolicy;
use App\Support\UserSessions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Restablece la contraseña de una cuenta y cierra sus sesiones abiertas (auditoría SE-01).
 */
class ResetUserPasswordCommand extends Command
{
    protected $signature = 'usuarios:restablecer {email : Correo de la cuenta}';

    protected $description = 'Cambia la contraseña de una cuenta y cierra sus sesiones abiertas.';

    public function handle(AuditLogger $audit): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No existe una cuenta con el correo {$email}.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Nueva contraseña (mínimo '.PasswordPolicy::MIN_LENGTH.' caracteres)');
        $confirmation = (string) $this->secret('Confirma la contraseña');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => [...PasswordPolicy::rules($email), 'confirmed']],
            PasswordPolicy::messages()
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $closed = UserSessions::closeAll($user);

        $audit->record('user.password_reset', $user, "Contraseña restablecida por consola: {$email}");

        $this->info("Contraseña actualizada para {$email}. Sesiones cerradas: {$closed}.");

        return self::SUCCESS;
    }
}
