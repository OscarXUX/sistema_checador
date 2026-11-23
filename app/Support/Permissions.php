<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permisos del sistema checador, con la misma estructura que el sistema de tickets
 * (pedido de Oscar, 22/11/2026; auditoría SE-02).
 *
 * Esta lista es la fuente para el código: cada clave es un Gate (Gate::allows('empleados.agregar'),
 * middleware 'can:empleados.agregar', @can en Blade). La tabla `permissions` es el catálogo que ve el
 * administrador: ahí cambia la descripción o desactiva un permiso; las claves nuevas se agregan aquí y
 * llegan con `php artisan permisos:sincronizar` (o con una migración que llame a sincronizar()).
 *
 * Reglas (User::hasPermission):
 *  - Un perfil marcado como administrador tiene todos los permisos activos y administra los catálogos.
 *  - Los demás: permisos de su perfil + los que se le dan a la persona − los que se le niegan.
 *  - Por defecto nadie tiene ninguno. Un permiso desactivado en el catálogo no lo tiene nadie (salvo el administrador).
 */
final class Permissions
{
    public const MODULES = [
        'dashboard' => 'Dashboard',
        'empleados' => 'Empleados',
        'terminal' => 'Terminal Hikvision',
        'turnos' => 'Turnos',
        'kardex' => 'Kardex',
        'medios' => 'Medios de marcaje',
        'catalogos' => 'Catálogos',
    ];

    /** clave => [módulo, descripción, detalles] (en el orden en que se muestran) */
    public const LIST = [
        'dashboard.consultar' => ['dashboard', 'Consultar el dashboard', 'Ver indicadores, gráficas y el monitor de checadas.'],
        'empleados.consultar' => ['empleados', 'Consultar empleados', 'Ver la lista de empleados (RFC, CURP y NSS enmascarados).'],
        'empleados.agregar' => ['empleados', 'Agregar empleados', 'Dar de alta empleados.'],
        'empleados.actualizar' => ['empleados', 'Actualizar empleados', 'Editar datos, turno y estado de un empleado.'],
        'empleados.baja' => ['empleados', 'Dar de baja empleados', 'Dar de baja (o eliminar si no tiene checadas).'],
        'empleados.datos_personales' => ['empleados', 'Ver y editar RFC, CURP y NSS', 'Ver los datos completos al editar y cambiarlos. Cada consulta queda en la bitácora.'],
        'empleados.datos_salud' => ['empleados', 'Ver y editar tipo de sangre', 'Dato de salud (sensible, LFPDPPP). Sin este permiso solo se ve si está registrado. Cada consulta queda en la bitácora.'],
        'terminal.sincronizar' => ['terminal', 'Sincronizar checadas', 'Descargar a mano las checadas de la terminal.'],
        'terminal.usuarios' => ['terminal', 'Usuarios en la terminal', 'Dar de alta o de baja a un empleado en el reloj y registrar su tarjeta o PIN.'],
        'terminal.huellas' => ['terminal', 'Capturar huellas y rostro', 'Activar el lector y registrar la huella o el rostro de un empleado (datos biométricos).'],
        'turnos.consultar' => ['turnos', 'Consultar turnos', 'Ver los turnos y sus tolerancias.'],
        'turnos.administrar' => ['turnos', 'Administrar turnos', 'Crear y editar turnos (recalcula retardos y horas extra).'],
        'turnos.programar' => ['turnos', 'Programar turnos', 'Rotaciones, asignaciones por fecha y excepciones de turno (tarea O2 de Oscar).'],
        'kardex.consultar' => ['kardex', 'Consultar el kardex', 'Ver el resumen diario y la bitácora de checadas.'],
        'kardex.exportar' => ['kardex', 'Exportar el kardex', 'Descargar el kardex en Excel (una hoja por mes) o CSV.'],
        'kardex.corregir' => ['kardex', 'Corregir checadas', 'Indicar cómo debe contar una checada o que no cuenta, con motivo. La checada original no se modifica.'],
        'medios.consultar' => ['medios', 'Consultar medios de marcaje', 'Ver qué medios (huella, rostro, tarjeta, PIN) acepta la terminal.'],
        'medios.administrar' => ['medios', 'Administrar medios de marcaje', 'Detectar los medios de la terminal y activar o desactivar cada uno.'],
        'catalogos.consultar' => ['catalogos', 'Consultar catálogos', 'Ver las ventanas de horario por turno y los tipos de evento.'],
        'catalogos.administrar' => ['catalogos', 'Administrar catálogos', 'Cambiar ventanas de horario y tipos de evento (recalcula los resúmenes).'],
    ];

    /** Permisos del perfil "Consulta" que crea la migración */
    public const QUERY_PROFILE = ['dashboard.consultar', 'empleados.consultar', 'turnos.consultar', 'kardex.consultar'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::LIST);
    }

    /**
     * Da de alta en `permissions` las claves de LIST que falten y quita las que ya no existen.
     * No cambia descripción ni estado de las existentes: eso lo administra el catálogo.
     *
     * @return int cuántas claves se agregaron
     */
    public static function synchronize(): int
    {
        if (! Schema::hasTable('permissions')) {
            return 0;
        }

        $lstExisting = DB::table('permissions')->pluck('key')->all();
        $added = 0;
        $order = 0;

        foreach (self::LIST as $key => [$module, $description, $details]) {
            $order++;

            if (in_array($key, $lstExisting, true)) {
                DB::table('permissions')->where('key', $key)->update(['module' => $module, 'sort_order' => $order]);

                continue;
            }

            DB::table('permissions')->insert([
                'key' => $key,
                'module' => $module,
                'description' => $description,
                'details' => $details,
                'sort_order' => $order,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $added++;
        }

        // Claves que ya no existen en el código: se quitan junto con sus asignaciones (llaves con cascada)
        DB::table('permissions')->whereNotIn('key', self::keys())->delete();

        return $added;
    }
}
