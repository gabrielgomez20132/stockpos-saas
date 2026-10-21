<?php

namespace Tests\Feature;

use App\Models\Control;
use App\Models\Deuda;
use App\Models\Devolucion;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Vale;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class GestionTest extends TestCase
{
    private function ventaCobrada(array $productos, array $cobro = []): Order
    {
        $this->post('/ventas');
        $o = Order::latest('id')->first();
        foreach ($productos as [$p, $c]) {
            $this->postJson("/ventas/{$o->id}/items", ['product_id' => $p->id, 'cantidad' => $c])->assertOk();
        }
        $this->post("/ventas/{$o->id}/cobrar", $cobro + ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo']])->assertSessionHasNoErrors();

        return $o->fresh();
    }

    public function test_devolucion_genera_vale_prorrateando_el_descuento(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja();
        $p = $this->producto(['monto' => 1000, 'quedan' => 10]);
        $o = $this->ventaCobrada([[$p, 4]], ['descuento_pct' => 25]); // pago 3000 por 4

        $this->post('/devoluciones', ['id_order' => $o->id, 'productos' => [['id_producto' => $p->id, 'cantidad' => 2]]])->assertSessionHasNoErrors();
        $d = Devolucion::first();
        $this->assertEquals(1500, $d->monto_total);
        $this->assertEquals(8, $p->fresh()->quedan);
        $this->assertEquals(1500, Vale::first()->monto_disponible);

        // no se puede devolver mas de lo vendido (quedan 2)
        $this->post('/devoluciones', ['id_order' => $o->id, 'productos' => [['id_producto' => $p->id, 'cantidad' => 3]]])->assertSessionHas('error');
        // ni un producto que no esta en la venta
        $otro = $this->producto();
        $this->post('/devoluciones', ['id_order' => $o->id, 'productos' => [['id_producto' => $otro->id, 'cantidad' => 1]]])->assertSessionHas('error');
        $this->get("/devoluciones/{$d->id}")->assertOk();
        $this->get("/devoluciones/{$d->id}/vale")->assertOk();

        // buscador: por Nº de venta, cliente, fecha y codigo de vale
        $cuenta = fn ($q) => $this->get('/devoluciones?q='.urlencode($q))->assertOk()->viewData('page')['props']['devoluciones']['total'];
        $this->assertSame(1, $cuenta((string) $o->id));
        $this->assertSame(1, $cuenta('#'.$o->id));
        $this->assertSame(0, $cuenta('99999'));
        $this->assertSame(1, $cuenta('general'));
        $this->assertSame(1, $cuenta(now()->format('d/m')));
        $this->assertSame(1, $cuenta(now()->format('d/m/y')));
        $this->assertSame(1, $cuenta(now()->format('Y-m-d')));
        $this->assertSame(0, $cuenta(now()->subDays(3)->format('d/m/Y')));
        $this->assertSame(1, $cuenta(Vale::first()->codigo_vale));
    }

    public function test_cuenta_corriente_de_proveedor_y_pago_desde_caja(): void
    {
        $this->actingAs($this->admin);
        $this->abrirCaja(5000);
        $prov = User::create(['nombre' => 'Distribuidora', 'id_uType' => User::TIPO_PROVEEDOR, 'activo' => 1]);
        $this->post("/proveedores/{$prov->id}/compra", ['monto' => 10000, 'detalle' => 'FC 1'])->assertSessionHasNoErrors();
        $this->post("/proveedores/{$prov->id}/pago", ['monto' => 4000, 'medio' => 'caja'])->assertSessionHasNoErrors();
        $this->post("/proveedores/{$prov->id}/pago", ['monto' => 1000, 'medio' => 'otro'])->assertSessionHasNoErrors();
        $this->assertEquals(5000, Deuda::saldo('proveedor', $prov->id));
        // solo el pago "de la caja" baja el efectivo; el pago a proveedor NO cuenta como cobro de deuda
        $this->get('/caja/movimientos')->assertInertia(fn ($pg) => $pg->where('datos.total_efec', 1000)->where('datos.ingXpago_deudas', 0)->etc());
        // la ruta de clientes no acepta un proveedor
        $this->get("/clientes/{$prov->id}/edit")->assertNotFound();
    }

    public function test_no_se_puede_escalar_privilegios(): void
    {
        $dueno = $this->encargado('Dueño');
        $soloEnc = $this->encargado('Empleado');
        // le damos permiso de encargados al rol Empleado
        $emp = Role::where('name', 'Empleado')->first();
        $emp->permissions()->attach(\App\Models\Permission::where('slug', 'encargados')->value('id'));
        $this->actingAs($soloEnc->fresh());

        // no puede cambiarle la clave al dueño (tiene mas permisos)
        $this->put("/encargados/{$dueno->id}", ['nombre' => 'X', 'dni' => $dueno->dni, 'password' => 'hackeo1', 'password_confirmation' => 'hackeo1'])->assertForbidden();
        // ni al administrador (id 1) por la ruta
        $this->get('/encargados/1/edit')->assertNotFound();

        // alguien con roles-permisos pero sin ser Super admin no puede darse Super admin
        $this->actingAs($dueno);
        $super = Role::where('name', Role::SUPER_ADMIN)->value('id');
        $this->put("/roles/usuarios/{$dueno->id}", ['roles' => [$super]])->assertSessionHasNoErrors();
        $this->assertFalse($dueno->fresh()->isSuperAdmin());
        // ni sacarle el Super admin al administrador
        $this->put('/roles/usuarios/1', ['roles' => []]);
        $this->assertTrue($this->admin->fresh()->isSuperAdmin());
        // ni editar los permisos del rol Super admin
        $this->put("/roles/{$super}/permisos", ['permisos' => []])->assertSessionHas('error');
    }

    public function test_productos_foto_validada_y_baja_si_tiene_ventas(): void
    {
        $this->actingAs($this->admin);
        $cat = \App\Models\ProductCategory::create(['nombre' => 'Varios', 'unidad' => 'Uds', 'activa' => 1]);
        $datos = ['nombre' => 'Alfajor', 'codigo' => '779001', 'id_categoria' => $cat->id, 'costo' => 500, 'monto' => 900, 'aviso' => 2, 'stock_inicial' => 10];

        // un PHP disfrazado de imagen se rechaza
        $this->post('/productos', $datos + ['archivo' => UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;')])->assertSessionHasErrors('archivo');
        $this->post('/productos', $datos + ['archivo' => UploadedFile::fake()->image('a.png', 50, 50)])->assertSessionHasNoErrors();
        $p = Product::where('codigo', '779001')->first();
        $this->assertMatchesRegularExpression('/^p-[a-z0-9]{20}\.png$/', $p->archivo);
        @unlink(public_path('uploads/'.$p->archivo));

        $this->post('/productos', $datos)->assertSessionHasErrors('codigo'); // codigo repetido

        $this->abrirCaja();
        $this->ventaCobrada([[$p, 1]]);
        $this->delete("/productos/{$p->id}");
        $this->assertNotNull($p->fresh());
        $this->assertFalse($p->fresh()->activo);
        $this->getJson('/buscar/productos?q=Alfajor')->assertJsonCount(0);
    }

    public function test_actualizar_precios_por_porcentaje(): void
    {
        $this->actingAs($this->admin);
        $p = $this->producto(['costo' => 100, 'monto' => 155]);
        $this->post('/productos/precios', ['porcentaje' => 10, 'costo' => true])->assertSessionHasNoErrors();
        $this->assertEquals(171, $p->fresh()->monto); // ceil(170.5)
        $this->assertEquals(110, $p->fresh()->costo);
    }

    public function test_busqueda_no_rompe_con_comodines(): void
    {
        if (\DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('El escape de LIKE con \\ es el de MySQL.');
        }
        $this->actingAs($this->admin);
        $this->producto(['nombre' => 'Agua 100% natural']);
        $this->producto(['nombre' => 'Otra cosa']);
        $this->getJson('/buscar/productos?q=%25')->assertJsonCount(1);
    }
}
