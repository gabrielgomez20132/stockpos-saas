<?php

namespace App\Services\Afip;

use App\Models\Afip;
use App\Models\AfipConfig;
use App\Models\Deuda;
use App\Models\Devolucion;
use App\Models\Order;
use App\Models\Product;
use App\Models\Vale;
use Illuminate\Support\Facades\DB;

/**
 * Emite facturas y notas de credito de una venta. Mismas reglas que
 * AfipController@generarCbte de control-soft, con estas diferencias:
 *  - los importes de la nota de credito salen de la factura guardada, no del
 *    formulario (antes se podian editar desde el navegador);
 *  - si AFIP falla no se toca la venta y se muestra el motivo;
 *  - la NC no devuelve stock de lo que ya se devolvio con una devolucion.
 */
class Facturador
{
    public function __construct(private ?AfipConfig $cfg = null)
    {
        $this->cfg ??= AfipConfig::actual();
    }

    /**
     * @param array{tipo:string, tipo_pago:string, concepto:string, nombre:?string, cuit:?string,
     *              detalle_opcional:?string, monto_opcional:?float} $d
     */
    public function facturar(Order $order, array $d, ?Wsfe $ws = null): Afip
    {
        $ws ?? $this->verificarListo();
        if (! $order->completada) {
            throw new \DomainException('Primero cobrá la venta.');
        }
        if ($order->idAfipFct) {
            throw new \DomainException('Esta venta ya tiene factura.');
        }

        $letra = $this->cfg->esMonotributo() ? 'C' : strtoupper($d['tipo']);
        if (! in_array($letra, ['A', 'B', 'C'], true)) {
            throw new \DomainException('Tipo de factura inválido.');
        }

        $cuit = preg_replace('/\D/', '', (string) ($d['cuit'] ?? ''));
        if ($cuit !== '' && strlen($cuit) !== 11) {
            throw new \DomainException('El CUIT del cliente tiene que tener 11 números.');
        }
        $docTipo = $cuit !== '' ? 80 : 99;
        if ($letra === 'A' && $docTipo !== 80) {
            throw new \DomainException('La factura A necesita el CUIT del cliente.');
        }

        $montoOpc = round(max(0, (float) ($d['monto_opcional'] ?? 0)), 2);
        $detalleOpc = $montoOpc > 0 ? trim((string) ($d['detalle_opcional'] ?? '')) : null;
        $total = round($order->monto - $order->descuento + $montoOpc, 2);
        if ($total <= 0) {
            throw new \DomainException('El total a facturar tiene que ser mayor a cero.');
        }

        [$neto, $iva] = $letra === 'C' ? [$total, 0.0] : [round($total - round($total * 21 / 121, 2), 2), round($total * 21 / 121, 2)];
        $tipoNum = ['A' => Afip::FACTURA_A, 'B' => Afip::FACTURA_B, 'C' => Afip::FACTURA_C][$letra];
        $condIva = $letra === 'A' ? 1 : ($docTipo === 80 ? 6 : 5);

        $afip = $this->emitir($tipoNum, $docTipo, $cuit === '' ? 0 : (int) $cuit, $neto, $iva, $total, $condIva, null, $ws, [
            'nombreRS' => $d['nombre'] ?? null,
            'tipoPago' => ($d['tipo_pago'] ?? 'Efectivo') === 'Efectivo' ? 'EFTV' : 'TRJT',
            'descuento' => $order->descuento ?? 0,
            'detalleOpcional' => $detalleOpc,
            'montoOpcional' => $montoOpc ?: null,
            'concepto' => ($d['concepto'] ?? 'Detalle') === 'Varios' ? 'V' : 'D',
        ]);

        $order->idAfipFct = $afip->id;
        $order->save();

        return $afip;
    }

    /** Nota de credito por el total de la factura: anula la venta. */
    public function notaCredito(Order $order, ?Wsfe $ws = null): Afip
    {
        $ws ?? $this->verificarListo();
        $fct = $order->idAfipFct ? Afip::find($order->idAfipFct) : null;
        if (! $fct) {
            throw new \DomainException('La venta no tiene factura.');
        }
        if ($order->idAfipNdc) {
            throw new \DomainException('La venta ya tiene nota de crédito.');
        }
        if (Devolucion::where('id_order_original', $order->id)->exists()) {
            throw new \DomainException('La venta tiene devoluciones con vale: no se puede anular entera con una nota de crédito.');
        }

        $tipoNum = ['A' => Afip::NDC_A, 'B' => Afip::NDC_B, 'C' => Afip::NDC_C][$fct->letra()];
        [$pv, $nro] = array_map('intval', explode('-', $fct->nroCbte) + [0, 0]);
        $condIva = $fct->letra() === 'A' ? 1 : ($fct->docTipo === 80 ? 6 : 5);
        $neto = $fct->letra() === 'C' ? $fct->impTotal : $fct->impNeto;

        $ndc = $this->emitir($tipoNum, $fct->docTipo, (int) $fct->docNro, $neto, $fct->impIVA, $fct->impTotal, $condIva,
            ['Tipo' => $fct->tipoCbteNum, 'PtoVta' => $pv, 'Nro' => $nro, 'Cuit' => $this->cfg->cuitDigitos()], $ws, [
                'nombreRS' => $fct->nombreRS,
                'tipoPago' => $fct->tipoPago,
                'descuento' => 0,
                'nroCbteAsoc' => $fct->nroCbte,
                'concepto' => 'D',
            ]);

        // anula la venta: stock de vuelta, sin deuda, importes en cero
        DB::transaction(function () use ($order, $ndc) {
            foreach ($order->productos as $p) {
                \App\Support\Stock::sumar($p->id_producto, $p->cantidad, (int) $order->id_sucursal);
            }
            if ($order->id_deuda) {
                Deuda::destroy($order->id_deuda);
            }
            foreach ($order->vales as $vale) {
                $vale->monto_disponible = round($vale->monto_disponible + $vale->pivot->monto_aplicado, 2);
                $vale->monto_usado = max(0, round($vale->monto_usado - $vale->pivot->monto_aplicado, 2));
                $vale->activo = true;
                $vale->save();
            }
            $order->fill(array_fill_keys(Order::COLUMNAS_PAGO, 0) + [
                'monto' => 0, 'fiado' => 0, 'descuento' => 0, 'id_deuda' => 0, 'idAfipNdc' => $ndc->id,
            ])->save();
        });

        return $ndc;
    }

    private function emitir(int $tipo, int $docTipo, int $docNro, float $neto, float $iva, float $total, int $condIva, ?array $asoc, ?Wsfe $ws, array $extra): Afip
    {
        $ws ??= new Wsfe($this->cfg);
        $pv = (int) $this->cfg->punto_venta;

        $det = [
            'Concepto' => 1, 'DocTipo' => $docTipo, 'DocNro' => $docNro, 'CondicionIVAReceptorId' => $condIva,
            'CbteFch' => now('America/Argentina/Buenos_Aires')->format('Ymd'),
            'ImpTotal' => $total, 'ImpTotConc' => 0, 'ImpNeto' => $neto, 'ImpOpEx' => 0, 'ImpTrib' => 0, 'ImpIVA' => $iva,
            'MonId' => 'PES', 'MonCotiz' => 1,
        ];
        if ($asoc) {
            $det['CbtesAsoc'] = ['CbteAsoc' => $asoc];
        }
        if ($iva > 0) {
            $det['Iva'] = ['AlicIva' => ['Id' => 5, 'BaseImp' => $neto, 'Importe' => $iva]]; // 21%
        }

        $r = $ws->solicitarCae($pv, $tipo, $det);

        $nroCbte = str_pad((string) $pv, 5, '0', STR_PAD_LEFT).'-'.str_pad((string) $r['numero'], 8, '0', STR_PAD_LEFT);

        return Afip::create($extra + [
            'cbteFch' => now()->toDateTimeString(),
            'tipoCbteNum' => $tipo,
            'nroCbte' => $nroCbte,
            'caeNum' => $r['cae'],
            'caeFvt' => date('Y-m-d', strtotime($r['vto'])),
            'docTipo' => $docTipo,
            'docNro' => (string) $docNro,
            'impNeto' => $tipo === Afip::FACTURA_C || $tipo === Afip::NDC_C ? 0 : $neto,
            'impIVA' => $iva,
            'impTotal' => $total,
            'pagada' => 0,
            'codigoBarra' => substr($this->cfg->cuitDigitos().str_pad((string) $tipo, 3, '0', STR_PAD_LEFT)
                .str_pad((string) $pv, 5, '0', STR_PAD_LEFT).$r['cae'].$r['vto'], 0, 45),
        ]);
    }

    private function verificarListo(): void
    {
        if (! $this->cfg->listo()) {
            throw new \DomainException('Todavía no se puede facturar: completá la Configuración AFIP (datos fiscales, certificado y clave) y activá la extensión SOAP de PHP.');
        }
    }

    /** URL del QR obligatorio (RG 4892) para el comprobante. */
    public static function urlQr(Afip $a, AfipConfig $cfg): string
    {
        [$pv, $nro] = array_map('intval', explode('-', $a->nroCbte) + [0, 0]);
        $datos = [
            'ver' => 1,
            'fecha' => date('Y-m-d', strtotime((string) $a->cbteFch)),
            'cuit' => (int) $cfg->cuitDigitos(),
            'ptoVta' => $pv,
            'tipoCmp' => $a->tipoCbteNum,
            'nroCmp' => $nro,
            'importe' => round($a->impTotal, 2),
            'moneda' => 'PES',
            'ctz' => 1,
            'tipoDocRec' => $a->docTipo,
            'nroDocRec' => (int) $a->docNro,
            'tipoCodAut' => 'E',
            'codAut' => (int) $a->caeNum,
        ];

        return 'https://www.afip.gob.ar/fe/qr/?p='.base64_encode(json_encode($datos));
    }
}
