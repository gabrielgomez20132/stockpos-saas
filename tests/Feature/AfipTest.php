<?php

namespace Tests\Feature;

use App\Models\Afip;
use App\Models\AfipConfig;
use App\Models\Order;
use App\Services\Afip\Facturador;
use App\Services\Afip\Wsfe;
use Tests\TestCase;

class WsfeFalso extends Wsfe
{
    public array $pedidos = [];

    public function __construct()
    {
    }

    public function solicitarCae(int $ptoVta, int $tipo, array $det): array
    {
        $this->pedidos[] = compact('ptoVta', 'tipo', 'det');

        return ['cae' => '70417054367476', 'vto' => '20261030', 'numero' => count($this->pedidos)];
    }
}

class AfipTest extends TestCase
{
    public function test_factura_b_y_nota_de_credito(): void
    {
        $this->actingAs($this->admin);
        $cfg = AfipConfig::create(['ambiente' => 'homologacion', 'cuit' => '20123456789', 'punto_venta' => 3, 'razon_social' => 'Kiosco', 'condicion_iva' => 'Responsable inscripto']);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1210, 'quedan' => 5]);
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 1]);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']]);

        $ws = new WsfeFalso();
        $f = (new Facturador($cfg))->facturar($o->fresh(), ['tipo' => 'B', 'tipo_pago' => 'Efectivo', 'concepto' => 'Detalle'], $ws);
        $this->assertSame(6, $ws->pedidos[0]['tipo']);
        $this->assertEquals(1210, $ws->pedidos[0]['det']['ImpTotal']);
        $this->assertEquals(210, $ws->pedidos[0]['det']['ImpIVA']);
        $this->assertEquals(1000, $ws->pedidos[0]['det']['ImpNeto']);
        $this->assertSame('00003-00000001', $f->nroCbte);
        $this->assertSame($f->id, $o->fresh()->idAfipFct);

        // no se factura dos veces
        $this->expectException(\DomainException::class);
        try {
            (new Facturador($cfg))->facturar($o->fresh(), ['tipo' => 'B', 'tipo_pago' => 'Efectivo', 'concepto' => 'Detalle'], $ws);
        } finally {
            $nc = (new Facturador($cfg))->notaCredito($o->fresh(), $ws);
            $this->assertSame(8, $ws->pedidos[1]['tipo']);
            $this->assertSame(['Tipo' => 6, 'PtoVta' => 3, 'Nro' => 1, 'Cuit' => '20123456789'], $ws->pedidos[1]['det']['CbtesAsoc']['CbteAsoc']);
            $o->refresh();
            $this->assertEquals(0, $o->monto);
            $this->assertSame($nc->id, $o->idAfipNdc);
            $this->assertEquals(5, $p->fresh()->quedan);
            $this->get("/afip/comprobante/{$f->id}")->assertOk();
            $this->assertStringStartsWith('https://www.afip.gob.ar/fe/qr/?p=', Facturador::urlQr($f, $cfg));
            $this->assertSame(2, Afip::count());
        }
    }

    public function test_monotributo_emite_c_sin_iva_y_a_requiere_cuit(): void
    {
        $cfg = AfipConfig::create(['ambiente' => 'homologacion', 'cuit' => '20123456789', 'punto_venta' => 1, 'razon_social' => 'K', 'condicion_iva' => 'Monotributo']);
        $o = Order::create(['id_encargado' => 1, 'id_cliente' => 2, 'id_type' => 1, 'canal_id' => 1, 'id_forma_pago' => 1, 'monto' => 500, 'completada' => 1, 'deHoy' => 1]);
        $ws = new WsfeFalso();
        (new Facturador($cfg))->facturar($o, ['tipo' => 'A', 'tipo_pago' => 'Efectivo', 'concepto' => 'Varios'], $ws);
        $this->assertSame(11, $ws->pedidos[0]['tipo']);
        $this->assertArrayNotHasKey('Iva', $ws->pedidos[0]['det']);

        $cfg->update(['condicion_iva' => 'Responsable inscripto']);
        $o2 = Order::create(['id_encargado' => 1, 'id_cliente' => 2, 'id_type' => 1, 'canal_id' => 1, 'id_forma_pago' => 1, 'monto' => 500, 'completada' => 1, 'deHoy' => 1]);
        $this->expectException(\DomainException::class);
        (new Facturador($cfg->fresh()))->facturar($o2, ['tipo' => 'A', 'tipo_pago' => 'Efectivo', 'concepto' => 'Varios'], $ws);
    }

    public function test_config_afip_genera_csr_y_valida_credenciales(): void
    {
        $this->actingAs($this->admin);
        $this->put('/afip/configuracion', ['ambiente' => 'homologacion', 'cuit' => '20-12345678-9', 'punto_venta' => 2, 'razon_social' => 'Kiosco'])->assertSessionHasNoErrors();
        @unlink(AfipConfig::first()->rutaKey('homologacion'));
        $r = $this->post('/afip/configuracion/homologacion/csr')->assertOk();
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $r->getContent());
        $cfg = AfipConfig::first();
        $this->assertFileExists($cfg->rutaKey('homologacion'));
        // un archivo cualquiera como certificado se rechaza
        $this->post('/afip/configuracion/homologacion/credenciales', ['certificado' => \Illuminate\Http\UploadedFile::fake()->createWithContent('a.crt', 'basura')])->assertSessionHas('error');
        // la clave no se sirve por web
        $this->assertStringNotContainsString('PRIVATE KEY', $this->get('/storage/app/private/afip/homologacion.key')->getContent());
        @unlink($cfg->rutaKey('homologacion'));
    }
}
