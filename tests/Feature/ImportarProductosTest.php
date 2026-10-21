<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportarProductosTest extends TestCase
{
    private function xlsx(array $filas): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $w = new Writer();
        $w->openToFile($tmp);
        foreach ($filas as $f) {
            $w->addRow(Row::fromValues($f));
        }
        $w->close();

        return new UploadedFile($tmp, 'productos.xlsx', null, null, true);
    }

    public function test_importa_excel_crea_y_actualiza(): void
    {
        $this->actingAs($this->admin);
        $existente = $this->producto(['codigo' => '111', 'nombre' => 'Viejo', 'monto' => 100, 'quedan' => 7]);

        $f = $this->xlsx([
            ['Código', 'Nombre', 'Categoría', 'Unidad', 'Costo', 'Precio de venta', 'Stock', 'Stock mínimo', 'Activo'],
            [7790895000997, 'Coca 500', 'Gaseosas', 'Uds', 900, 1400, 24, 6, 'Sí'],
            ['2001', 'Jamón cocido', 'Fiambres', 'Kg', 7200, 11000, 5.5, 1, 'Sí'],
            ['111', 'Nuevo nombre', 'Gaseosas', 'Uds', 50, 150, 99, 2, 'Sí'],
            ['', '', '', '', '', '', '', '', ''],
        ]);
        $this->post('/productos/importar', ['archivo' => $f])->assertSessionHas('ok');

        $this->assertSame('Coca 500', Product::where('codigo', '7790895000997')->value('nombre'));
        $jamon = Product::where('codigo', '2001')->first();
        $this->assertEquals(5.5, $jamon->quedan);
        $this->assertSame('Kg', ProductCategory::where('nombre', 'Fiambres')->value('unidad'));
        $existente->refresh();
        $this->assertSame('Nuevo nombre', $existente->nombre);
        $this->assertEquals(150, $existente->monto);
        $this->assertEquals(7, $existente->quedan); // stock no se pisa si no se pide
    }

    public function test_csv_con_punto_y_coma_y_reemplazo_de_stock(): void
    {
        $this->actingAs($this->admin);
        $p = $this->producto(['codigo' => '555', 'quedan' => 3]);
        $csv = "\xEF\xBB\xBFCodigo;Producto;Rubro;Costo;Precio;Stock\n555;Alfajor;Golosinas;\$ 500,50;1.200,00;10\n";
        $f = UploadedFile::fake()->createWithContent('p.csv', $csv);
        $this->post('/productos/importar', ['archivo' => $f, 'actualizar_stock' => 1])->assertSessionHas('ok');
        $p->refresh();
        $this->assertEquals(1200, $p->monto);
        $this->assertEquals(500.5, $p->costo);
        $this->assertEquals(10, $p->quedan);
    }

    public function test_si_hay_errores_no_importa_nada(): void
    {
        $this->actingAs($this->admin);
        $f = $this->xlsx([
            ['Código', 'Nombre', 'Precio de venta', 'Stock'],
            ['1', 'Bien', 100, 2],
            ['2', '', 'abc', 1.5],
            ['1', 'Repetido', 100, 1],
        ]);
        $r = $this->post('/productos/importar', ['archivo' => $f])->assertSessionHas('error');
        $this->assertSame(0, Product::count());
        $this->assertCount(2, session('importacion')['errores']);

        $sinColumnas = $this->xlsx([['Algo', 'Otra'], ['1', '2']]);
        $this->post('/productos/importar', ['archivo' => $sinColumnas])->assertSessionHas('error');
        $this->post('/productos/importar', ['archivo' => UploadedFile::fake()->createWithContent('x.php', '<?php')])->assertSessionHasErrors('archivo');
    }

    public function test_exportar_y_plantilla_y_permisos(): void
    {
        $this->actingAs($this->admin);
        $this->producto();
        $this->get('/productos/exportar?formato=xlsx')->assertOk();
        $this->get('/productos/exportar?formato=csv')->assertOk();
        $this->get('/productos/plantilla')->assertOk();

        $this->actingAs($this->encargado('Empleado'));
        $this->get('/productos/exportar?formato=xlsx')->assertForbidden();
        $this->post('/productos/importar')->assertForbidden();
    }

    public function test_el_modelo_se_puede_importar_tal_cual(): void
    {
        $this->actingAs($this->admin);
        $r = $this->get('/productos/plantilla')->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'm').'.xlsx';
        copy($r->baseResponse->getFile()->getPathname(), $tmp);
        $this->post('/productos/importar', ['archivo' => new UploadedFile($tmp, 'modelo.xlsx', null, null, true)])->assertSessionHas('ok');
        $this->assertSame(4, Product::count());
        $this->assertEquals(5.5, Product::where('codigo', '2001')->value('quedan'));
    }
}
