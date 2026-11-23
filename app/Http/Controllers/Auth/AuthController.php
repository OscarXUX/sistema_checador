<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(Request $request): View
    {
        return view('auth.login', [
            'sessionExpired' => $request->boolean('expirada'),
        ]);
    }

    public function store(LoginRequest $request, AuditLogger $audit): RedirectResponse
    {
        $request->authenticate($audit);

        // Nuevo identificador de sesión al entrar (fijación de sesión)
        $request->session()->regenerate();

        $audit->record('auth.login', $request->user(), 'Inicio de sesión');

        return redirect()->intended(route($request->user()->homeRouteName()));
    }

    public function destroy(Request $request, AuditLogger $audit): RedirectResponse
    {
        $audit->record('auth.logout', $request->user(), 'Cierre de sesión');

        Auth::guard('web')->logout();

        // La sesión se invalida en el servidor y se genera un token CSRF nuevo
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Inicio de cada quien: manda a la primera pantalla que puede ver. Si no tiene ninguna,
     * se le explica en lugar de mostrar un 403.
     */
    public function home(Request $request): View|RedirectResponse
    {
        $home = $request->user()->homeRouteName();
        if ($home !== 'home') {
            return redirect()->route($home);
        }

        return view('auth.no-permissions');
    }
}
