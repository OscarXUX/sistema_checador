<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Foto del empleado (nota 16). Se guarda fuera de la carpeta pública con nombre aleatorio, se
 * vuelve a codificar como JPEG de máximo 600 px (quita metadatos como la ubicación GPS) y solo se
 * entrega por esta ruta, que revisa el permiso (guía de seguridad 4.6).
 */
class EmployeePhotoController extends Controller
{
    private const MAX_SIDE = 600;

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(Employee $employee): Response
    {
        $disk = Storage::disk(Employee::PHOTO_DISK);
        if ($employee->photo_path === null || ! $disk->exists($employee->photo_path)) {
            abort(404);
        }

        return response($disk->get($employee->photo_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="empleado-'.$employee->id.'.jpg"',
        ]);
    }

    public function store(Request $request, Employee $employee): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:max_width=8000,max_height=8000'],
        ], [
            'photo.required' => 'Elige una foto.',
            'photo.image' => 'El archivo debe ser una imagen.',
            'photo.mimes' => 'La foto debe ser JPG, PNG o WebP.',
            'photo.max' => 'La foto no debe pasar de 4 MB.',
            'photo.dimensions' => 'La foto es demasiado grande (máximo 8000 px por lado).',
        ]);

        $jpeg = $this->reencode($request->file('photo'));
        if ($jpeg === null) {
            return response()->json(['success' => false, 'message' => 'No se pudo leer la imagen. Prueba con otra foto en JPG o PNG.'], 422);
        }

        $disk = Storage::disk(Employee::PHOTO_DISK);
        $path = Employee::PHOTO_DIR.'/'.Str::random(40).'.jpg';
        $disk->put($path, $jpeg);

        $previous = $employee->photo_path;
        $employee->forceFill(['photo_path' => $path])->save();
        if ($previous !== null) {
            $disk->delete($previous);
        }

        $this->audit->record('employee.photo_updated', $employee, "Foto del empleado #{$employee->hikvision_employee_no} ".($previous === null ? 'agregada' : 'cambiada'));

        return response()->json([
            'success' => true,
            'message' => 'Foto guardada.',
            'photo_url' => route('employees.photo', ['employee' => $employee->id, 'v' => substr(md5($path), 0, 8)]),
        ]);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        if ($employee->photo_path === null) {
            return response()->json(['success' => false, 'message' => 'El empleado no tiene foto.'], 404);
        }

        Storage::disk(Employee::PHOTO_DISK)->delete($employee->photo_path);
        $employee->forceFill(['photo_path' => null])->save();
        $this->audit->record('employee.photo_removed', $employee, "Foto del empleado #{$employee->hikvision_employee_no} eliminada");

        return response()->json(['success' => true, 'message' => 'Foto eliminada.']);
    }

    /**
     * JPEG nuevo de máximo 600 px por lado, sin metadatos. Null si la imagen no se puede leer.
     */
    private function reencode(UploadedFile $file): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            // Sin GD no se puede limpiar: se rechaza en lugar de guardar el archivo tal cual
            return null;
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($target, null, 85);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $jpeg !== '' ? $jpeg : null;
    }
}
