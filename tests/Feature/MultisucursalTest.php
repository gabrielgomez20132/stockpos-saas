<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Order;
use App\Models\Sucursal;
use App\Support\Stock;
use App\Support\SucursalActual;
use Tests\TestCase;

class MultisucursalTest extends TestCase
{
    private function habilitar(): Sucursal
    {
        $this->actingAs($this->admin);
        $this->put('/configuracion/multisucursal', ['habilitado' => true])->assertSessionHas('ok');
        $this->post('/configuracion/sucursales', ['nombre' => 'Norte', 'direccion' => 'Av. Siempre Viva 1'])->assertSessionHas('ok');

        return Sucursal::where('nombre', 'Norte')->firstOrFail();
    }

    public function test_deshabilitado_no_hay_rutas(): void
    {
        $this->actingAs($this->admin);
        $this->get('/transferencias')->assertRedirect('/inicio');
        $this->post('/sucursal', ['id' => 1])->assertRedirect('/inicio');
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('modulos.multisucursal', false)->where('sucursal', null)->etc());
    }

    public function test_stock_caja_y_ventas_por_sucursal_y_transferencia(): void
    {
        $norte = $this->habilitar();
        $p = $this->producto(['quedan' => 10, 'codigo' => 'GAS1']);

        // transferir 4 de Casa Central a Norte
        $this->get('/transferencias/nueva')->assertOk()->assertInertia(fn ($pg) => $pg->has('destinos', 1)->etc());
        $this->post('/transferencias', ['destino' => $norte->id, 'items' => [['id_producto' => $p->id, 'cantidad' => 4]]])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertEquals(6, Stock::de($p->id, 1));
        $this->assertEquals(4, Stock::de($p->id, $norte->id));
        // no se puede mandar mas de lo que hay
        $this->post('/transferencias', ['destino' => $norte->id, 'items' => [['id_producto' => $p->id, 'cantidad' => 7]]])->assertSessionHas('error');
        $this->assertEquals(6, Stock::de($p->id, 1));

        // trabajar en Norte
        $this->post('/sucursal', ['id' => $norte->id])->assertSessionHas('ok');
        $this->get('/inicio')->assertInertia(fn ($pg) => $pg->where('sucursal.nombre', 'Norte')->where('caja.abierta', false)->etc());
        $this->get('/productos?q=GAS1')->assertInertia(fn ($pg) => $pg->where('productos.data.0.quedan', 4)->etc());

        // caja propia: se abre en Norte y Casa Central sigue cerrada
        $this->post('/caja/movimientos', ['id_desc' => 1, 'monto' => 500])->assertSessionHas('ok');
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->assertEquals($norte->id, $o->id_sucursal);
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => 'GAS1', 'cantidad' => 3])->assertOk();
        $this->assertEquals(1, Stock::de($p->id, $norte->id));
        $this->assertEquals(6, Stock::de($p->id, 1)); // Casa Central no se toca
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => 'GAS1', 'cantidad' => 2])->assertStatus(422);
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();
        // cada pedido arranca de cero (como en el servidor): la venta de Norte se abre desde Norte
        SucursalActual::fijar(null);
        $this->get("/ventas/{$o->id}")->assertOk();
        SucursalActual::fijar(null);
        $this->get("/ventas/{$o->id}/ticket")->assertOk();
        $this->get('/caja/movimientos')->assertInertia(fn ($pg) => $pg->where('datos.caja_inicial', 500)->where('datos.ingXprod_efec', 3000)->etc());

        // la venta de Norte no se ve en Casa Central (y su caja esta cerrada)
        $this->withSession(['sucursal_id' => 1]);
        $this->post('/sucursal', ['id' => 1]);
        $this->get('/inicio')->assertInertia(fn ($pg) => $pg->where('sucursal.id', 1)->where('caja.abierta', false)->etc());
        $this->get("/ventas/{$o->id}")->assertNotFound();
        $this->get('/ventas?desde='.now()->toDateString().'&hasta='.now()->toDateString())->assertInertia(fn ($pg) => $pg->has('ventas', 0)->etc());

        // reporte comparando sucursales
        $r = $this->getJson('/reportes/datos?periodo=semana&sucursal=todas')->assertOk()->json();
        $this->assertEquals(3000, collect($r['sucursales'])->firstWhere('nombre', 'Norte')['ingreso']);
        $this->assertEquals(0, collect($r['sucursales'])->firstWhere('id', 1)['ventas']);
        $this->assertEquals(3000, $r['totales']['ingreso']);
        $this->assertEquals(0, $this->getJson('/reportes/datos?periodo=semana')->json('totales.ingreso')); // Casa Central

        // no se puede deshabilitar con la caja de Norte abierta
        $this->put('/configuracion/multisucursal', ['habilitado' => false])->assertSessionHas('error');
    }

    public function test_encargado_asignado_a_una_sucursal(): void
    {
        $norte = $this->habilitar();
        $emp = $this->encargado('Encargado', ['id_sucursal' => $norte->id]);
        $this->actingAs($emp);
        $this->get('/inicio')->assertInertia(fn ($pg) => $pg->where('sucursal.nombre', 'Norte')->where('sucursal.puedeCambiar', false)->etc());
        $this->post('/sucursal', ['id' => 1])->assertSessionHas('error');
        $this->withSession(['sucursal_id' => 1])->get('/inicio')->assertInertia(fn ($pg) => $pg->where('sucursal.nombre', 'Norte')->etc());
    }

    public function test_producto_editado_en_otra_sucursal_cambia_solo_su_stock(): void
    {
        $norte = $this->habilitar();
        $p = $this->producto(['quedan' => 8]);
        $this->withSession(['sucursal_id' => $norte->id]);
        $this->get("/productos/{$p->id}/edit")->assertInertia(fn ($pg) => $pg->where('producto.quedan', 0)->has('stockSucursal.stock', 2)->where('sucursal.id', $norte->id)->etc());
        $this->put("/productos/{$p->id}", ['nombre' => $p->nombre, 'codigo' => $p->codigo, 'id_categoria' => $p->id_categoria, 'costo' => 600, 'monto' => 1000,
            'aviso' => 1, 'quedan' => 0, 'ingreso' => 5])->assertSessionHasNoErrors();
        $this->assertEquals(5, Stock::de($p->id, $norte->id));
        $this->assertEquals(8, $p->fresh()->quedan);

        // un producto nuevo cargado en Norte arranca con stock solo en Norte
        $this->post('/productos', ['nombre' => 'Nuevo', 'codigo' => 'NUE1', 'id_categoria' => $p->id_categoria, 'costo' => 1, 'monto' => 2, 'aviso' => 0, 'stock_inicial' => 3])
            ->assertSessionHasNoErrors();
        $nuevo = \App\Models\Product::where('codigo', 'NUE1')->first();
        $this->assertEquals(0, $nuevo->quedan);
        $this->assertEquals(3, Stock::de($nuevo->id, $norte->id));
        SucursalActual::fijar(null);
    }

    public function test_configuracion_sucursales_solo_duenos(): void
    {
        $this->actingAs($this->encargado('Empleado'));
        Configuracion::set('multisucursal_habilitado', true);
        $this->assertContains($this->put('/configuracion/multisucursal', ['habilitado' => false])->status(), [302, 403]);
        $this->assertContains($this->post('/configuracion/sucursales', ['nombre' => 'X'])->status(), [302, 403]);
        Configuracion::limpiarCache();
        $this->assertTrue(SucursalActual::habilitado());
        $this->assertFalse(Sucursal::where('nombre', 'X')->exists());
    }
}
