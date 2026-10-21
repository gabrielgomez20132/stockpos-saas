<?php

namespace App\Http\Controllers;

use App\Models\Afip;
use App\Models\AfipConfig;
use App\Models\Order;
use App\Models\OrderProduct;
use App\Models\OrderService;
use App\Services\Afip\Facturador;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class AfipController extends Controller
{
    /** Libro de comprobantes: los no "pagados" (rendidos) o los de un rango. */
    public function index(Request $request)
    {
        $rango = $this->rango($request);
        $q = Afip::query()->orderByDesc('id');
        $rango ? $q->whereBetween('cbteFch', $rango) : $q->where('pagada', 0);
        $registros = $q->limit(2000)->get();

        $cfg = AfipConfig::actual();

        return Inertia::render('Afip/Index', [
            'registros' => $registros->map(fn (Afip $a) => $a->only(['id', 'cbteFch', 'tipoCbteNum', 'nroCbte', 'caeNum', 'docTipo', 'docNro', 'nombreRS', 'impNeto', 'impIVA', 'impTotal', 'pagada', 'nroCbteAsoc'])
                + ['letra' => $a->letra(), 'ndc' => $a->esNotaCredito()]),
            'totales' => Afip::totales($registros),
            'filtro' => $rango ? ['desde' => $rango[0]->toDateString(), 'hasta' => $rango[1]->toDateString()] : null,
            'config' => ['listo' => $cfg->listo(), 'ambiente' => $cfg->ambiente, 'soap' => extension_loaded('soap')],
        ]);
    }

    public function marcarPagadas()
    {
        $n = Afip::where('pagada', 0)->update(['pagada' => 1]);

        return $this->ok($n.' comprobantes marcados como rendidos.');
    }

    public function facturar(Request $request, Order $order)
    {
        $d = $request->validate([
            'tipo' => ['required', 'in:A,B,C'],
            'tipo_pago' => ['required', 'in:Efectivo,Tarjeta'],
            'concepto' => ['required', 'in:Detalle,Varios'],
            'nombre' => ['nullable', 'string', 'max:35', 'required_with:cuit'],
            'cuit' => ['nullable', 'string', 'max:15'],
            'detalle_opcional' => ['nullable', 'string', 'max:100', 'required_with:monto_opcional'],
            'monto_opcional' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [], ['nombre' => 'nombre / razón social', 'detalle_opcional' => 'detalle adicional']);

        try {
            $afip = (new Facturador())->facturar($order, $d);
        } catch (\RuntimeException $e) {
            Log::warning('AFIP factura venta '.$order->id.': '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Factura '.$afip->letra().' '.$afip->nroCbte.' autorizada (CAE '.$afip->caeNum.').')
            ->with('abrir', route('afip.ver', $afip->id));
    }

    public function notaCredito(Order $order)
    {
        try {
            $ndc = (new Facturador())->notaCredito($order);
        } catch (\RuntimeException $e) {
            Log::warning('AFIP NC venta '.$order->id.': '.$e->getMessage());

            return back()->with('error', $e->getMessage());
        }

        return back()->with('ok', 'Nota de crédito '.$ndc->nroCbte.' autorizada. La venta quedó anulada y el stock volvió.')
            ->with('abrir', route('afip.ver', $ndc->id));
    }

    /** Comprobante para imprimir, con el QR obligatorio de AFIP. */
    public function ver(Afip $afip)
    {
        $order = Order::where($afip->esNotaCredito() ? 'idAfipNdc' : 'idAfipFct', $afip->id)->first();
        $cfg = AfipConfig::actual();

        $items = [];
        if ($order && $afip->concepto !== 'V') {
            $items = OrderProduct::with('producto:id,nombre')->where('id_order', $order->id)->get()
                ->map(fn ($p) => ['nombre' => $p->producto?->nombre ?? '—', 'cantidad' => $p->cantidad, 'precio' => $p->monto, 'subtotal' => $p->subtotal()])
                ->concat(OrderService::where('id_order', $order->id)->get()
                    ->map(fn ($s) => ['nombre' => $s->description, 'cantidad' => 1, 'precio' => $s->monto, 'subtotal' => ceil(round($s->monto, 4))]))
                ->all();
        }

        $qrUrl = Facturador::urlQr($afip, $cfg);
        $qr = (new QRCode(new QROptions(['scale' => 4, 'addQuietzone' => true])))->render($qrUrl);

        return Inertia::render('Print/Comprobante', [
            'cbte' => $afip->only(['id', 'cbteFch', 'tipoCbteNum', 'nroCbte', 'caeNum', 'caeFvt', 'docTipo', 'docNro', 'nombreRS', 'tipoPago',
                'impNeto', 'impIVA', 'descuento', 'impTotal', 'nroCbteAsoc', 'detalleOpcional', 'montoOpcional', 'concepto'])
                + ['letra' => $afip->letra(), 'ndc' => $afip->esNotaCredito(), 'venta' => $order?->id],
            'items' => $items,
            'emisor' => VentaController::emisor(),
            'qr' => $qr,
            'qrUrl' => $qrUrl,
        ]);
    }
}
