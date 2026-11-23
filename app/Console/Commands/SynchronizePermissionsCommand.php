<?php

namespace App\Console\Commands;

use App\Support\Permissions;
use Illuminate\Console\Command;

class SynchronizePermissionsCommand extends Command
{
    protected $signature = 'permisos:sincronizar';

    protected $description = 'Agrega al catálogo de permisos las claves nuevas definidas en App\\Support\\Permissions';

    public function handle(): int
    {
        $added = Permissions::synchronize();

        $this->info($added > 0
            ? "Se agregaron {$added} permiso(s) al catálogo."
            : 'El catálogo de permisos ya estaba al día.');

        return self::SUCCESS;
    }
}
