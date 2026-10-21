<?php

namespace Tests\Feature;

use App\Models\Order;
use Tests\TestCase;

class VentasVaciasTest extends TestCase
{
    public function test_venta_vacia_no_cuenta_como_sin_cobrar_y_se_reusa(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 0, 'quedan' => 10]); // producto de $0 (regalo, muestra)

        // tocar "Nueva venta" dos veces sin cargar nada: una sola venta
        $this->post('/ventas');
        $this->post('/ventas');
        $this->assertSame(1, Order::count());
        $vacia = Order::first();

        $this->get('/inicio')->assertInertia(fn ($pg) => $pg->where('turno.pendientes', 0)->etc());
        $this->get('/ventas')->assertInertia(fn ($pg) => $pg->where('ventas.0.vacia', fn ($v) => (int) $v === 1)->etc());

        // con un producto (aunque sea de $0) ya es "sin cobrar" en todos lados
        $this->postJson("/ventas/{$vacia->id}/items", ['product_id' => $p->id, 'cantidad' => 1])->assertOk();
        $this->get('/inicio')->assertInertia(fn ($pg) => $pg->where('turno.pendientes', 1)->etc());
        $this->get('/ventas')->assertInertia(fn ($pg) => $pg->where('ventas.0.vacia', fn ($v) => (int) $v === 0)->etc());

        // ahora "Nueva venta" crea otra (la anterior tiene productos)
        $this->post('/ventas');
        $this->assertSame(2, Order::count());
        // y otro encargado no reusa la vacia de otro
        $this->actingAs($this->encargado('Encargado'))->post('/ventas');
        $this->assertSame(3, Order::count());
    }
}
