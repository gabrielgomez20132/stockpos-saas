<?php

namespace Tests\Feature;

use App\Models\Afip;
use App\Models\Atributo;
use App\Models\CierreCaja;
use App\Models\Combo;
use App\Models\Configuracion;
use App\Models\Devolucion;
use App\Models\ListaPrecio;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sucursal;
use App\Models\Transferencia;
use App\Models\User;
use App\Support\SucursalActual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Recorre TODAS las pantallas y llamadas GET del sistema con datos de todo
 * tipo (variantes, combos, fiado, devoluciones, cierres, listas, sucursales),
 * en Casa Central y en otra sucursal: ninguna puede dar error 500.
 */
class RecorridoCompletoTest extends TestCase
{
    private array $ids = [];

    private function armarDatos(): void
    {
        $this->actingAs($this->admin);
        $this->put('/configuracion/variantes', ['habilitadas' => true]);
        $this->put('/configuracion/listas-precios', ['habilitadas' => true]);
        $this->put('/configuracion/multisucursal', ['habilitado' => true]);
        $this->put('/configuracion/presupuestos', ['habilitadas' => true]);
        $this->put('/configuracion/remitos', ['habilitadas' => true]);
        $this->put('/configuracion/combos', ['habilitadas' => true]);
        $this->put('/configuracion/afip', ['habilitada' => true]);
        $this->put('/configuracion/panaderia', ['habilitada' => true, 'importe' => true, 'balanza' => true, 'produccion' => true, 'prefijos' => '20', 'digitos_codigo' => 5, 'tipo' => 'peso', 'decimales' => 3, 'verificar' => true]);
        $this->post('/configuracion/sucursales', ['nombre' => 'Norte']);
        Configuracion::limpiarCache();
        $norte = Sucursal::where('nombre', 'Norte')->first();

        $coca = $this->producto(['codigo' => '7790001', 'nombre' => 'Coca', 'monto' => 1500, 'costo' => 900, 'quedan' => 50]);
        $jamon = $this->producto(['_cat' => 'Fiambres', '_unidad' => 'Kg', 'codigo' => 'JAM', 'nombre' => 'Jamón', 'monto' => 9000, 'costo' => 6000, 'quedan' => 5.5]);
        $hielo = $this->producto(['codigo' => 'HIE', 'nombre' => 'Hielo', 'monto' => 800, 'quedan' => 30]);
        $remera = $this->producto(['_cat' => 'Ropa', 'codigo' => 'REM', 'nombre' => 'Remera', 'monto' => 10000, 'quedan' => 0]);
        $talle = Atributo::where('nombre', 'Talle')->value('id');
        $this->post("/productos/{$remera->id}/variantes", ['seleccion' => ['Talle' => ['S', 'M']]]);
        $variantes = $remera->variantes()->get();
        $this->put("/productos/{$remera->id}/variantes", ['variantes' => $variantes->map(fn ($v) => ['id' => $v->id, 'codigo' => $v->codigo, 'costo' => 5000, 'monto' => 10000, 'quedan' => 4, 'aviso' => 1, 'activo' => true])->all()]);
        $this->post('/productos/multiples', ['nombre' => 'Buzo', 'id_categoria' => $remera->id_categoria, 'costo' => 1, 'monto' => 2,
            'filas' => [['valores' => [$talle => 'L'], 'cantidad' => 2, 'aviso' => 0]]]);
        $this->post('/combos', ['nombre' => 'Fernet promo', 'codigo' => 'PROMO1', 'precio' => 2000, 'activo' => true,
            'items' => [['id_producto' => $coca->id, 'cantidad' => 1], ['id_producto' => $hielo->id, 'cantidad' => 1]]]);
        $combo = Combo::first();
        $cliente = User::create(['nombre' => 'Luis', 'id_uType' => User::TIPO_CLIENTE, 'activo' => 1]);
        $prov = User::create(['nombre' => 'Distribuidora', 'id_uType' => User::TIPO_PROVEEDOR, 'activo' => 1]);
        $enc = $this->encargado('Encargado');

        // turno 1: ventas de todo tipo y cierre
        $this->post('/caja/movimientos', ['id_desc' => 1, 'monto' => 5000]);
        $this->post('/ventas');
        $o1 = Order::latest('id')->first();
        $this->postJson("/ventas/{$o1->id}/items", ['codigo' => 'PROMO1', 'cantidad' => 1])->assertOk();
        $this->postJson("/ventas/{$o1->id}/items", ['product_id' => $jamon->id, 'cantidad' => 0.35])->assertOk();
        $this->postJson("/ventas/{$o1->id}/items", ['product_id' => $variantes[0]->id, 'cantidad' => 1])->assertOk();
        $this->postJson("/ventas/{$o1->id}/servicios", ['descripcion' => 'Carga', 'monto' => 500]);
        $this->post("/ventas/{$o1->id}/cobrar", ['id_cliente' => 2, 'canal_id' => 1, 'medios' => ['efectivo', 'debito'], 'monto_primero' => 3000, 'descuento_pct' => 5])->assertSessionHasNoErrors();
        $this->post('/ventas');
        $o2 = Order::latest('id')->first();
        $this->postJson("/ventas/{$o2->id}/items", ['product_id' => $coca->id, 'cantidad' => 3])->assertOk();
        $this->post("/ventas/{$o2->id}/cobrar", ['id_cliente' => $cliente->id, 'canal_id' => 1, 'medios' => ['fiado']])->assertSessionHasNoErrors();
        $this->post("/clientes/{$cliente->id}/pago", ['monto' => 1000]);
        $this->post('/devoluciones', ['id_order' => $o1->id, 'productos' => [['id_producto' => $coca->id, 'cantidad' => 1]]])->assertSessionHasNoErrors();
        $this->post('/caja/movimientos', ['id_desc' => 6, 'monto' => 100]);
        $this->post('/caja/movimientos', ['id_desc' => 7, 'monto' => 200, 'id_proveedor' => $prov->id]);
        $this->post("/proveedores/{$prov->id}/compra", ['monto' => 5000, 'detalle' => 'FC 1']);
        $this->post('/caja/cerrar')->assertSessionHasNoErrors();
        // turno 2 abierto con una venta pendiente
        $this->post('/caja/movimientos', ['id_desc' => 1, 'monto' => 1000]);
        $this->post('/ventas');
        $o3 = Order::latest('id')->first();
        $this->postJson("/ventas/{$o3->id}/items", ['product_id' => $hielo->id, 'cantidad' => 1]);

        $afip = Afip::create(['cbteFch' => now()->format('Ymd'), 'tipoCbteNum' => 6, 'nroCbte' => 1, 'caeNum' => '12345678901234', 'caeFvt' => now()->addDays(10)->format('Ymd'),
            'docTipo' => 99, 'docNro' => 0, 'nombreRS' => 'Consumidor final', 'tipoPago' => 'EFTV', 'impNeto' => 1000, 'impIVA' => 210, 'descuento' => 0, 'impTotal' => 1210, 'pagada' => 1, 'concepto' => 'P']);
        $o1->update(['idAfipFct' => $afip->id]);

        // lista de precios y transferencia
        $f = tempnam(sys_get_temp_dir(), 'lp').'.csv';
        file_put_contents($f, "Codigo;Descripcion;Precio\n7790001;COCA;1000\nXX;NUEVO;5\n");
        $this->post('/listas-precios', ['id_proveedor' => $prov->id, 'archivo' => new UploadedFile($f, 'l.csv', 'text/csv', null, true)]);
        $lista = ListaPrecio::first();
        $this->post("/listas-precios/{$lista->id}/columnas", ['fila_titulos' => 0, 'codigo' => 0, 'descripcion' => 1, 'costo' => 2, 'descuento' => 0, 'iva' => 21, 'politica' => 'margen', 'redondeo' => 10]);
        $this->post('/transferencias', ['destino' => $norte->id, 'items' => [['id_producto' => $coca->id, 'cantidad' => 5]]])->assertSessionHasNoErrors();
        $this->postJson('/etiquetas/preparar', ['items' => [['id' => $coca->id, 'copias' => 2]], 'config' => \App\Http\Controllers\EtiquetaController::DEFECTO])->assertOk();

        // produccion del dia (abierta) en Casa Central
        $this->post('/produccion/hornear', ['id_producto' => $jamon->id, 'cantidad' => 2.5])->assertSessionHas('ok');
        // presupuesto y remitos (de venta y suelto)
        $this->post('/presupuestos', ['validez_dias' => 10, 'descuento' => 100, 'cliente_nombre' => 'Obra Pérez', 'items' => [
            ['id_producto' => $coca->id, 'descripcion' => 'Coca', 'cantidad' => 2, 'precio' => 1500], ['descripcion' => 'Flete', 'cantidad' => 1, 'precio' => 3000]]])->assertSessionHasNoErrors();
        $this->post('/remitos', ['id_order' => $o2->id, 'items' => [['id_producto' => $coca->id, 'descripcion' => 'Coca', 'cantidad' => 2, 'precio' => 1500]]])->assertSessionHasNoErrors();
        $this->post('/remitos', ['id_cliente' => $cliente->id, 'items' => [['id_producto' => $hielo->id, 'descripcion' => 'Hielo', 'cantidad' => 2, 'precio' => 800]]])->assertSessionHasNoErrors();

        $this->assertTrue($o1->fresh()->completada);
        $this->assertGreaterThan(0, $o2->fresh()->fiado);
        $this->assertSame('pendiente', $lista->fresh()->estado);
        $this->assertTrue(Product::where('nombre', 'Buzo')->exists());
        $this->assertSame(1, CierreCaja::count());
        $this->assertEquals(5, \App\Support\Stock::de($coca->id, $norte->id));

        $this->ids = [
            'afip' => $afip->id, 'cierre' => CierreCaja::first()->id, 'tipo' => 'varios', 'categoria' => $coca->id_categoria,
            'cliente' => $cliente->id, 'combo' => $combo->id, 'order' => $o1->id, 'devolucion' => Devolucion::first()->id,
            'encargado' => $enc->id, 'lista' => $lista->id, 'producto' => $remera->id, 'proveedor' => $prov->id,
            'transferencia' => Transferencia::first()->id, 'presupuesto' => \App\Models\Presupuesto::first()->id, 'remito' => \App\Models\Remito::first()->id, 'norte' => $norte->id, 'pendiente' => $o3->id, 'jamon' => $jamon->id,
        ];
    }

    private function recorrer(string $donde): array
    {
        $consulta = [
            'reportes/datos' => '?periodo=semana', 'productos/exportar' => '?formato=xlsx', 'ventas/exportar' => '?formato=csv&desde='.now()->subDays(3)->toDateString().'&hasta='.now()->toDateString(),
            'etiquetas/barra' => '?codigo=7790001', 'etiquetas/buscar' => '?q=co', 'buscar/productos' => '?q=co', 'buscar/clientes' => '?q=lu',
            'buscar/vales/codigo' => '?codigo=0000', 'presupuestos/productos' => '?q=co', 'produccion/productos' => '?q=co', 'produccion' => '?fecha='.now()->toDateString(), 'remitos/productos' => '?q=co', 'presupuestos/clientes' => '?q=lu',
            'remitos/clientes' => '?q=lu', 'remitos/nuevo' => '?venta=2', 'remitos/{remito}/imprimir' => '?precios=1', 'presupuestos/nuevo' => '?copiar=1', 'transferencias/productos' => '?q=co', 'listas-precios/productos' => '?q=co',
            'ventas' => '?desde='.now()->subDays(3)->toDateString().'&hasta='.now()->toDateString(),
            'caja/movimientos' => '?desde='.now()->subDays(3)->toDateString().'&hasta='.now()->toDateString(),
        ];
        $errores = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true) || in_array($r->uri(), ['login', 'up', '/'], true)) {
                continue;
            }
            $uri = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $this->ids[$m[1]] ?? 1, $r->uri());
            $url = '/'.ltrim($uri, '/').($consulta[$r->uri()] ?? '');
            $resp = str_contains($r->uri(), 'buscar') || str_contains($r->uri(), 'datos') || str_contains($r->uri(), 'items') || str_contains($r->uri(), 'productos') && ! str_starts_with($r->uri(), 'productos')
                ? $this->getJson($url) : $this->get($url);
            if ($resp->getStatusCode() >= 500) {
                $errores[] = "[$donde] $url -> ".$resp->getStatusCode().' '.mb_substr(strip_tags((string) $resp->getContent()), 0, 300);
            }
            // ninguna pantalla puede pisar los datos compartidos del menu (sucursal, modulos, permisos)
            $vista = $resp->getStatusCode() === 200 ? $resp->baseResponse->original ?? null : null;
            if ($vista instanceof \Illuminate\View\View && is_array($pagina = $vista->getData()['page'] ?? null) && ! str_starts_with($pagina['component'] ?? '', 'Print/')) { // las hojas para imprimir no tienen menu
                $props = $pagina['props'] ?? [];
                if (SucursalActual::habilitado() && ! isset($props['sucursal']['id'], $props['sucursal']['lista'])) {
                    $errores[] = "[$donde] $url pisa la prop 'sucursal' del menu";
                }
                if (! is_array($props['modulos'] ?? null) || ! is_array($props['auth']['permisos'] ?? null)) {
                    $errores[] = "[$donde] $url pisa 'modulos' o 'auth'";
                }
            }
        }
        // las mismas ventas con su detalle
        foreach (['order', 'pendiente'] as $k) {
            foreach (["/ventas/{$this->ids[$k]}", "/ventas/{$this->ids[$k]}/ticket"] as $url) {
                if ($this->get($url)->getStatusCode() >= 500) {
                    $errores[] = "[$donde] $url";
                }
            }
        }

        return $errores;
    }

    public function test_ninguna_pantalla_da_error_en_central_ni_en_sucursal(): void
    {
        $this->armarDatos();
        $errores = $this->recorrer('central');

        // las mismas pantallas parado en la otra sucursal
        $this->withSession(['sucursal_id' => $this->ids['norte']]);
        $this->post('/sucursal', ['id' => $this->ids['norte']]);
        $this->get('/inicio')->assertInertia(fn ($p) => $p->where('sucursal.nombre', 'Norte')->etc());
        $this->get('/productos')->assertOk()->assertInertia(fn ($p) => $p->where('productos.data', fn ($d) => collect($d)->firstWhere('nombre', 'Remera')['variantes_count'] === 2)->etc());
        $errores = array_merge($errores, $this->recorrer('Norte'));

        // y como empleado (sin permisos de administracion): nada de 500
        SucursalActual::fijar(null);
        $this->actingAs($this->encargado('Empleado'))->withSession(['sucursal_id' => 1]);
        $errores = array_merge($errores, $this->recorrer('empleado'));

        $this->assertSame([], $errores, implode("\n", $errores));
    }
}
