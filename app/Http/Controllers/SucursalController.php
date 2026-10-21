<?php

namespace App\Http\Controllers;

use App\Models\Control;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Alta / edicion de sucursales (desde Configuracion) y cambio de sucursal de trabajo. */
class SucursalController extends Controller
{
    private function exigirDueno(Request $request): void
    {
        abort_unless($request->user()->hasPermission('encargados'), 403, 'Solo el dueño o quien administra encargados maneja las sucursales.');
    }

    public function cambiar(Request $request)
    {
        $u = $request->user();
        if ($u->id_sucursal) {
            throw new \DomainException('Estás asignado a una sucursal: no podés cambiarte de sucursal.');
        }
        $d = $request->validate(['id' => ['required', 'integer', Rule::exists('sucursales', 'id')->where('activa', 1)]]);
        $request->session()->put('sucursal_id', (int) $d['id']);

        return redirect()->route('inicio')->with('ok', 'Ahora estás trabajando en '.Sucursal::find($d['id'])->nombre.'.');
    }

    public function store(Request $request)
    {
        $this->exigirDueno($request);
        $d = $request->validate($this->reglas());
        $s = Sucursal::create($d + ['activa' => 1]);

        return $this->ok('Sucursal "'.$s->nombre.'" creada. Su stock arranca en 0: cargalo con una transferencia o desde cada producto.');
    }

    public function update(Request $request, Sucursal $sucursal)
    {
        $this->exigirDueno($request);
        $d = $request->validate($this->reglas($sucursal) + ['activa' => ['boolean']]);
        if (array_key_exists('activa', $d) && ! $d['activa']) {
            if ($sucursal->id === SucursalActual::CENTRAL) {
                throw new \DomainException('Casa Central no se puede dar de baja (podés cambiarle el nombre).');
            }
            if (Control::withoutGlobalScope('sucursal')->where('id_sucursal', $sucursal->id)->where('caja_abierta', 1)->exists()) {
                throw new \DomainException('La sucursal tiene la caja abierta: cerrala antes de darla de baja.');
            }
            User::where('id_sucursal', $sucursal->id)->update(['id_sucursal' => null]);
        }
        $sucursal->update($d);

        return $this->ok('Sucursal actualizada.');
    }

    private function reglas(?Sucursal $s = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:60', Rule::unique('sucursales', 'nombre')->ignore($s?->id)],
            'direccion' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:40'],
        ];
    }
}
