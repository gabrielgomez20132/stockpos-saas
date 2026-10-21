<?php

namespace Tests\Feature;

use App\Models\CierreCaja;
use App\Models\Control;
use App\Models\Deuda;
use App\Models\Order;
use App\Models\Vale;
use App\Support\FormasPago;
use Tests\TestCase;

class VentasTest extends TestCase
{
    private function nuevaVenta(): Order
    {
        $this->post('/ventas')->assertRedirect();

        return Order::latest('id')->first();
    }

    private function agregar(Order $o, $p, $cant = 1)
    {
        return $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => $cant]);
    }

    public function test_no_se_vende_con_la_caja_cerrada(): void
    {
        $this->actingAs($this->admin);
        $this->post('/ventas')->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    public function test_venta_completa_en_efectivo_descuenta_stock_y_suma_al_turno(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(500);
        $p = $this->producto(['monto' => 1000, 'costo' => 600, 'quedan' => 10]);
        $o = $this->nuevaVenta();

        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $p->codigo, 'cantidad' => '3'])->assertOk()->assertJsonPath('monto', 3000);
        $this->assertEquals(7, $p->fresh()->quedan);

        // el cliente intenta mandar montos y "completada" a mano: se ignoran
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo'], 'pago' => 5000, 'monto' => 1, 'completada' => 1, 'pago_efec' => 1])
            ->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertTrue($o->completada);
        $this->assertEquals(3000, $o->monto);
        $this->assertEquals(3000, $o->pago_efec);
        $this->assertSame(FormasPago::EFECTIVO, $o->id_forma_pago);

        $this->get('/caja/movimientos')->assertInertia(fn ($pg) => $pg->where('datos.total_efec', 3500)->where('datos.ganXmercaderias', 1200)->etc());
    }

    public function test_no_se_puede_vender_mas_que_el_stock_ni_fracciones_de_unidades(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['quedan' => 2]);
        $o = $this->nuevaVenta();
        $this->agregar($o, $p, 3)->assertStatus(422);
        $this->agregar($o, $p, 0.5)->assertStatus(422);
        $this->agregar($o, $p, -1)->assertStatus(422);
        $kg = $this->producto(['_cat' => 'Fiambrería', '_unidad' => 'Kg', 'monto' => 10000, 'quedan' => 5]);
        $this->agregar($o, $kg, '0,250')->assertOk()->assertJsonPath('monto', 2500);
        $this->assertEquals(4.75, $kg->fresh()->quedan);
    }

    public function test_editar_cantidad_y_quitar_devuelve_stock(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['quedan' => 10]);
        $o = $this->nuevaVenta();
        $item = $this->agregar($o, $p, 2)->json('items.0.id');
        $this->putJson("/ventas/items/{$item}", ['cantidad' => 5])->assertOk()->assertJsonPath('monto', 5000);
        $this->assertEquals(5, $p->fresh()->quedan);
        $this->putJson("/ventas/items/{$item}", ['cantidad' => 20])->assertStatus(422);
        $this->deleteJson("/ventas/items/{$item}")->assertOk()->assertJsonPath('monto', 0);
        $this->assertEquals(10, $p->fresh()->quedan);
    }

    public function test_venta_cobrada_no_se_puede_tocar_ni_cobrar_dos_veces_ni_anular(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto();
        $o = $this->nuevaVenta();
        $item = $this->agregar($o, $p, 1)->json('items.0.id');
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']]);

        $this->agregar($o, $p, 1)->assertStatus(422);
        $this->deleteJson("/ventas/items/{$item}")->assertStatus(422);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHas('error');
        $this->delete("/ventas/{$o->id}")->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $o->id]);
        $this->assertEquals(9, $p->fresh()->quedan);
    }

    public function test_pago_mixto_y_descuento(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1000]);
        $o = $this->nuevaVenta();
        $this->agregar($o, $p, 4);
        // 4000 - 10% (400) - 100 = 3500 ; 2000 efectivo + 1500 debito
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo', 'debito'],
            'descuento_pct' => 10, 'descuento_monto' => 100, 'monto_primero' => 2000])->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertEquals(500, $o->descuento);
        $this->assertEquals(2000, $o->pago_efec);
        $this->assertEquals(1500, $o->pago_tarj_debito);
        $this->assertSame(3, $o->id_forma_pago); // Efectivo/Tarjeta Débito
    }

    public function test_cliente_general_no_puede_quedar_debiendo(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $o = $this->nuevaVenta();
        $this->agregar($o, $this->producto(), 1);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo'], 'pago' => 500])->assertSessionHas('error');
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['fiado']])->assertSessionHas('error');
        $this->assertFalse($o->fresh()->completada);
    }

    public function test_fiado_parcial_y_cuenta_corriente_con_descuento(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $cli = $this->cliente();
        $p = $this->producto(['monto' => 1000]);

        $o = $this->nuevaVenta();
        $this->agregar($o, $p, 2);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => $cli->id, 'canal_id' => 1, 'medios' => ['efectivo'], 'pago' => 1500]);
        $this->assertEquals(500, $o->fresh()->fiado);

        $o2 = $this->nuevaVenta();
        $this->agregar($o2, $p, 1);
        $this->post("/ventas/{$o2->id}/cobrar", ['id_cliente' => $cli->id, 'canal_id' => 1, 'medios' => ['fiado'], 'descuento_monto' => 200]);
        $this->assertEquals(800, $o2->fresh()->fiado); // control-soft fiaba 1000 ignorando el descuento
        $this->assertEquals(1300, Deuda::saldo('cliente', $cli->id));

        // cobro de deuda: no mas que lo que debe, y suma al efectivo
        $this->post("/clientes/{$cli->id}/pago", ['monto' => 5000])->assertSessionHasErrors('monto');
        $this->post("/clientes/{$cli->id}/pago", ['monto' => 300])->assertSessionHasNoErrors();
        $this->assertEquals(1000, Deuda::saldo('cliente', $cli->id));
        $this->get('/caja/movimientos')->assertInertia(fn ($pg) => $pg->where('datos.total_efec', 1000 + 1500 + 300)->where('datos.fiado', 1300)->etc());
    }

    public function test_pago_con_vale_y_resto_en_efectivo(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(0);
        $vale = Vale::create(['codigo_vale' => 'VALE-20260101-0001', 'id_cliente' => 2, 'monto_original' => 1400, 'monto_usado' => 0,
            'monto_disponible' => 1400, 'fecha_emision' => now(), 'fecha_vencimiento' => now()->addDays(10), 'activo' => 1]);
        $o = $this->nuevaVenta();
        $this->agregar($o, $this->producto(['monto' => 2700]), 1);

        $this->getJson('/buscar/vales/codigo?codigo=0001')->assertOk()->assertJsonPath('id', $vale->id);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['vale'], 'id_vale' => $vale->id])->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertEquals(1400, $o->pago_vale);
        $this->assertEquals(1300, $o->pago_efec);
        $this->assertSame(29, $o->id_forma_pago); // Efectivo/Vale
        $vale->refresh();
        $this->assertEquals(0, $vale->monto_disponible);
        $this->assertFalse($vale->activo);
        $this->get('/caja/movimientos')->assertInertia(fn ($pg) => $pg->where('datos.total_efec', 1300)->where('datos.total_vale', 1400)->etc());

        // un vale vencido no se acepta
        $venc = Vale::create(['codigo_vale' => 'VALE-X', 'id_cliente' => 2, 'monto_original' => 100, 'monto_disponible' => 100,
            'fecha_emision' => now()->subDays(40), 'fecha_vencimiento' => now()->subDays(10), 'activo' => 1]);
        $o2 = $this->nuevaVenta();
        $this->agregar($o2, $this->producto(), 1);
        $this->post("/ventas/{$o2->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['vale'], 'id_vale' => $venc->id])->assertSessionHas('error');
    }

    public function test_anular_venta_abierta_devuelve_stock(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['quedan' => 5]);
        $o = $this->nuevaVenta();
        $this->agregar($o, $p, 3);
        $this->delete("/ventas/{$o->id}")->assertRedirect('/ventas');
        $this->assertEquals(5, $p->fresh()->quedan);
        $this->assertDatabaseMissing('orders', ['id' => $o->id]);
    }

    public function test_cada_venta_es_un_registro_nuevo_con_fecha_actual(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $a = $this->nuevaVenta();
        \App\Models\Order::whereKey($a->id)->update(['created_at' => '2026-01-01 10:00:00']);
        $b = $this->nuevaVenta();
        $this->assertNotSame($a->id, $b->id);
        $this->assertTrue($b->created_at->isToday());
    }

    public function test_cierre_de_caja_guarda_foto_y_pasa_pendientes_al_turno_siguiente(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(1000);
        $p = $this->producto(['monto' => 1000]);
        $o = $this->nuevaVenta();
        $this->agregar($o, $p, 2);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['qr']]);
        $this->post('/caja/movimientos', ['id_desc' => Control::RETIRO, 'monto' => 300, 'detalle' => 'dueño']);
        $this->post('/caja/movimientos', ['id_desc' => Control::GASTO_VARIOS, 'monto' => 200]);

        $pend = $this->nuevaVenta();
        $this->agregar($pend, $p, 1);
        $vacia = Order::create(['id_encargado' => 1, 'id_cliente' => 2, 'id_type' => 1, 'canal_id' => 1, 'id_forma_pago' => 1, 'completada' => 0, 'deHoy' => 1]);

        $this->post('/caja/cerrar')->assertRedirect();
        $c = CierreCaja::first();
        $this->assertEquals(500, $c->total_efectivo);       // 1000 - 300 - 200
        $this->assertEquals(2500, $c->total_turno);          // 500 + 2000 QR
        $this->assertSame(1, $c->resumen['pendientes_cant']);
        $this->assertSame(1, $c->cant_ventas);
        $this->assertFalse(app(\App\Services\Caja::class)->abierta());
        $this->assertTrue($pend->fresh()->deHoy);             // pasa al turno siguiente
        $this->assertNull($vacia->fresh());                   // la vacia se borra
        $this->post('/caja/cerrar')->assertSessionHas('error'); // no se cierra dos veces
        $this->assertSame('test', $c->abierto_por);           // quien cargo la caja inicial
        $this->get("/caja/cierres/{$c->id}")->assertOk()->assertInertia(fn ($pg) => $pg->where('cierre.abierto_por', 'test')->where('cierre.cerrado_por', 'Administrador')->etc());
        $this->get('/caja/cierres?quien=Admin&desde=2026-01-01&hasta=2026-12-31')->assertOk()->assertInertia(fn ($pg) => $pg->where('totales.cierres', 1)->etc());
        $this->get('/caja/cierres?quien=Nadie')->assertInertia(fn ($pg) => $pg->where('totales.cierres', 0)->etc());
        // movimientos de turnos cerrados no se pueden borrar
        $this->delete('/caja/movimientos/'.Control::first()->id)->assertSessionHas('error');
    }

    public function test_exporta_csv_y_excel(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $o = $this->nuevaVenta();
        $this->agregar($o, $this->producto(), 1);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']]);
        $this->get('/ventas/exportar?formato=csv')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->get('/ventas/exportar?formato=xlsx&desde=2026-01-01&hasta=2026-12-31')->assertOk();
        $this->get('/ventas/exportar?formato=exe')->assertSessionHasErrors('formato');
    }
}
