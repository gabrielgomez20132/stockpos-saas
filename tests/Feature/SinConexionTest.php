<?php

namespace Tests\Feature;

use App\Models\IngresoProducto;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use Tests\TestCase;

class SinConexionTest extends TestCase
{
    private function venta(array $extra = []): array
    {
        return $extra + ['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001', 'fecha' => now()->subHours(2)->toIso8601String(), 'medio' => 'efectivo', 'items' => []];
    }

    public function test_catalogo_y_sincronizar_ventas_hechas_sin_conexion(): void
    {
        $this->actingAs($this->admin);
        $coca = $this->producto(['monto' => 1500, 'quedan' => 5, 'codigo' => 'C1']);
        $alfa = $this->producto(['monto' => 800, 'quedan' => 1]);

        $cat = $this->getJson('/offline/catalogo')->assertOk()->json();
        $this->assertSame('C1', collect($cat['productos'])->firstWhere('id', $coca->id)['c']);
        $this->assertArrayNotHasKey('costo', $cat['productos'][0]);

        // caja cerrada: queda pendiente
        $v = $this->venta(['items' => [['id' => $coca->id, 'cantidad' => 2, 'monto' => 1500], ['id' => $alfa->id, 'cantidad' => 3, 'monto' => 800]], 'total' => 5400]);
        $this->postJson('/offline/ventas', ['ventas' => [$v]])->assertOk()->assertJsonPath("resultados.{$v['uuid']}.estado", 'caja');
        $this->assertSame(0, Order::count());

        $this->abrirCaja();
        $r = $this->postJson('/offline/ventas', ['ventas' => [$v]])->assertOk()->json("resultados.{$v['uuid']}");
        $this->assertSame('ok', $r['estado']);
        $o = Order::find($r['venta']);
        $this->assertTrue($o->completada);
        $this->assertEquals(5400, $o->monto);
        $this->assertEquals(5400, $o->pago_efec);
        $this->assertSame(now()->subHours(2)->format('Y-m-d H'), $o->created_at->format('Y-m-d H')); // hora real de la venta
        $this->assertEquals(3, $coca->fresh()->quedan);
        $this->assertEquals(0, $alfa->fresh()->quedan); // vendio 3 con 1 en stock: queda en 0 y avisa
        $this->assertNotEmpty($r['avisos']);

        // reenviar no duplica
        $this->postJson('/offline/ventas', ['ventas' => [$v]])->assertJsonPath("resultados.{$v['uuid']}.estado", 'ya');
        $this->assertSame(1, Order::count());

        // precio inventado: se usa el actual; precio viejo de esa hora: se respeta
        IngresoProducto::forceCreate(['id_producto' => $coca->id, 'id_user' => 1, 'cantidad' => 0, 'costo' => 600, 'monto' => 1400, 'created_at' => now()->subDay()]);
        $coca->update(['monto' => 1600]);
        IngresoProducto::create(['id_producto' => $coca->id, 'id_user' => 1, 'cantidad' => 0, 'costo' => 600, 'monto' => 1600]);
        $v2 = $this->venta(['uuid' => 'uuid-2', 'medio' => 'qr', 'items' => [['id' => $coca->id, 'cantidad' => 1, 'monto' => 1400]]]);
        $v3 = $this->venta(['uuid' => 'uuid-3', 'items' => [['id' => $coca->id, 'cantidad' => 1, 'monto' => 10]]]);
        $res = $this->postJson('/offline/ventas', ['ventas' => [$v2, $v3]])->json('resultados');
        $this->assertEquals(1400, Order::find($res['uuid-2']['venta'])->monto);
        $this->assertEquals(1400, Order::find($res['uuid-2']['venta'])->pago_qr);
        $this->assertEquals(1600, Order::find($res['uuid-3']['venta'])->monto);
        $this->assertNotEmpty($res['uuid-3']['avisos']);
    }

    public function test_descuento_sin_permiso_se_quita(): void
    {
        $emp = $this->encargado('Empleado');
        Role::where('name', 'Empleado')->first()->permissions()->detach(Permission::where('slug', 'descuentos')->value('id'));
        $this->actingAs($emp->fresh());
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1000]);
        $r = $this->postJson('/offline/ventas', ['ventas' => [$this->venta(['uuid' => 'd-1', 'descuento' => 300, 'items' => [['id' => $p->id, 'cantidad' => 1, 'monto' => 1000]]])]])->json('resultados.d-1');
        $this->assertEquals(0, Order::find($r['venta'])->descuento);

        // y en la venta normal tampoco puede
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 1]);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo'], 'descuento_pct' => 10])->assertSessionHas('error');
    }

    public function test_permisos_nuevos_se_heredan_y_roles_se_copian(): void
    {
        $this->actingAs($this->admin);
        $dueno = Role::where('name', 'Dueño')->first();
        foreach (['devoluciones', 'proveedores', 'combos', 'listas-precios', 'transferencias', 'configuracion', 'descuentos', 'anular-ventas'] as $slug) {
            $this->assertTrue($dueno->permissions->contains('slug', $slug), $slug);
        }
        $emp = Role::where('name', 'Empleado')->first();
        $this->assertFalse($emp->permissions->contains('slug', 'configuracion'));
        $this->assertTrue($emp->permissions->contains('slug', 'descuentos'));

        $this->post('/roles', ['name' => 'Cajero', 'copiar_de' => $emp->id])->assertSessionHas('ok');
        $cajero = Role::where('name', 'Cajero')->first();
        $this->assertEqualsCanonicalizing($emp->permissions->pluck('id')->all(), $cajero->permissions->pluck('id')->all());
        $this->put("/roles/{$cajero->id}", ['name' => 'Cajera', 'description' => 'Caja'])->assertSessionHas('ok');
        $this->assertSame('Cajera', $cajero->fresh()->name);

        // un empleado sin el permiso nuevo no entra a configuracion ni a devoluciones
        $this->actingAs($this->encargado('Empleado'));
        $this->get('/configuracion')->assertForbidden();
        $this->get('/devoluciones')->assertForbidden();
        $this->get('/combos')->assertForbidden();
    }
}
