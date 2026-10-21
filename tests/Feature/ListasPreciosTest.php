<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\ListaPrecio;
use App\Models\ProductoProveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ListasPreciosTest extends TestCase
{
    private function csv(string $contenido): UploadedFile
    {
        $f = tempnam(sys_get_temp_dir(), 'lp').'.csv';
        file_put_contents($f, $contenido);

        return new UploadedFile($f, 'lista_norte.csv', 'text/csv', null, true);
    }

    public function test_subir_mapear_revisar_y_aplicar(): void
    {
        $this->actingAs($this->admin);
        $prov = User::create(['nombre' => 'Distribuidora Norte', 'id_uType' => User::TIPO_PROVEEDOR, 'activo' => 1]);
        $coca = $this->producto(['codigo' => '7790001', 'nombre' => 'Coca 500', 'costo' => 1000, 'monto' => 1500]);
        $alfa = $this->producto(['codigo' => 'ALF-9', 'nombre' => 'Alfajor', 'costo' => 400, 'monto' => 800]);
        $agua = $this->producto(['codigo' => 'AGUA', 'nombre' => 'Agua', 'costo' => 500, 'monto' => 900]);

        // deshabilitado
        $this->get('/listas-precios')->assertRedirect('/inicio');
        $this->put('/configuracion/listas-precios', ['habilitadas' => true])->assertSessionHas('ok');
        Configuracion::limpiarCache();
        $this->get('/listas-precios')->assertOk()->assertInertia(fn ($p) => $p->component('ListasPrecios/Index', false)->has('proveedores', 1)->etc());
        $this->get('/listas-precios/ejemplo')->assertOk();

        $csv = "DISTRIBUIDORA NORTE;;\nLista octubre;;\nCodigo;Descripcion;Precio Lista\n7790001;COCA COLA 500;1.100,00\nP-77;ALFAJOR TRIPLE;440\nAGUA;AGUA;500\nNUEVO1;NO EXISTE;99\n;RUBRO BEBIDAS;\n";
        $r = $this->post('/listas-precios', ['id_proveedor' => $prov->id, 'archivo' => $this->csv($csv)]);
        $lista = ListaPrecio::firstOrFail();
        $r->assertRedirect("/listas-precios/{$lista->id}/columnas");

        // detecta la fila de titulos y las columnas
        $this->get("/listas-precios/{$lista->id}/columnas")->assertOk()->assertInertia(fn ($p) => $p
            ->where('opciones.fila_titulos', 2)->where('opciones.codigo', 0)->where('opciones.descripcion', 1)->where('opciones.costo', 2)->etc());

        $this->post("/listas-precios/{$lista->id}/columnas", ['fila_titulos' => 2, 'codigo' => 0, 'descripcion' => 1, 'costo' => 2,
            'descuento' => 0, 'iva' => 0, 'politica' => 'margen', 'margen' => null, 'redondeo' => 10])->assertRedirect("/listas-precios/{$lista->id}");
        $lista->refresh();
        $this->assertSame('pendiente', $lista->estado);
        $this->assertSame(4, $lista->filas);
        $this->assertSame(2, $lista->vinculados); // coca por codigo, agua por codigo; alfajor tiene otro codigo

        $items = $lista->items()->get()->keyBy('codigo');
        $this->assertEquals(1100, $items['7790001']->costo_nuevo);
        $this->assertEquals(1650, $items['7790001']->precio_nuevo); // mantiene el 50% de margen
        $this->assertTrue($items['7790001']->aplicar);
        $this->assertFalse($items['AGUA']->aplicar); // no cambia
        $this->assertNull($items['P-77']->id_producto);

        // vincular a mano el alfajor
        $this->putJson("/listas-precios/{$lista->id}/renglones/{$items['P-77']->id}", ['id_producto' => $alfa->id])
            ->assertOk()->assertJsonPath('producto.id', $alfa->id)->assertJsonPath('precio_nuevo', 880);
        $this->assertTrue(ProductoProveedor::where('codigo_proveedor', 'P-77')->where('id_producto', $alfa->id)->exists());

        // aplicar solo coca y alfajor
        $this->post("/listas-precios/{$lista->id}/aplicar", ['items' => [$items['7790001']->id, $items['P-77']->id]])->assertSessionHas('ok');
        $this->assertEquals(1100, $coca->fresh()->costo);
        $this->assertEquals(1650, $coca->fresh()->monto);
        $this->assertEquals(440, $alfa->fresh()->costo);
        $this->assertEquals(900, $agua->fresh()->monto);
        $this->assertSame('aplicada', $lista->fresh()->estado);
        $this->post("/listas-precios/{$lista->id}/aplicar", ['items' => [$items['7790001']->id]])->assertSessionHas('error');

        // la proxima lista del mismo proveedor reconoce P-77 y recuerda las columnas, con bonificacion e IVA
        $this->post('/listas-precios', ['id_proveedor' => $prov->id, 'archivo' => $this->csv("Codigo;Descripcion;Precio Lista\nP-77;ALFAJOR;500\n")]);
        $l2 = ListaPrecio::latest('id')->first();
        $this->post("/listas-precios/{$l2->id}/columnas", ['fila_titulos' => 0, 'codigo' => 0, 'descripcion' => 1, 'costo' => 2,
            'descuento' => 10, 'iva' => 21, 'politica' => 'fijo', 'margen' => 100, 'redondeo' => 100]);
        $it = $l2->items()->first();
        $this->assertEquals($alfa->id, $it->id_producto);
        $this->assertEquals(544.5, $it->costo_nuevo); // 500 - 10% + 21%
        $this->assertEquals(1100, $it->precio_nuevo); // 1089 redondeado a 100
        $this->post("/listas-precios/{$l2->id}/descartar")->assertSessionHas('ok');
        $this->assertEquals(440, $alfa->fresh()->costo);

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/listas-precios')->assertForbidden();
    }
}
