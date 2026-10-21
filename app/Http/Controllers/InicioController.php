<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Services\Caja;
use Illuminate\Http\Request;
use App\Support\Stock;
use Inertia\Inertia;

class InicioController extends Controller
{
    public function __invoke(Request $request, Caja $caja)
    {
        $u = $request->user();
        $abierta = $caja->abierta();

        $turno = null;
        if ($abierta) {
            $d = $caja->datosTurno();
            $turno = [
                'ventas' => $d['cant_ventas'],
                'total' => round($d['ingXmercaderias'] - $d['descuentos'], 2),
                'efectivo' => $d['total_efec'],
                'pendientes' => Order::delTurno()->where('completada', 0)->where('monto', '>', 0)->count(),
                'ganancia' => $u->hasPermission('control') ? round($d['ganXmercaderias'] + $d['ganXservicios'], 2) : null,
            ];
        }

        $col = Stock::columna();
        $bajo = fn () => Stock::conStock(Product::query())->where('products.activo', 1)->where('products.tiene_variantes', 0)->whereRaw($col.' <= products.aviso');
        $stockBajo = $u->hasPermission('productos')
            ? $bajo()->with('category:id,unidad')->orderByRaw($col)->limit(12)->get()
                ->map(fn ($p) => $p->only(['id', 'nombre', 'codigo', 'quedan', 'aviso', 'id_categoria', 'archivo', 'imagen_url']) + ['category' => $p->category])
            : [];

        $ultimas = Order::with('cliente:id,nombre')->delTurno()->where('completada', 1)
            ->latest('id')->limit(6)->get(['id', 'id_cliente', 'monto', 'descuento', 'created_at', 'id_forma_pago']);

        return Inertia::render('Inicio', [
            'turno' => $turno,
            'stockBajo' => $stockBajo,
            'cantStockBajo' => $u->hasPermission('productos') ? $bajo()->count() : 0,
            'ultimas' => $ultimas,
        ]);
    }
}
