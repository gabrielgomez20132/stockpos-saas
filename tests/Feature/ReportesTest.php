<?php

namespace Tests\Feature;

use App\Models\Order;
use Tests\TestCase;

class ReportesTest extends TestCase
{
    private function venta(array $lineas, array $cobro): Order
    {
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        foreach ($lineas as [$p, $c]) {
            $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => $c])->assertOk();
        }
        $this->post("/ventas/{$o->id}/cobrar", $cobro + ['id_cliente' => 2, 'canal_id' => 1])->assertSessionHasNoErrors();

        return $o->fresh();
    }

    public function test_desglose_de_pagos_con_todos_los_medios(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1000, 'costo' => 600, 'quedan' => 100]);
        $cli = $this->cliente('Ana');

        $this->venta([[$p, 3]], ['medios' => ['efectivo']]);                                     // 3000 efectivo
        $this->venta([[$p, 2]], ['medios' => ['efectivo', 'debito'], 'monto_primero' => 500]);  // 500 efectivo + 1500 debito
        $this->venta([[$p, 5]], ['medios' => ['qr']]);                                           // 5000 qr
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 1])->assertOk();
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => $cli->id, 'canal_id' => 1, 'medios' => ['fiado']])->assertSessionHasNoErrors(); // 1000 fiado

        $r = $this->getJson('/reportes/datos?periodo=semana')->assertOk()->json();
        $pagos = collect($r['pagos'])->keyBy('medio');
        $this->assertCount(9, $r['pagos']); // estan todos los medios, aunque esten en 0
        $this->assertEquals(3500, $pagos['efectivo']['total']);
        $this->assertSame(2, $pagos['efectivo']['ventas']);
        $this->assertEquals(1500, $pagos['debito']['total']);
        $this->assertEquals(5000, $pagos['qr']['total']);
        $this->assertEquals(1000, $pagos['fiado']['total']);
        $this->assertEquals(0, $pagos['cheque']['total']);
        $this->assertSame(0, $pagos['cheque']['ventas']);
        $this->assertEquals(31.8, $pagos['efectivo']['porcentaje']); // 3500 / 11000
        $this->assertEqualsWithDelta(100, collect($r['pagos'])->sum('porcentaje'), 0.3);
        $this->assertEquals(11000, $r['totales']['cobrado']);
        $this->assertSame(4, $r['totales']['ventas']);
        $this->assertEquals(2750, $r['totales']['ticket']);
        $this->assertEquals(40, $r['totales']['margen']);
        $this->assertCount(4, $r['medios']); // el grafico solo con los que tienen algo

        // periodo sin ventas: todo en 0 sin dividir por cero
        $r = $this->getJson('/reportes/datos?periodo=personalizado&inicio=2020-01-01&fin=2020-01-10')->assertOk()->json();
        $this->assertEquals(0, $r['totales']['cobrado']);
        $this->assertEquals(0, $r['totales']['ticket']);
        $this->assertSame(0.0, (float) $r['pagos'][0]['porcentaje']);
    }
}
