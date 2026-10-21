<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Produccion;
use App\Models\Role;
use App\Support\Panaderia;
use App\Support\Stock;
use Tests\TestCase;

class PanaderiaTest extends TestCase
{
    private const CFG = ['habilitada' => true, 'importe' => true, 'balanza' => true, 'produccion' => true,
        'prefijos' => '20', 'digitos_codigo' => 5, 'tipo' => 'peso', 'decimales' => 3, 'verificar' => true];

    private function configurar(array $extra = []): void
    {
        $this->actingAs($this->admin);
        $this->put('/configuracion/panaderia', $extra + self::CFG)->assertSessionHasNoErrors()->assertSessionHas('ok');
    }

    /** etiqueta EAN-13 con su digito verificador */
    private function ean(string $doce): string
    {
        $s = 0;
        for ($i = 0; $i < 12; $i++) {
            $s += (int) $doce[$i] * ($i % 2 ? 3 : 1);
        }

        return $doce.((10 - $s % 10) % 10);
    }

    private function ventaAbierta(): Order
    {
        $this->abrirCaja();
        $this->post('/ventas');

        return Order::latest('id')->first();
    }

    private function pan(array $a = [])
    {
        return $this->producto($a + ['_cat' => 'Panificados', '_unidad' => 'Kg', 'codigo' => '105', 'nombre' => 'Pan francés', 'monto' => 2400, 'costo' => 1200, 'quedan' => 20]);
    }

    public function test_configuracion_y_modulo(): void
    {
        $this->actingAs($this->admin);
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('modulos.panaderia.importe', false)->where('modulos.panaderia.balanza', false)->where('modulos.panaderia.produccion', false)->etc());
        $this->get('/produccion')->assertRedirect('/inicio');

        $this->configurar(['balanza' => false]);
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('modulos.panaderia.importe', true)->where('modulos.panaderia.balanza', false)
            ->where('modulos.panaderia.produccion', true)->where('modulos.panaderia.etiqueta', null)->etc());
        $this->get('/produccion')->assertOk();
        $this->get('/configuracion')->assertInertia(fn ($p) => $p->where('panaderia.habilitada', true)->where('panaderia.prefijos', '20')->etc());

        // validaciones
        $this->put('/configuracion/panaderia', ['prefijos' => '2a'] + self::CFG)->assertSessionHasErrors('prefijos');
        $this->put('/configuracion/panaderia', ['prefijos' => '200', 'digitos_codigo' => 7] + self::CFG)->assertSessionHas('error'); // no queda lugar para el peso
        $this->put('/configuracion/panaderia', ['tipo' => 'otra'] + self::CFG)->assertSessionHasErrors('tipo');

        // deshabilitada: se apagan todas las opciones
        $this->put('/configuracion/panaderia', ['habilitada' => false] + self::CFG)->assertSessionHas('ok');
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('modulos.panaderia.importe', false)->where('modulos.panaderia.produccion', false)->etc());

        // permiso por rol: el Empleado arranca sin produccion
        $this->assertTrue(Permission::where('slug', 'produccion')->exists());
        $this->configurar();
        $emp = $this->encargado('Empleado');
        $this->actingAs($emp)->get('/produccion')->assertForbidden();
        Role::where('name', 'Empleado')->first()->permissions()->attach(Permission::where('slug', 'produccion')->value('id'));
        $this->actingAs($emp->fresh())->get('/produccion')->assertOk();
    }

    public function test_leer_etiqueta(): void
    {
        $c = self::CFG;
        $e = $this->ean('200010501250'); // PLU 00105, 01250 g = 1,25 kg
        $this->assertSame(['plu' => '00105', 'tipo' => 'peso', 'valor' => 1.25], Panaderia::leer($e, $c));
        $this->assertNull(Panaderia::leer('7790001000010', $c)); // otro prefijo: codigo comun
        $this->assertNull(Panaderia::leer('12345', $c));
        $mal = substr($e, 0, 12).(((int) $e[12] + 1) % 10);
        $this->expectException(\DomainException::class);
        Panaderia::leer($mal, $c);
    }

    public function test_venta_por_importe(): void
    {
        $this->configurar();
        $pan = $this->pan();
        $facturas = $this->producto(['nombre' => 'Medialuna', 'monto' => 500, 'quedan' => 50]);
        $o = $this->ventaAbierta();

        // $1.500 de pan a $2.400 el kg = 0,625 kg
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $pan->id, 'importe' => 1500])->assertOk()->assertJsonPath('monto', 1500);
        $this->assertEquals(0.625, $o->productos()->first()->cantidad);
        $this->assertEquals(19.375, Stock::de($pan->id));
        // tambien escribiendo el codigo
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => '105', 'importe' => '1.000'])->assertOk();
        $this->assertEquals(1.042, $o->productos()->first()->fresh()->cantidad); // se suma al mismo renglon (0,625 + 0,417)

        // por unidad no
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $facturas->id, 'importe' => 1000])->assertStatus(422);
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $pan->id, 'importe' => 0])->assertStatus(422);
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $pan->id, 'importe' => 1])->assertStatus(422); // no llega a 1 gramo
        // con la opcion apagada no
        $this->put('/configuracion/panaderia', ['importe' => false] + self::CFG);
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $pan->id, 'importe' => 1500])->assertStatus(422);
    }

    public function test_etiqueta_de_balanza_por_peso_y_por_precio(): void
    {
        $this->configurar();
        $pan = $this->pan();
        $o = $this->ventaAbierta();

        // peso: 0,850 kg de PLU 00105 (el producto esta cargado como "105")
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $this->ean('200010500850'), 'cantidad' => 1])->assertOk()->assertJsonPath('monto', 2040);
        $this->assertEquals(0.85, $o->productos()->first()->cantidad);
        // digito verificador mal
        $e = $this->ean('200010500850');
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => substr($e, 0, 12).(((int) $e[12] + 1) % 10), 'cantidad' => 1])->assertStatus(422);
        // PLU que no existe
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $this->ean('200099900500'), 'cantidad' => 1])->assertStatus(422)->assertJsonFragment(['message' => 'La etiqueta es del producto con código 999, pero no hay ningún producto activo con ese código. Cargalo con el mismo código que tiene en la balanza.']);
        // un producto con ese codigo exacto de 13 digitos se vende como siempre
        $exacto = $this->producto(['codigo' => $this->ean('200099900501'), 'nombre' => 'Exacto', 'quedan' => 5]);
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $exacto->codigo, 'cantidad' => 2])->assertOk();
        $this->assertEquals(3, Stock::de($exacto->id));

        // precio: la etiqueta trae $1.800,00 (2 decimales) => 0,75 kg
        $this->put('/configuracion/panaderia', ['tipo' => 'precio', 'decimales' => 2, 'digitos_codigo' => 4] + self::CFG)->assertSessionHas('ok');
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $this->ean('200105180000'), 'cantidad' => 1])->assertOk();
        $this->assertEquals(1.6, $o->productos()->where('id_producto', $pan->id)->first()->cantidad); // 0,85 + 0,75

        // balanza apagada: el codigo se busca como codigo comun
        $this->put('/configuracion/panaderia', ['balanza' => false] + self::CFG);
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => $this->ean('200010500850'), 'cantidad' => 1])->assertStatus(422);

        // probar etiqueta desde Configuracion
        $this->postJson('/configuracion/panaderia/probar', ['codigo' => $this->ean('200010501000')] + self::CFG)->assertOk()
            ->assertJsonPath('ok', true)->assertJsonPath('producto.nombre', 'Pan francés')->assertJsonPath('cantidad', 1)->assertJsonPath('importe', 2400);
        $this->postJson('/configuracion/panaderia/probar', ['codigo' => '7790001'] + self::CFG)->assertJsonPath('ok', false);
    }

    public function test_produccion_del_dia_y_cierre(): void
    {
        $this->configurar();
        $pan = $this->pan(['quedan' => 0]);
        $med = $this->producto(['nombre' => 'Medialuna', 'monto' => 500, 'costo' => 200, 'quedan' => 0]);

        $this->post('/produccion/hornear', ['id_producto' => $pan->id, 'cantidad' => 12.5])->assertSessionHas('ok');
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => 48])->assertSessionHas('ok');
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => 24])->assertSessionHas('ok'); // segunda tanda
        $this->assertEquals(12.5, Stock::de($pan->id));
        $this->assertEquals(72, Stock::de($med->id));
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => 1.5])->assertSessionHas('error'); // por unidad
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => 0])->assertSessionHasErrors('cantidad');
        // correccion
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => -2])->assertSessionHas('ok');
        $this->assertEquals(70, Stock::de($med->id));
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => -71])->assertSessionHas('error');
        $this->assertSame(1, Produccion::count());
        $this->assertDatabaseCount('produccion_tandas', 4);

        // ventas del dia
        $o = $this->ventaAbierta();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $med->id, 'cantidad' => 60])->assertOk();
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $pan->id, 'cantidad' => 10])->assertOk();
        $this->post("/ventas/{$o->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();

        $hoy = now()->toDateString();
        $this->get('/produccion')->assertInertia(fn ($p) => $p->component('Produccion/Index', false)->where('esHoy', true)->has('items', 2)
            ->where('items.0.nombre', 'Medialuna')->where('items.0.horneado', 70)->where('items.0.vendido', 60)->where('items.0.stock', 10)
            ->where('items.1.vendido', 10)->where('produccion.cerrada', false)->has('items.0.tandas', 3)->etc());

        // cierre: sobraron 10 medialunas (se descartan) y 2,5 kg de pan (quedan para mañana)
        $this->post('/produccion/cerrar', ['fecha' => $hoy, 'items' => [
            ['id_producto' => $med->id, 'sobrante' => 10, 'descartar' => true],
            ['id_producto' => $pan->id, 'sobrante' => 2.5, 'descartar' => false]]])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->assertEquals(0, Stock::de($med->id));
        $this->assertEquals(2.5, Stock::de($pan->id));
        $this->post('/produccion/cerrar', ['fecha' => $hoy, 'items' => [['id_producto' => $med->id, 'sobrante' => 0]]])->assertSessionHas('error'); // ya cerrado
        $this->post('/produccion/hornear', ['id_producto' => $med->id, 'cantidad' => 5])->assertSessionHas('error'); // dia cerrado

        $this->get('/produccion')->assertInertia(fn ($p) => $p->where('produccion.cerrada', true)->where('items.0.descartado', 10)->where('items.0.vendido', 60)
            ->where('historial.0.fecha', $hoy)->where('historial.0.horneado', 65000)->where('historial.0.descartado', 5000)
            ->where('historial.0.perdida', 2000)->where('historial.0.desperdicio', 7.7)->etc());
        $this->get('/produccion?fecha=2020-01-01')->assertInertia(fn ($p) => $p->where('produccion', null)->where('esHoy', false)->etc());
        $this->get('/produccion?fecha='.now()->addDay()->toDateString())->assertSessionHasErrors('fecha');
    }

    public function test_catalogo_sin_conexion_lleva_la_configuracion(): void
    {
        $this->configurar();
        $this->getJson('/offline/catalogo')->assertOk()->assertJsonPath('panaderia.importe', true)
            ->assertJsonPath('panaderia.etiqueta.prefijos', '20')->assertJsonPath('panaderia.etiqueta.digitos_codigo', 5);
    }
}
