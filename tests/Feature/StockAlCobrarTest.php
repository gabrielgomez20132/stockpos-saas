<?php

namespace Tests\Feature;

use App\Models\Deuda;
use App\Models\Order;
use App\Support\Stock;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockAlCobrarTest extends TestCase
{
    private function venta(): Order
    {
        $this->post('/ventas')->assertRedirect();

        return Order::latest('id')->first();
    }

    public function test_dos_cajas_con_la_ultima_unidad_solo_una_la_cobra(): void
    {
        $this->abrirCaja();
        $p = $this->producto(['nombre' => 'Fernet', 'monto' => 15000, 'quedan' => 1]);
        $cli = $this->cliente('Ana');

        // caja 1 y caja 2 cargan la misma unidad (el stock no se toca al cargar)
        $this->actingAs($this->admin);
        $a = $this->venta();
        $this->postJson("/ventas/{$a->id}/items", ['product_id' => $p->id, 'cantidad' => 1])->assertOk();
        $this->actingAs($this->encargado('Encargado'));
        $b = $this->venta();
        $this->postJson("/ventas/{$b->id}/items", ['product_id' => $p->id, 'cantidad' => 1])->assertOk();
        $this->assertEquals(1, Stock::de($p->id));

        // cobra la caja 2 primero
        $this->post("/ventas/{$b->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();
        $this->assertEquals(0, Stock::de($p->id));

        // la caja 1 ya no puede: aviso claro, nada queda a medias (ni deuda, ni cobro, ni stock negativo)
        $this->actingAs($this->admin);
        $r = $this->post("/ventas/{$a->id}/cobrar", ['id_cliente' => $cli->id, 'canal_id' => 1, 'medios' => ['fiado']]);
        $r->assertSessionHas('error');
        $this->assertStringContainsString('Fernet (quedan 0, en la venta hay 1)', session('error'));
        $a->refresh();
        $this->assertFalse($a->completada);
        $this->assertFalse($a->stock_descontado);
        $this->assertEquals(0, $a->fiado);
        $this->assertSame(0, Deuda::count());
        $this->assertEquals(0, Stock::de($p->id));

        // y si se repone, se cobra normal
        DB::table('products')->where('id', $p->id)->update(['quedan' => 3]);
        $this->post("/ventas/{$a->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();
        $this->assertEquals(2, Stock::de($p->id));
    }

    public function test_anular_o_abandonar_una_venta_no_toca_el_stock(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['quedan' => 5]);
        $o = $this->venta();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 4])->assertOk();
        $this->delete("/ventas/{$o->id}")->assertRedirect();
        $this->assertDatabaseMissing('orders', ['id' => $o->id]);
        $this->assertEquals(5, Stock::de($p->id));

        // abandonada: pasa el cierre de caja y no tomo stock
        $o = $this->venta();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 4])->assertOk();
        $this->post('/caja/cerrar')->assertSessionHasNoErrors();
        $this->assertEquals(5, Stock::de($p->id));
        $this->assertTrue(Order::whereKey($o->id)->exists()); // sigue para cobrarla o anularla
    }

    public function test_la_migracion_devuelve_el_stock_de_las_ventas_abiertas_viejas(): void
    {
        $p = $this->producto(['quedan' => 7]);   // 3 tomadas por una venta abierta de antes
        $q = $this->producto(['quedan' => 9]);   // 1 vendida (cobrada)
        $abierta = Order::create(['id_encargado' => 1, 'id_cliente' => 2, 'id_type' => 1, 'canal_id' => 1, 'id_forma_pago' => 1, 'monto' => 3000, 'completada' => 0, 'deHoy' => 1]);
        $abierta->productos()->create(['id_producto' => $p->id, 'cantidad' => 3, 'costo_x_uni' => 600, 'monto' => 1000]);
        $cobrada = Order::create(['id_encargado' => 1, 'id_cliente' => 2, 'id_type' => 1, 'canal_id' => 1, 'id_forma_pago' => 1, 'monto' => 1000, 'completada' => 1, 'deHoy' => 1]);
        $cobrada->productos()->create(['id_producto' => $q->id, 'cantidad' => 1, 'costo_x_uni' => 600, 'monto' => 1000]);
        DB::table('orders')->update(['stock_descontado' => 0]); // como estaban antes de la migracion

        (require database_path('migrations/2026_10_21_000012_stock_al_cobrar.php'))->up();

        $this->assertEquals(10, Stock::de($p->id));
        $this->assertEquals(9, Stock::de($q->id));
        $this->assertTrue($cobrada->fresh()->stock_descontado);
        $this->assertFalse($abierta->fresh()->stock_descontado);

        // al cobrar la vieja se descuenta una sola vez
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $this->post("/ventas/{$abierta->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();
        $this->assertEquals(7, Stock::de($p->id));
        // correrla otra vez no cambia nada
        (require database_path('migrations/2026_10_21_000012_stock_al_cobrar.php'))->up();
        $this->assertEquals(7, Stock::de($p->id));
    }

    public function test_venta_sin_conexion_descuenta_una_sola_vez(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1000, 'quedan' => 1]);
        $v = ['uuid' => 'u-1', 'fecha' => now()->toIso8601String(), 'medio' => 'efectivo', 'total' => 2000, 'items' => [['id' => $p->id, 'cantidad' => 2, 'monto' => 1000]]];
        $r = $this->postJson('/offline/ventas', ['ventas' => [$v]])->assertOk()->json('resultados.u-1');
        $this->assertNotEmpty($r['venta']); // se registra aunque no alcanzara el stock (ya se vendio)
        $this->assertEquals(0, Stock::de($p->id));
        $this->assertTrue(Order::find($r['venta'])->stock_descontado);
    }
}
