<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Order;
use App\Models\Product;
use Tests\TestCase;

class VariantesTest extends TestCase
{
    private function remera(): Product
    {
        return $this->producto(['_cat' => 'Ropa', 'codigo' => 'REM1', 'nombre' => 'Remera básica', 'monto' => 12000, 'costo' => 6000, 'quedan' => 0]);
    }

    public function test_configuracion_habilita_y_atributos(): void
    {
        $this->actingAs($this->admin);
        $this->get('/configuracion')->assertOk()->assertInertia(fn ($p) => $p->where('variantes', false)->has('atributos', 3)->etc());
        $this->put('/configuracion/variantes', ['habilitadas' => true])->assertSessionHas('ok');
        Configuracion::limpiarCache();
        $this->assertTrue(Configuracion::variantes());
        $this->post('/configuracion/atributos', ['nombre' => 'Número'])->assertSessionHasNoErrors();
        $id = \App\Models\Atributo::where('nombre', 'Número')->value('id');
        $this->post("/configuracion/atributos/{$id}/valores", ['valor' => '42'])->assertSessionHasNoErrors();
        $this->post("/configuracion/atributos/{$id}/valores", ['valor' => '42'])->assertSessionHasErrors('valor');

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/configuracion')->assertForbidden();
    }

    public function test_generar_variantes_y_venderlas(): void
    {
        $this->actingAs($this->admin);
        Configuracion::set('variantes_habilitadas', true);
        $base = $this->remera();

        $this->post("/productos/{$base->id}/variantes", ['seleccion' => ['Talle' => ['S', 'M'], 'Color' => ['Negro', 'Blanco']]])->assertSessionHas('ok');
        $base->refresh();
        $this->assertTrue($base->tiene_variantes);
        $this->assertSame(4, $base->variantes()->count());
        $m = $base->variantes()->get()->first(fn ($v) => $v->variante === ['Talle' => 'M', 'Color' => 'Negro']);
        $this->assertSame('Remera básica - M / Negro', $m->nombre);

        // repetir no duplica; agregar un talle crea solo las nuevas
        $this->post("/productos/{$base->id}/variantes", ['seleccion' => ['Talle' => ['S', 'M', 'L'], 'Color' => ['Negro', 'Blanco']]]);
        $this->assertSame(6, $base->variantes()->count());

        // cargar stock / precio desde la tabla
        $filas = $base->variantes()->get()->map(fn ($v) => ['id' => $v->id, 'codigo' => $v->codigo, 'costo' => 6000, 'monto' => 13000, 'quedan' => 5, 'aviso' => 1, 'activo' => true])->all();
        $this->put("/productos/{$base->id}/variantes", ['variantes' => $filas])->assertSessionHas('ok');
        $this->assertEquals(5, $m->fresh()->quedan);

        // en la venta: el base no se vende, su codigo ofrece las variantes, y la variante se vende como producto
        $this->abrirCaja();
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        $this->postJson("/ventas/{$o->id}/items", ['codigo' => 'REM1', 'cantidad' => 1])->assertOk()->assertJsonCount(6, 'elegir');
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $base->id, 'cantidad' => 1])->assertStatus(422);
        $this->postJson("/ventas/{$o->id}/items", ['product_id' => $m->id, 'cantidad' => 2])->assertOk()->assertJsonPath('monto', 26000);
        $this->assertEquals(5, $m->fresh()->quedan); // se descuenta al cobrar
        $this->getJson('/buscar/productos?q=Remera')->assertJsonCount(6);

        // cambiar el nombre del base renombra las variantes
        $this->put("/productos/{$base->id}", ['nombre' => 'Remera lisa', 'codigo' => 'REM1', 'id_categoria' => $base->id_categoria, 'costo' => 6000, 'monto' => 12000, 'aviso' => 0, 'quedan' => 99])->assertSessionHasNoErrors();
        $this->assertSame('Remera lisa - M / Negro', $m->fresh()->nombre);
        $this->assertEquals(0, $base->fresh()->quedan); // el base no tiene stock propio

        // stock bajo no muestra el base
        $this->get('/productos?stock=sin')->assertInertia(fn ($p) => $p->where('productos.total', 0)->etc());
    }

    public function test_no_crea_variantes_si_el_producto_tiene_stock_y_codigo_repetido(): void
    {
        $this->actingAs($this->admin);
        $base = $this->producto(['quedan' => 3]);
        $this->post("/productos/{$base->id}/variantes", ['seleccion' => ['Talle' => ['S']]])->assertSessionHas('error');

        $r = $this->remera();
        $this->post("/productos/{$r->id}/variantes", ['seleccion' => ['Talle' => ['S', 'M']]]);
        [$a, $b] = $r->variantes()->get()->all();
        $this->put("/productos/{$r->id}/variantes", ['variantes' => [
            ['id' => $a->id, 'codigo' => $base->codigo, 'costo' => 1, 'monto' => 1, 'quedan' => 1, 'aviso' => 0, 'activo' => true],
        ]])->assertSessionHas('error');
        $this->put("/productos/{$r->id}/variantes", ['variantes' => [
            ['id' => $base->id, 'codigo' => 'X', 'costo' => 1, 'monto' => 1, 'quedan' => 1, 'aviso' => 0, 'activo' => true],
        ]])->assertSessionHasErrors('variantes.0.id'); // no se puede editar un producto ajeno por esta ruta
    }

    public function test_multiples_productos_crea_base_y_variantes(): void
    {
        \Illuminate\Support\Facades\Storage::fake('uploads');
        $this->actingAs($this->admin);
        $cat = \App\Models\ProductCategory::firstOrCreate(['nombre' => 'Ropa'], ['unidad' => 'Uds', 'activa' => 1]);
        $talle = \App\Models\Atributo::where('nombre', 'Talle')->value('id');
        $color = \App\Models\Atributo::where('nombre', 'Color')->value('id');

        // deshabilitadas: no hay boton ni pagina
        $this->get('/productos')->assertInertia(fn ($p) => $p->where('variantesHabilitadas', false)->etc());
        $this->get('/productos/multiples')->assertRedirect('/productos');

        Configuracion::set('variantes_habilitadas', true);
        $this->get('/productos')->assertInertia(fn ($p) => $p->where('variantesHabilitadas', true)->etc());
        $this->get('/productos/multiples')->assertOk()->assertInertia(fn ($p) => $p->component('Productos/Multiples', false)->has('atributos', 3)->etc());

        $foto = \Illuminate\Http\UploadedFile::fake()->image('negra.jpg', 50, 50);
        $datos = ['nombre' => 'Remera lisa', 'codigo' => '', 'id_categoria' => $cat->id, 'costo' => 5000, 'monto' => 10000, 'filas' => [
            ['valores' => [$talle => 'M', $color => 'Negro'], 'codigo' => '779000111', 'cantidad' => 4, 'aviso' => 1, 'archivo' => $foto],
            ['valores' => [$talle => 'L', $color => 'Negro'], 'codigo' => '', 'cantidad' => 2, 'aviso' => 1],
            ['valores' => [$talle => 'S'], 'codigo' => '', 'cantidad' => 0, 'aviso' => 0],
        ]];
        $this->post('/productos/multiples', $datos)->assertSessionHasNoErrors()->assertSessionHas('ok');

        $base = Product::where('nombre', 'Remera lisa')->firstOrFail();
        $this->assertTrue($base->tiene_variantes);
        $this->assertEquals(0, $base->quedan);
        $vs = $base->variantes()->orderBy('id')->get();
        $this->assertCount(3, $vs);
        $this->assertSame('Remera lisa - M / Negro', $vs[0]->nombre);
        $this->assertSame('779000111', $vs[0]->codigo);
        $this->assertSame($base->codigo.'-1', $vs[1]->codigo);
        $this->assertSame(['Talle' => 'S'], $vs[2]->variante);
        $this->assertEquals(4, $vs[0]->quedan);
        $this->assertEquals(10000, $vs[1]->monto);
        $this->assertNotSame($vs[0]->archivo, $base->archivo); // copia propia
        \Illuminate\Support\Facades\Storage::disk('uploads')->assertExists([$vs[0]->archivo, $base->archivo]);
        $this->assertSame($base->archivo, $vs[1]->archivo);

        // cambiar la foto del base no pisa la foto propia de la variante
        $this->put("/productos/{$base->id}", ['nombre' => 'Remera lisa', 'codigo' => $base->codigo, 'id_categoria' => $cat->id, 'costo' => 5000, 'monto' => 10000, 'aviso' => 0, 'quedan' => 0,
            'archivo' => \Illuminate\Http\UploadedFile::fake()->image('nueva.png', 40, 40)])->assertSessionHasNoErrors();
        $base->refresh();
        $this->assertSame($vs[0]->archivo, $vs[0]->fresh()->archivo);
        $this->assertSame($base->archivo, $vs[1]->fresh()->archivo);

        // errores: repetidas, valor inexistente, codigo usado, fraccion en unidades
        $mal = ['nombre' => 'Buzo', 'id_categoria' => $cat->id, 'costo' => 1, 'monto' => 2];
        $this->post('/productos/multiples', $mal + ['filas' => [['valores' => [$talle => 'M'], 'cantidad' => 1, 'aviso' => 0], ['valores' => [$talle => 'M'], 'cantidad' => 1, 'aviso' => 0]]])->assertSessionHas('error');
        $this->post('/productos/multiples', $mal + ['filas' => [['valores' => [$talle => 'XXXL9'], 'cantidad' => 1, 'aviso' => 0]]])->assertSessionHasErrors("filas.0.valores.{$talle}");
        $this->post('/productos/multiples', $mal + ['filas' => [['valores' => [$talle => 'M'], 'codigo' => '779000111', 'cantidad' => 1, 'aviso' => 0]]])->assertSessionHasErrors('filas.0.codigo');
        $this->post('/productos/multiples', $mal + ['filas' => [['valores' => [$talle => 'M'], 'cantidad' => 1.5, 'aviso' => 0]]])->assertSessionHasErrors('filas.0.cantidad');
        $this->post('/productos/multiples', $mal + ['filas' => [['valores' => [], 'cantidad' => 1, 'aviso' => 0]]])->assertSessionHas('error');
        $this->assertFalse(Product::where('nombre', 'like', 'Buzo%')->exists());

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/productos/multiples')->assertForbidden();
    }
}
