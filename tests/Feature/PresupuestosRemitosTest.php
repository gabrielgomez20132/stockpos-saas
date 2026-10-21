<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Presupuesto;
use App\Models\Remito;
use App\Models\Role;
use App\Models\Sucursal;
use App\Support\Stock;
use Tests\TestCase;

class PresupuestosRemitosTest extends TestCase
{
    private function habilitar(): void
    {
        $this->actingAs($this->admin);
        $this->put('/configuracion/presupuestos', ['habilitadas' => true])->assertSessionHas('ok');
        $this->put('/configuracion/remitos', ['habilitadas' => true])->assertSessionHas('ok');
    }

    /** venta cobrada con los productos dados [[producto, cantidad], ...] */
    private function venta(array $lineas, ?int $cliente = null): Order
    {
        $this->abrirCaja();
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        foreach ($lineas as [$p, $c]) {
            $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => $c])->assertOk();
        }
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => $cliente ?? 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();

        return $o->fresh();
    }

    public function test_modulos_deshabilitados_y_permisos(): void
    {
        $this->actingAs($this->admin);
        $this->get('/presupuestos')->assertRedirect('/inicio');
        $this->get('/remitos')->assertRedirect('/inicio');
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('modulos.presupuestos', false)->where('modulos.remitos', false)->etc());

        $this->habilitar();
        $this->get('/configuracion')->assertInertia(fn ($p) => $p->where('presupuestos', true)->where('remitos', true)->etc());
        $this->get('/presupuestos')->assertOk();
        $this->get('/remitos')->assertOk();

        // los permisos aparecen en Roles y el Empleado arranca con presupuestos pero sin remitos (mueven stock)
        $this->assertTrue(Permission::where('slug', 'presupuestos')->exists());
        $this->assertTrue(Permission::where('slug', 'remitos')->exists());
        $emp = $this->encargado('Empleado');
        $this->assertTrue($emp->hasPermission('presupuestos'));
        $this->assertFalse($emp->hasPermission('remitos'));
        $this->actingAs($emp);
        $this->get('/presupuestos')->assertOk();
        $this->get('/remitos')->assertForbidden();
        $this->post('/remitos', [])->assertForbidden();

        // sacandole el permiso desde Roles, deja de entrar
        $rol = Role::where('name', 'Empleado')->first();
        $rol->permissions()->detach(Permission::where('slug', 'presupuestos')->value('id'));
        $this->actingAs($emp->fresh());
        $this->get('/presupuestos')->assertForbidden();
    }

    public function test_presupuesto_crear_editar_duplicar_imprimir_y_borrar(): void
    {
        $this->habilitar();
        $p = $this->producto(['nombre' => 'Cemento', 'monto' => 9000, 'quedan' => 0]);
        $c = $this->cliente('Constructora Sur');
        $c->update(['dni' => 30111222, 'direccion' => 'Ruta 3 km 5', 'telefono' => '2914000000']);

        $this->getJson('/presupuestos/productos?q=Cem')->assertOk()->assertJsonPath('0.monto', 9000);
        $this->getJson('/presupuestos/clientes?q=Constr')->assertOk()->assertJsonPath('0.dni', 30111222);

        $this->post('/presupuestos', ['id_cliente' => $c->id, 'validez_dias' => 15, 'descuento' => 500, 'observaciones' => 'Entrega en obra',
            'items' => [
                ['id_producto' => $p->id, 'descripcion' => 'Cemento x 50 kg', 'cantidad' => 3, 'precio' => 9000],
                ['descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 2500.5],
            ]])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $pr = Presupuesto::with('items')->first();
        $this->assertEquals(29500.5, $pr->subtotal);
        $this->assertEquals(29000.5, $pr->total);
        $this->assertSame('Constructora Sur', $pr->cliente_nombre); // datos del cliente copiados
        $this->assertSame('30111222', $pr->cliente_doc);
        $this->assertSame('Ruta 3 km 5', $pr->cliente_direccion);
        $this->assertSame('Cemento x 50 kg', $pr->items[0]->descripcion);
        $this->assertSame((string) $p->codigo, $pr->items[0]->codigo);
        $this->assertNull($pr->items[1]->id_producto);
        $this->assertEquals(0, Stock::de($p->id)); // no toca stock (y se puede presupuestar sin stock)
        $this->assertSame('0001-00000001', $pr->numero());

        $this->get("/presupuestos/{$pr->id}")->assertOk()->assertInertia(fn ($pg) => $pg->component('Presupuestos/Show', false)
            ->where('presupuesto.total', 29000.5)->where('presupuesto.vencido', false)->where('presupuesto.vence', now()->addDays(15)->toDateString())->etc());
        $this->get("/presupuestos/{$pr->id}/imprimir")->assertOk()->assertInertia(fn ($pg) => $pg->component('Print/Documento', false)
            ->where('tipo', 'presupuesto')->has('doc.items', 2)->has('emisor.razon_social')->etc());

        // editar
        $this->put("/presupuestos/{$pr->id}", ['cliente_nombre' => 'Otro', 'validez_dias' => 7, 'items' => [['descripcion' => 'Arena', 'cantidad' => 2, 'precio' => 100]]])
            ->assertSessionHasNoErrors();
        $pr->refresh();
        $this->assertSame('Otro', $pr->cliente_nombre);
        $this->assertNull($pr->id_cliente);
        $this->assertEquals(200, $pr->total);
        $this->assertCount(1, $pr->items);

        // duplicar: abre el formulario con los datos y sin numero
        $this->get("/presupuestos/nuevo?copiar={$pr->id}")->assertInertia(fn ($pg) => $pg->where('presupuesto.id', null)->where('copiaDe', $pr->numero())->has('presupuesto.items', 1)->etc());
        $this->get('/presupuestos?q=otro')->assertInertia(fn ($pg) => $pg->has('presupuestos.data', 1)->etc());
        $this->get('/presupuestos?q=1')->assertInertia(fn ($pg) => $pg->has('presupuestos.data', 1)->etc());
        $this->get('/presupuestos?q=0001-00000001')->assertInertia(fn ($pg) => $pg->has('presupuestos.data', 1)->etc());
        $this->get('/presupuestos?q=nadie')->assertInertia(fn ($pg) => $pg->has('presupuestos.data', 0)->etc());

        $this->delete("/presupuestos/{$pr->id}")->assertRedirect('/presupuestos');
        $this->assertSame(0, Presupuesto::count());
        $this->assertDatabaseCount('presupuesto_items', 0);
    }

    public function test_presupuesto_validaciones(): void
    {
        $this->habilitar();
        $p = $this->producto();
        $base = ['validez_dias' => 7, 'items' => [['descripcion' => 'X', 'cantidad' => 1, 'precio' => 100]]];
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => []])->assertSessionHasErrors('items');
        $this->post('/presupuestos', ['validez_dias' => 0] + $base)->assertSessionHasErrors('validez_dias');
        $this->post('/presupuestos', $base + ['descuento' => 101])->assertSessionHas('error');
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['descripcion' => '', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHasErrors('items.0.descripcion');
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['descripcion' => 'X', 'cantidad' => 0, 'precio' => 1]]])->assertSessionHasErrors('items.0.cantidad');
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['descripcion' => 'X', 'cantidad' => 1, 'precio' => -1]]])->assertSessionHasErrors('items.0.precio');
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['id_producto' => 99999, 'descripcion' => 'X', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHas('error');
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['id_producto' => $p->id, 'descripcion' => 'X', 'cantidad' => 1.5, 'precio' => 1]]])->assertSessionHas('error');
        $this->post('/presupuestos', ['id_cliente' => $this->admin->id] + $base)->assertSessionHasErrors('id_cliente'); // solo clientes
        $this->assertSame(0, Presupuesto::count());
    }

    public function test_remito_de_venta_no_mueve_stock_y_permite_entregas_parciales(): void
    {
        $this->habilitar();
        $a = $this->producto(['nombre' => 'Ladrillo', 'quedan' => 100, 'monto' => 300]);
        $b = $this->producto(['nombre' => 'Cal', 'quedan' => 10, 'monto' => 5000]);
        $cli = $this->cliente('Pedro');
        $o = $this->venta([[$a, 50], [$b, 2]], $cli->id);
        $this->assertEquals(50, Stock::de($a->id));

        $this->get("/ventas/{$o->id}")->assertInertia(fn ($pg) => $pg->where('remitos', [])->etc());
        $this->get("/remitos/nuevo?venta={$o->id}")->assertOk()->assertInertia(fn ($pg) => $pg->component('Remitos/Form', false)
            ->has('venta.lineas', 2)->where('venta.lineas.0.cantidad', 50)->where('venta.cliente.nombre', 'Pedro')->etc());

        // primera entrega: 30 ladrillos
        $this->post('/remitos', ['id_order' => $o->id, 'id_cliente' => $cli->id, 'items' => [['id_producto' => $a->id, 'descripcion' => 'Ladrillo', 'cantidad' => 30, 'precio' => 300]]])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $r1 = Remito::first();
        $this->assertFalse($r1->descuenta_stock);
        $this->assertEquals(50, Stock::de($a->id)); // el stock no se vuelve a descontar
        $this->assertSame('Pedro', $r1->cliente_nombre);

        // falta entregar 20: no se puede poner 21
        $this->get("/remitos/nuevo?venta={$o->id}")->assertInertia(fn ($pg) => $pg->where('venta.lineas.0.cantidad', 20)->etc());
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [['id_producto' => $a->id, 'descripcion' => 'Ladrillo', 'cantidad' => 21, 'precio' => 300]]])->assertSessionHas('error');
        // ni un producto que no esta en la venta
        $otro = $this->producto();
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [['id_producto' => $otro->id, 'descripcion' => 'X', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHas('error');
        // el mismo producto repartido en dos renglones tambien cuenta
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [
            ['id_producto' => $a->id, 'descripcion' => 'Ladrillo', 'cantidad' => 15, 'precio' => 300], ['id_producto' => $a->id, 'descripcion' => 'Ladrillo', 'cantidad' => 6, 'precio' => 300]]])->assertSessionHas('error');
        $this->assertSame(1, Remito::count());

        // segunda entrega con el resto y un renglon a mano (sin cliente: toma el de la venta)
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [
            ['id_producto' => $a->id, 'descripcion' => 'Ladrillo', 'cantidad' => 20, 'precio' => 300],
            ['id_producto' => $b->id, 'descripcion' => 'Cal', 'cantidad' => 2, 'precio' => 5000],
            ['descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 0]]])->assertSessionHasNoErrors();
        $r2 = Remito::latest('id')->first();
        $this->assertSame('Pedro', $r2->cliente_nombre);
        $this->assertEquals(16000, $r2->total);
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [['id_producto' => $b->id, 'descripcion' => 'Cal', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHas('error'); // ya entregado

        // anular el segundo: no cambia el stock y libera lo que faltaba entregar
        $this->post("/remitos/{$r2->id}/anular")->assertSessionHas('ok');
        $this->assertNotNull($r2->fresh()->anulado_at);
        $this->assertEquals(50, Stock::de($a->id));
        $this->post("/remitos/{$r2->id}/anular")->assertSessionHas('error'); // dos veces no
        $this->get("/remitos/nuevo?venta={$o->id}")->assertInertia(fn ($pg) => $pg->where('venta.lineas.0.cantidad', 20)->where('venta.lineas.1.cantidad', 2)->etc());

        $this->get("/ventas/{$o->id}")->assertInertia(fn ($pg) => $pg->has('remitos', 2)->where('remitos.1.anulado', true)->etc());
        $this->get("/remitos/{$r1->id}/imprimir?precios=1")->assertOk()->assertInertia(fn ($pg) => $pg->where('tipo', 'remito')->where('precios', true)->where('doc.venta', $o->id)->etc());
        $this->get("/remitos/{$r1->id}/imprimir")->assertInertia(fn ($pg) => $pg->where('precios', false)->etc());
        $this->get('/remitos?q='.$o->id)->assertInertia(fn ($pg) => $pg->has('remitos.data', 2)->etc());
    }

    public function test_remito_de_venta_sin_cobrar_no(): void
    {
        $this->habilitar();
        $p = $this->producto();
        $this->abrirCaja();
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => 1])->assertOk();
        $this->get("/remitos/nuevo?venta={$o->id}")->assertRedirect('/remitos/nuevo');
        $this->post('/remitos', ['id_order' => $o->id, 'items' => [['id_producto' => $p->id, 'descripcion' => 'X', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHas('error');
        $this->get("/ventas/{$o->id}")->assertInertia(fn ($pg) => $pg->where('remitos', null)->etc());
    }

    public function test_remito_suelto_descuenta_stock_y_al_anular_vuelve(): void
    {
        $this->habilitar();
        $p = $this->producto(['nombre' => 'Hielo', 'quedan' => 5, 'monto' => 800]);
        $kg = $this->producto(['_cat' => 'Fiambres', '_unidad' => 'Kg', 'nombre' => 'Queso', 'quedan' => 2.5, 'monto' => 10000]);

        $this->getJson('/remitos/productos?q=Hielo')->assertJsonPath('0.quedan', 5);
        // no deja pasarse del stock
        $this->post('/remitos', ['items' => [['id_producto' => $p->id, 'descripcion' => 'Hielo', 'cantidad' => 6, 'precio' => 800]]])->assertSessionHas('error');
        $this->post('/remitos', ['items' => [['id_producto' => $p->id, 'descripcion' => 'Hielo', 'cantidad' => 3, 'precio' => 800], ['id_producto' => $p->id, 'descripcion' => 'Hielo', 'cantidad' => 3, 'precio' => 800]]])->assertSessionHas('error');
        $this->assertEquals(5, Stock::de($p->id));

        $this->post('/remitos', ['cliente_nombre' => 'Bar Centro', 'items' => [
            ['id_producto' => $p->id, 'descripcion' => 'Hielo', 'cantidad' => 4, 'precio' => 800],
            ['id_producto' => $kg->id, 'descripcion' => 'Queso', 'cantidad' => 1.25, 'precio' => 10000],
            ['descripcion' => 'Envío', 'cantidad' => 1, 'precio' => 1000]]])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $r = Remito::first();
        $this->assertTrue($r->descuenta_stock);
        $this->assertNull($r->id_order);
        $this->assertEquals(1, Stock::de($p->id));
        $this->assertEquals(1.25, Stock::de($kg->id));
        $this->assertEquals(16700, $r->total);

        $this->get("/remitos/{$r->id}")->assertOk()->assertInertia(fn ($pg) => $pg->where('remito.stock', true)->has('remito.items', 3)->etc());
        $this->post("/remitos/{$r->id}/anular")->assertSessionHas('ok');
        $this->assertEquals(5, Stock::de($p->id));
        $this->assertEquals(2.5, Stock::de($kg->id));
        $this->post("/remitos/{$r->id}/anular")->assertSessionHas('error');
        $this->assertEquals(5, Stock::de($p->id)); // no vuelve dos veces
    }

    public function test_remitos_y_presupuestos_por_sucursal(): void
    {
        $this->habilitar();
        $this->put('/configuracion/multisucursal', ['habilitado' => true]);
        $this->post('/configuracion/sucursales', ['nombre' => 'Norte']);
        $norte = Sucursal::where('nombre', 'Norte')->first();
        $p = $this->producto(['quedan' => 10]);
        $this->post('/transferencias', ['destino' => $norte->id, 'items' => [['id_producto' => $p->id, 'cantidad' => 4]]])->assertSessionHasNoErrors();

        $this->post('/sucursal', ['id' => $norte->id]);
        $this->post('/remitos', ['items' => [['id_producto' => $p->id, 'descripcion' => 'X', 'cantidad' => 5, 'precio' => 1]]])->assertSessionHas('error'); // en Norte hay 4
        $this->post('/remitos', ['items' => [['id_producto' => $p->id, 'descripcion' => 'X', 'cantidad' => 3, 'precio' => 1]]])->assertSessionHasNoErrors();
        $this->post('/presupuestos', ['validez_dias' => 7, 'items' => [['descripcion' => 'X', 'cantidad' => 1, 'precio' => 1]]])->assertSessionHasNoErrors();
        $r = Remito::withoutGlobalScope('sucursal')->first();
        $pr = Presupuesto::withoutGlobalScope('sucursal')->first();
        $this->assertSame($norte->id, $r->id_sucursal);
        $this->assertEquals(1, Stock::de($p->id, $norte->id));
        $this->assertEquals(6, Stock::de($p->id, 1));
        $this->assertStringStartsWith(str_pad((string) $norte->id, 4, '0', STR_PAD_LEFT).'-', $r->numero());
        $this->get("/remitos/{$r->id}/imprimir")->assertInertia(fn ($pg) => $pg->where('emisor.sucursal.nombre', 'Norte')->etc());
        // cada pedido arranca de cero (como en el servidor): la sucursal se resuelve antes de buscar el remito
        \App\Support\SucursalActual::fijar(null);
        $this->get("/remitos/{$r->id}")->assertOk();
        \App\Support\SucursalActual::fijar(null);
        $this->get("/presupuestos/{$pr->id}")->assertOk();

        // desde Casa Central no se ven
        $this->post('/sucursal', ['id' => 1]);
        \App\Support\SucursalActual::fijar($norte->id);
        $this->get("/remitos/{$r->id}")->assertNotFound();
        $this->post("/remitos/{$r->id}/anular")->assertNotFound();
        $this->get("/presupuestos/{$pr->id}")->assertNotFound();
        $this->delete("/presupuestos/{$pr->id}")->assertNotFound();
        $this->get('/remitos')->assertInertia(fn ($pg) => $pg->has('remitos.data', 0)->etc());
        $this->assertEquals(1, Stock::de($p->id, $norte->id));
    }
}
