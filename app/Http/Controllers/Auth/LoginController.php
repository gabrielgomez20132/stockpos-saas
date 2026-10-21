<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LoginController extends Controller
{
    public function show()
    {
        return Inertia::render('Auth/Login');
    }

    /** Se entra con email o con DNI (igual que control-soft). */
    public function login(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:200'],
            'recordar' => ['boolean'],
        ], [], ['usuario' => 'email o DNI', 'password' => 'contraseña']);

        $campo = ctype_digit($datos['usuario']) ? 'dni' : 'email';
        $cred = [$campo => $datos['usuario'], 'password' => $datos['password'], 'activo' => 1, 'id_uType' => User::TIPO_ENCARGADO];

        if (! Auth::attempt($cred, (bool) ($datos['recordar'] ?? false))) {
            throw ValidationException::withMessages(['usuario' => 'Usuario o contraseña incorrectos.']);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('inicio'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
