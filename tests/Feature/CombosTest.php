<?php

namespace Tests\Feature;

use App\Models\Combo;
use App\Models\Order;
use App\Models\OrderProduct;
use Tests\TestCase;

class CombosTest extends TestCase
{
    private function comboFernet(array $extra = []): array
    {
        $fernet = $this->producto(['nombre' => 'Fernet 750', 'monto' => 15000, 'costo' => 11000, 'quedan' => 10]);
        $coca = $this->producto(['nombre' => 'Coca 1.5', 'monto' => 2700, 'costo' => 1800, 'quedan' => 10]);
        $hielo = $this->producto(['nombre' => 'Hielo 2kg', 'monto' => 1500, 'costo' => 700, 'quedan' => 1]);
        $this->post('/combos', $extra + ['nombre' => 'Fernet + Coca + Hielo', 'codigo' => 'PROMO1', 'precio' => 17000, 'activo' => true,
            'items' => [['id_producto' => $fernet->id, 'cantidad' => 1], ['id_producto' => $coca->id, 'cantidad' => 1], ['id_producto' => $hielo->id, 'cantidad' => 1]]])
            ->assertSessionHasNoErrors();

        return [Combo::latest('id')->first(), $fernet, $coca, $hielo];
    }

    public function test_vender_un_combo_cobra_el_precio_exacto_y_descuenta_stock(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(0);
        [$combo, $fernet, $coca, $hielo] = $this->comboFernet();

        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => 'PROMO1', 'cantidad' => 1])->assertOk()
            ->assertJsonPath('monto', 17000)->assertJsonPath('items.0.tipo', 'combo');
        $this->assertEquals(9, $fernet->fresh()->quedan);
        $this->assertEquals(0, $hielo->fresh()->quedan);

        // no alcanza el hielo para un segundo combo
        $this->postJson("/ventas/{$o->id}/items", ['combo_id' => $combo->id, 'cantidad' => 1])->assertStatus(422);
        $this->assertEquals(17000, $o->fresh()->monto);

        // se puede vender el fernet suelto aparte y no se mezcla con el combo
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $fernet->id, 'cantidad' => 1])->assertOk()->assertJsonPath('monto', 32000);

        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();
        $this->get('/caja/movimientos')->assertInertia(fn ($p) => $p->where('datos.total_efec', 32000)
            ->where('datos.ganXmercaderias', (17000 - 13500) + (15000 - 11000))->etc());
        $this->get('/combos')->assertInertia(fn ($p) => $p->where('combos.0.veces', 1)->etc());
    }

    public function test_quitar_y_cambiar_cantidad_de_un_combo(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(0);
        [$combo, $fernet, $coca, $hielo] = $this->comboFernet();
        $hielo->update(['quedan' => 10]);
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $id = $this->postJson("/ventas/{$o->id}/items", ['combo_id' => $combo->id, 'cantidad' => 2])->assertOk()->assertJsonPath('monto', 34000)->json('items.0.id');
        $this->putJson("/ventas/items/{$id}", ['cantidad' => 3])->assertOk()->assertJsonPath('monto', 51000);
        $this->assertEquals(7, $fernet->fresh()->quedan);
        $id = OrderProduct::where('id_order', $o->id)->value('id');
        $this->deleteJson("/ventas/items/{$id}")->assertOk()->assertJsonPath('monto', 0);
        $this->assertEquals(10, $fernet->fresh()->quedan);
        $this->assertSame(0, OrderProduct::where('id_order', $o->id)->count());
    }

    public function test_combo_vencido_o_pausado_no_se_vende_y_validaciones(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(0);
        [$combo] = $this->comboFernet(['hasta' => now()->subDay()->toDateString(), 'desde' => now()->subDays(5)->toDateString()]);
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['combo_id' => $combo->id, 'cantidad' => 1])->assertStatus(422);
        $this->getJson('/buscar/productos?q=Fernet')->assertJsonMissing(['tipo' => 'combo']);

        $p = $this->producto();
        $this->post('/combos', ['nombre' => 'X', 'precio' => 10, 'codigo' => $p->codigo, 'items' => [['id_producto' => $p->id, 'cantidad' => 1]]])->assertSessionHasErrors('codigo');
        $this->post('/combos', ['nombre' => 'X', 'precio' => 10, 'items' => []])->assertSessionHasErrors('items');
        $this->post('/combos', ['nombre' => 'X', 'precio' => 10, 'items' => [['id_producto' => $p->id, 'cantidad' => 1], ['id_producto' => $p->id, 'cantidad' => 1]]])->assertSessionHasErrors('items.1.id_producto');

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/combos')->assertForbidden();
    }

    public function test_reparto_de_precio_con_cantidades_raras_da_exacto(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(0);
        $a = $this->producto(['monto' => 333, 'quedan' => 100]);
        $b = $this->producto(['monto' => 777, 'quedan' => 100]);
        $c = $this->producto(['_cat' => 'Fiambrería', '_unidad' => 'Kg', 'monto' => 9999, 'quedan' => 100]);
        $this->post('/combos', ['nombre' => 'Raro', 'precio' => 9991, 'items' => [
            ['id_producto' => $a->id, 'cantidad' => 3], ['id_producto' => $b->id, 'cantidad' => 7], ['id_producto' => $c->id, 'cantidad' => 0.333]]]);
        $combo = Combo::latest('id')->first();
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['combo_id' => $combo->id, 'cantidad' => 3])->assertOk()->assertJsonPath('monto', 29973);
    }
}
