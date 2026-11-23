<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Crea una cuenta del sistema. No hay registro público ni usuario por defecto (auditoría SE-01).
 */
class CreateUserCommand extends Command
{
    protected $signature = 'usuarios:crear {--perfil=Administrador : Nombre del perfil (Administrador, Consulta u otro creado en Administración)}';

    protected $description = 'Crea una cuenta para entrar al sistema (pide nombre, correo y contraseña).';

    public function handle(AuditLogger $audit): int
    {
        $profile = Profile::active()->where('name', (string) $this->option('perfil'))->first();
        if ($profile === null) {
            $this->error('No existe un perfil activo llamado "'.$this->option('perfil').'". Perfiles: '
                .Profile::active()->orderBy('name')->pluck('name')->implode(', '));

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Nombre completo'));
        $email = mb_strtolower(trim((string) $this->ask('Correo')));
        $password = (string) $this->secret('Contraseña (mínimo '.PasswordPolicy::MIN_LENGTH.' caracteres)');
        $confirmation = (string) $this->secret('Confirma la contraseña');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => [...PasswordPolicy::rules($email), 'confirmed'],
            ],
            [
                ...PasswordPolicy::messages(),
                'name.required' => 'Escribe el nombre.',
                'email.required' => 'Escribe el correo.',
                'email.email' => 'Escribe un correo válido.',
                'email.unique' => 'Ya existe una cuenta con ese correo.',
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'profile_id' => $profile->id,
        ]);

        $audit->record('user.created', $user, "Cuenta creada por consola: {$email}");

        $this->info("Cuenta creada: {$email} (perfil {$profile->name})");

        return self::SUCCESS;
    }
}
