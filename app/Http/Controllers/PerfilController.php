<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class PerfilController extends Controller
{
    public function edit()
    {
        return Inertia::render('Perfil');
    }

    public function clave(Request $request)
    {
        $datos = $request->validate([
            'actual' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(6), 'different:actual'],
        ], [
            'actual.current_password' => 'La contraseña actual no es correcta.',
            'password.different' => 'La nueva contraseña tiene que ser distinta de la actual.',
        ], ['password' => 'nueva contraseña']);

        $u = $request->user();
        $u->password = $datos['password'];
        $u->save();

        // cierra las otras sesiones abiertas con la clave vieja
        Auth::logoutOtherDevices($datos['password']);

        return $this->ok('Contraseña actualizada.');
    }
}
