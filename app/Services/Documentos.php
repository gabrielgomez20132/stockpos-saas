<?php

namespace App\Services;

use App\Http\Controllers\VentaController;
use App\Models\Product;
use App\Models\Sucursal;
use App\Models\User;
use App\Support\Stock;
use App\Support\SucursalActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lo comun a presupuestos y remitos: cliente, renglones, busqueda y datos del emisor. */
class Documentos
{
    public const MAX_ITEMS = 300;

    public static function reglas(): array
    {
        return [
            'id_cliente' => ['nullable', 'integer', Rule::exists('users', 'id')->where('id_uType', User::TIPO_CLIENTE)],
            'cliente_nombre' => ['nullable', 'string', 'max:100'],
            'cliente_doc' => ['nullable', 'string', 'max:20'],
            'cliente_direccion' => ['nullable', 'string', 'max:150'],
            'cliente_telefono' => ['nullable', 'string', 'max:40'],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'items.*.id_producto' => ['nullable', 'integer'],
            'items.*.codigo' => ['nullable', 'string', 'max:60'],
            'items.*.descripcion' => ['required', 'string', 'max:150'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.precio' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ];
    }

    public static function mensajes(): array
    {
        return [
            'items.required' => 'Agregá al menos un renglón.',
            'items.min' => 'Agregá al menos un renglón.',
            'items.*.descripcion.required' => 'Hay un renglón sin descripción.',
            'items.*.cantidad.gt' => 'Las cantidades tienen que ser mayores a 0.',
            'items.*.cantidad.required' => 'Hay un renglón sin cantidad.',
            'items.*.precio.required' => 'Hay un renglón sin precio.',
        ];
    }

    /** datos del cliente que quedan guardados en el documento (si se elige uno de la lista se completan solos) */
    public static function cliente(array $d): array
    {
        $c = ! empty($d['id_cliente']) && (int) $d['id_cliente'] !== User::ID_CLIENTE_GENERAL ? User::find($d['id_cliente']) : null;
        $limpio = fn ($k) => ($v = trim((string) ($d[$k] ?? ''))) === '' ? null : $v;

        return [
            'id_cliente' => $c?->id,
            'cliente_nombre' => $limpio('cliente_nombre') ?? $c?->nombre,
            'cliente_doc' => $limpio('cliente_doc') ?? ($c?->dni ? (string) $c->dni : null),
            'cliente_direccion' => $limpio('cliente_direccion') ?? $c?->direccion,
            'cliente_telefono' => $limpio('cliente_telefono') ?? $c?->telefono,
        ];
    }

    /**
     * Renglones limpios. Los que vienen de un producto se validan contra el
     * catalogo (que exista, que no sea un producto con variantes y que las
     * unidades vayan enteras); los demas son texto libre.
     *
     * @return array<int, array{id_producto:?int,codigo:?string,descripcion:string,cantidad:float,precio:float,orden:int,producto:?Product}>
     */
    public static function renglones(array $items, bool $bloquear = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($i) => (int) ($i['id_producto'] ?? 0), $items))));
        $q = Product::with('category')->whereIn('id', $ids);
        $productos = ($bloquear ? $q->lockForUpdate() : $q)->get()->keyBy('id');

        $out = [];
        foreach (array_values($items) as $n => $it) {
            $p = null;
            if (! empty($it['id_producto'])) {
                $p = $productos[(int) $it['id_producto']] ?? null;
                if (! $p || $p->tiene_variantes) {
                    throw new \DomainException('"'.$it['descripcion'].'" no existe más o es un producto con variantes (elegí la variante).');
                }
            }
            $cant = round((float) $it['cantidad'], 3);
            if ($p && ! $p->category?->esPorPeso() && abs($cant - round($cant)) > 1e-9) {
                throw new \DomainException($p->nombre.' va por unidad entera.');
            }
            $out[] = [
                'id_producto' => $p?->id,
                'codigo' => $p ? (string) $p->codigo : (($c = trim((string) ($it['codigo'] ?? ''))) === '' ? null : $c),
                'descripcion' => trim((string) $it['descripcion']),
                'cantidad' => $cant,
                'precio' => round((float) $it['precio'], 2),
                'orden' => $n,
                'producto' => $p,
            ];
        }

        return $out;
    }

    public static function subtotal(array $renglones): float
    {
        return round(array_sum(array_map(fn ($r) => round($r['cantidad'] * $r['precio'], 2), $renglones)), 2);
    }

    /** para guardar: sin el modelo del producto */
    public static function filas(array $renglones): array
    {
        return array_map(fn ($r) => array_diff_key($r, ['producto' => 1]), $renglones);
    }

    public static function buscarProductos(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        if ($q === '') {
            return response()->json([]);
        }
        $like = '%'.addcslashes($q, '%_\\').'%';
        $r = Product::with('category:id,unidad')->where('activo', 1)->where('tiene_variantes', 0)
            ->where(fn ($w) => $w->where('codigo', $q)->orWhere('nombre', 'like', $like)->orWhere('codigo', 'like', $like))
            ->orderByRaw('CASE WHEN codigo = ? THEN 0 ELSE 1 END', [$q])->orderBy('nombre')->limit(15)->get();
        Stock::cargar($r);

        return response()->json($r->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'codigo' => (string) $p->codigo, 'monto' => $p->monto,
            'quedan' => $p->quedan, 'unidad' => $p->category?->unidad, 'fraccionable' => (bool) $p->category?->esPorPeso()]));
    }

    public static function buscarClientes(Request $request)
    {
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 50);
        $like = '%'.addcslashes($q, '%_\\').'%';

        return response()->json(User::tipo(User::TIPO_CLIENTE)->where('activo', 1)->where('id', '!=', User::ID_CLIENTE_GENERAL)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', $like)->orWhere('dni', 'like', addcslashes($q, '%_\\').'%')))
            ->orderBy('nombre')->limit(20)->get(['id', 'nombre', 'dni', 'direccion', 'telefono']));
    }

    /** cabecera de la hoja: logo y datos del negocio, con la sucursal del documento */
    public static function emisor(int $idSucursal): array
    {
        $e = VentaController::emisor();
        $e['sucursal'] = SucursalActual::habilitado() ? Sucursal::find($idSucursal)?->only(['nombre', 'direccion', 'telefono']) : null;

        return $e;
    }

    public static function datosCliente($doc): array
    {
        return ['id' => $doc->id_cliente, 'nombre' => $doc->cliente_nombre, 'doc' => $doc->cliente_doc,
            'direccion' => $doc->cliente_direccion, 'telefono' => $doc->cliente_telefono];
    }

    public static function items($doc): array
    {
        return $doc->items->map(fn ($i) => ['id_producto' => $i->id_producto, 'codigo' => $i->codigo, 'descripcion' => $i->descripcion,
            'cantidad' => $i->cantidad, 'precio' => $i->precio, 'subtotal' => round($i->cantidad * $i->precio, 2)])->all();
    }
}
