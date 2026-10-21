<?php

use App\Http\Controllers\AfipConfigController;
use App\Http\Controllers\AfipController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BusquedaController;
use App\Http\Controllers\CajaController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ComboController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\VarianteController;
use App\Http\Controllers\DevolucionController;
use App\Http\Controllers\EncargadoController;
use App\Http\Controllers\EtiquetaController;
use App\Http\Controllers\InicioController;
use App\Http\Controllers\ListaPrecioController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\SucursalController;
use App\Http\Controllers\TransferenciaController;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\PresupuestoController;
use App\Http\Controllers\ProduccionController;
use App\Http\Controllers\RemitoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\ReporteController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\VentaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/inicio');

// ------------------------------------------------------------------ acceso
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:login');
});
Route::post('logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'staff'])->group(function () {
    Route::get('inicio', InicioController::class)->name('inicio');

    Route::get('perfil', [PerfilController::class, 'edit'])->name('perfil');
    // venta sin conexion
    Route::get('offline/ping', [OfflineController::class, 'ping'])->name('offline.ping');
    Route::get('offline/catalogo', [OfflineController::class, 'catalogo'])->name('offline.catalogo');
    Route::post('offline/ventas', [OfflineController::class, 'sincronizar'])->middleware('throttle:60,1')->name('offline.ventas');
    Route::post('sucursal', [SucursalController::class, 'cambiar'])->middleware('modulo:multisucursal')->name('sucursal.cambiar');
    Route::put('perfil/clave', [PerfilController::class, 'clave'])->name('perfil.clave');

    // ---------------------------------------------------------------- caja
    // (abrir caja, retiros y cierre: cualquier encargado, como en control-soft)
    Route::prefix('caja')->name('caja.')->group(function () {
        Route::get('/', [CajaController::class, 'inicial'])->name('inicial');
        Route::get('retiros', [CajaController::class, 'retiros'])->name('retiros');
        Route::post('movimientos', [CajaController::class, 'guardar'])->name('guardar');
        Route::delete('movimientos/{control}', [CajaController::class, 'borrar'])->name('borrar');
        Route::post('cerrar', [CajaController::class, 'cerrar'])->name('cerrar');
        Route::get('cierres', [CajaController::class, 'cierres'])->name('cierres');
        Route::get('cierres/{cierre}', [CajaController::class, 'verCierre'])->name('cierre');

        Route::middleware('permiso:control')->group(function () {
            Route::get('gastos/{tipo}', [CajaController::class, 'gastos'])->whereIn('tipo', ['varios', 'servicios', 'proveedores'])->name('gastos');
            Route::get('movimientos', [CajaController::class, 'movimientos'])->name('movimientos');
        });
    });

    // -------------------------------------------------------------- ventas
    Route::prefix('ventas')->name('ventas.')->group(function () {
        Route::get('/', [VentaController::class, 'index'])->name('index');
        Route::post('/', [VentaController::class, 'nueva'])->name('nueva');
        Route::get('exportar', [VentaController::class, 'exportar'])->middleware('permiso:control')->name('exportar');
        Route::get('{order}', [VentaController::class, 'show'])->name('show');
        Route::delete('{order}', [VentaController::class, 'anular'])->middleware('permiso:anular-ventas')->name('anular');
        Route::get('{order}/ticket', [VentaController::class, 'ticket'])->name('ticket');
        Route::post('{order}/cobrar', [VentaController::class, 'cobrar'])->name('cobrar');

        // llamadas de la pantalla de venta (JSON)
        Route::get('{order}/items', [VentaController::class, 'items'])->name('items');
        Route::post('{order}/items', [VentaController::class, 'agregar'])->name('agregar');
        Route::post('{order}/servicios', [VentaController::class, 'servicio'])->name('servicio');
        Route::put('items/{item}', [VentaController::class, 'cantidad'])->name('cantidad');
        Route::delete('items/{item}', [VentaController::class, 'quitar'])->name('quitar');
        Route::delete('servicios/{servicio}', [VentaController::class, 'quitarServicio'])->name('quitarServicio');
    });

    Route::get('buscar/productos', [BusquedaController::class, 'productos'])->name('buscar.productos');
    Route::get('buscar/vales/cliente/{cliente}', [BusquedaController::class, 'valesCliente'])->name('buscar.valesCliente');
    Route::get('buscar/vales/codigo', [BusquedaController::class, 'valePorCodigo'])->name('buscar.vale');

    // ------------------------------------------------------- facturacion
    // emitir desde la venta: cualquier encargado (igual que control-soft)
    // (se habilita en Configuracion; los comprobantes ya emitidos se pueden ver siempre)
    Route::post('afip/facturar/{order}', [AfipController::class, 'facturar'])->middleware('modulo:afip')->name('afip.facturar');
    Route::post('afip/nota-credito/{order}', [AfipController::class, 'notaCredito'])->middleware('modulo:afip')->name('afip.ndc');
    Route::get('afip/comprobante/{afip}', [AfipController::class, 'ver'])->name('afip.ver');

    Route::middleware(['permiso:afip', 'modulo:afip'])->prefix('afip')->name('afip.')->group(function () {
        Route::get('/', [AfipController::class, 'index'])->name('index');
        Route::post('marcar-pagadas', [AfipController::class, 'marcarPagadas'])->name('pagadas');
    });
    Route::post('afip/configuracion/logo', [AfipConfigController::class, 'logo'])->middleware('permiso:afip-config')->name('afip.config.logo');
    Route::middleware(['permiso:afip-config', 'modulo:afip'])->prefix('afip')->name('afip.')->group(function () {
        Route::get('configuracion', [AfipConfigController::class, 'show'])->name('config');
        Route::put('configuracion', [AfipConfigController::class, 'guardar'])->name('config.guardar');
        Route::post('configuracion/{ambiente}/credenciales', [AfipConfigController::class, 'credenciales'])->whereIn('ambiente', ['homologacion', 'produccion'])->name('config.credenciales');
        Route::post('configuracion/{ambiente}/csr', [AfipConfigController::class, 'csr'])->whereIn('ambiente', ['homologacion', 'produccion'])->name('config.csr');
        Route::post('configuracion/probar', [AfipConfigController::class, 'probar'])->name('config.probar');
    });

    // -------------------------------------------- devoluciones y proveedores
    Route::middleware('permiso:devoluciones')->group(function () {
        Route::get('devoluciones', [DevolucionController::class, 'index'])->name('devoluciones.index');
        Route::get('devoluciones/crear', [DevolucionController::class, 'create'])->name('devoluciones.create');
        Route::get('devoluciones/venta/{order}', [DevolucionController::class, 'venta'])->name('devoluciones.venta');
        Route::post('devoluciones', [DevolucionController::class, 'store'])->name('devoluciones.store');
        Route::get('devoluciones/{devolucion}', [DevolucionController::class, 'show'])->name('devoluciones.show');
        Route::get('devoluciones/{devolucion}/vale', [DevolucionController::class, 'ticket'])->name('devoluciones.ticket');
    });
    Route::middleware('permiso:proveedores')->group(function () {
        Route::get('proveedores/papelera', [ProveedorController::class, 'papelera'])->name('proveedores.papelera');
        Route::post('proveedores/{proveedor}/restaurar', [ProveedorController::class, 'restaurar'])->name('proveedores.restaurar');
        Route::get('proveedores/{proveedor}/cuenta', [ProveedorController::class, 'cuenta'])->name('proveedores.cuenta');
        Route::post('proveedores/{proveedor}/compra', [ProveedorController::class, 'compra'])->name('proveedores.compra');
        Route::post('proveedores/{proveedor}/pago', [ProveedorController::class, 'pago'])->name('proveedores.pago');
        Route::resource('proveedores', ProveedorController::class)->except('show')->parameters(['proveedores' => 'proveedor']);
    });

    // ---------------------------------------------------------- clientes
    Route::middleware('permiso:clientes')->group(function () {
        Route::get('clientes/papelera', [ClienteController::class, 'papelera'])->name('clientes.papelera');
        Route::post('clientes/{cliente}/restaurar', [ClienteController::class, 'restaurar'])->name('clientes.restaurar');
        Route::get('clientes/{cliente}/historial', [ClienteController::class, 'historial'])->name('clientes.historial');
        Route::post('clientes/{cliente}/pago', [ClienteController::class, 'pago'])->name('clientes.pago');
        Route::resource('clientes', ClienteController::class)->except('show');
    });
    // selector de clientes de la venta (alta rapida solo con permiso de clientes)
    Route::get('buscar/clientes', [BusquedaController::class, 'clientes'])->name('buscar.clientes');
    Route::post('clientes/rapido', [ClienteController::class, 'rapido'])->middleware('permiso:clientes')->name('clientes.rapido');

    // -------------------------------------------------------- encargados
    Route::middleware('permiso:encargados')->group(function () {
        Route::get('encargados/papelera', [EncargadoController::class, 'papelera'])->name('encargados.papelera');
        Route::post('encargados/{encargado}/restaurar', [EncargadoController::class, 'restaurar'])->name('encargados.restaurar');
        Route::resource('encargados', EncargadoController::class)->except('show');
    });

    // ---------------------------------------------------------- catalogo
    Route::middleware('permiso:productos')->group(function () {
        Route::post('productos/precios', [ProductoController::class, 'precios'])->name('productos.precios');
        Route::get('productos/exportar', [ProductoController::class, 'exportar'])->name('productos.exportar');
        Route::get('productos/plantilla', [ProductoController::class, 'plantilla'])->name('productos.plantilla');
        Route::post('productos/importar', [ProductoController::class, 'importar'])->name('productos.importar');
        Route::get('productos/multiples', [ProductoController::class, 'multiples'])->name('productos.multiples');
        Route::post('productos/multiples', [ProductoController::class, 'guardarMultiples'])->name('productos.multiples.guardar');
        Route::resource('productos', ProductoController::class)->except('show');
        Route::get('productos/{producto}/variantes', [VarianteController::class, 'show'])->name('variantes.show');
        Route::post('productos/{producto}/variantes', [VarianteController::class, 'generar'])->name('variantes.generar');
        Route::put('productos/{producto}/variantes', [VarianteController::class, 'guardar'])->name('variantes.guardar');
        Route::delete('productos/{producto}/variantes/{variante}', [VarianteController::class, 'quitar'])->name('variantes.quitar');
    });

    // ---------------------------------------------------------- etiquetas
    Route::middleware('permiso:productos')->prefix('etiquetas')->name('etiquetas.')->group(function () {
        Route::get('/', [EtiquetaController::class, 'index'])->name('index');
        Route::get('buscar', [EtiquetaController::class, 'buscar'])->name('buscar');
        Route::get('barra', [EtiquetaController::class, 'barra'])->name('barra');
        Route::post('preparar', [EtiquetaController::class, 'preparar'])->name('preparar');
        Route::get('imprimir', [EtiquetaController::class, 'imprimir'])->name('imprimir');
        Route::post('pdf', [EtiquetaController::class, 'pdf'])->name('pdf');
    });

    // ------------------------------------------------------ configuracion
    Route::middleware('permiso:configuracion')->group(function () {
        Route::get('configuracion', [ConfiguracionController::class, 'show'])->name('configuracion');
        Route::put('configuracion/variantes', [ConfiguracionController::class, 'variantes'])->name('configuracion.variantes');
        Route::post('configuracion/atributos', [ConfiguracionController::class, 'crearAtributo'])->name('atributos.store');
        Route::put('configuracion/atributos/{atributo}', [ConfiguracionController::class, 'actualizarAtributo'])->name('atributos.update');
        Route::delete('configuracion/atributos/{atributo}', [ConfiguracionController::class, 'borrarAtributo'])->name('atributos.destroy');
        Route::post('configuracion/atributos/{atributo}/valores', [ConfiguracionController::class, 'agregarValor'])->name('atributos.valores');
        Route::delete('configuracion/valores/{valor}', [ConfiguracionController::class, 'borrarValor'])->name('atributos.valores.destroy');
        Route::put('configuracion/listas-precios', [ConfiguracionController::class, 'listasPrecios'])->name('configuracion.listas');
        Route::put('configuracion/presupuestos', [ConfiguracionController::class, 'presupuestos'])->name('configuracion.presupuestos');
        Route::put('configuracion/panaderia', [ConfiguracionController::class, 'panaderia'])->name('configuracion.panaderia');
        Route::post('configuracion/panaderia/probar', [ConfiguracionController::class, 'probarBalanza'])->name('configuracion.balanza');
        Route::put('configuracion/afip', [ConfiguracionController::class, 'afip'])->name('configuracion.afip');
        Route::put('configuracion/negocio', [ConfiguracionController::class, 'negocio'])->name('configuracion.negocio');
        Route::post('configuracion/logo', [AfipConfigController::class, 'logo'])->name('configuracion.logo');
        Route::put('configuracion/combos', [ConfiguracionController::class, 'combos'])->name('configuracion.combos');
        Route::put('configuracion/remitos', [ConfiguracionController::class, 'remitos'])->name('configuracion.remitos');
        Route::put('configuracion/multisucursal', [ConfiguracionController::class, 'multisucursal'])->name('configuracion.multisucursal');
        Route::post('configuracion/sucursales', [SucursalController::class, 'store'])->name('sucursales.store');
        Route::put('configuracion/sucursales/{sucursal}', [SucursalController::class, 'update'])->name('sucursales.update');
    });

    // listas de precios de proveedores
    Route::middleware('permiso:listas-precios')->group(function () {
        Route::middleware('modulo:listas_precios')->prefix('listas-precios')->name('listas.')->group(function () {
            Route::get('/', [ListaPrecioController::class, 'index'])->name('index');
            Route::post('/', [ListaPrecioController::class, 'store'])->name('store');
            Route::get('ejemplo', [ListaPrecioController::class, 'ejemplo'])->name('ejemplo');
            Route::get('productos', [ListaPrecioController::class, 'productos'])->name('productos');
            Route::get('{lista}/columnas', [ListaPrecioController::class, 'columnas'])->name('columnas');
            Route::post('{lista}/columnas', [ListaPrecioController::class, 'procesar'])->name('procesar');
            Route::get('{lista}', [ListaPrecioController::class, 'show'])->name('show');
            Route::put('{lista}/renglones/{renglon}', [ListaPrecioController::class, 'vincular'])->name('vincular');
            Route::post('{lista}/aplicar', [ListaPrecioController::class, 'aplicar'])->name('aplicar');
            Route::post('{lista}/descartar', [ListaPrecioController::class, 'descartar'])->name('descartar');
        });

    });

    // transferencias de stock entre sucursales
    Route::middleware('permiso:transferencias')->group(function () {
        Route::middleware('modulo:multisucursal')->prefix('transferencias')->name('transferencias.')->group(function () {
            Route::get('/', [TransferenciaController::class, 'index'])->name('index');
            Route::get('nueva', [TransferenciaController::class, 'create'])->name('create');
            Route::get('productos', [TransferenciaController::class, 'productos'])->name('productos');
            Route::post('/', [TransferenciaController::class, 'store'])->name('store');
            Route::get('{transferencia}', [TransferenciaController::class, 'show'])->name('show');
        });

    });

    // panaderia: produccion del dia
    Route::middleware(['permiso:produccion', 'modulo:panaderia_produccion'])->prefix('produccion')->name('produccion.')->group(function () {
        Route::get('/', [ProduccionController::class, 'index'])->name('index');
        Route::get('productos', [ProduccionController::class, 'productos'])->name('productos');
        Route::post('hornear', [ProduccionController::class, 'hornear'])->name('hornear');
        Route::post('cerrar', [ProduccionController::class, 'cerrar'])->name('cerrar');
    });

    // presupuestos (solo se imprimen) y remitos de entrega
    Route::middleware(['permiso:presupuestos', 'modulo:presupuestos'])->prefix('presupuestos')->name('presupuestos.')->group(function () {
        Route::get('/', [PresupuestoController::class, 'index'])->name('index');
        Route::get('nuevo', [PresupuestoController::class, 'create'])->name('create');
        Route::get('productos', [PresupuestoController::class, 'productos'])->name('productos');
        Route::get('clientes', [PresupuestoController::class, 'clientes'])->name('clientes');
        Route::post('/', [PresupuestoController::class, 'store'])->name('store');
        Route::get('{presupuesto}', [PresupuestoController::class, 'show'])->whereNumber('presupuesto')->name('show');
        Route::get('{presupuesto}/editar', [PresupuestoController::class, 'edit'])->whereNumber('presupuesto')->name('edit');
        Route::put('{presupuesto}', [PresupuestoController::class, 'update'])->whereNumber('presupuesto')->name('update');
        Route::delete('{presupuesto}', [PresupuestoController::class, 'destroy'])->whereNumber('presupuesto')->name('destroy');
        Route::get('{presupuesto}/imprimir', [PresupuestoController::class, 'imprimir'])->whereNumber('presupuesto')->name('imprimir');
    });
    Route::middleware(['permiso:remitos', 'modulo:remitos'])->prefix('remitos')->name('remitos.')->group(function () {
        Route::get('/', [RemitoController::class, 'index'])->name('index');
        Route::get('nuevo', [RemitoController::class, 'create'])->name('create');
        Route::get('productos', [RemitoController::class, 'productos'])->name('productos');
        Route::get('clientes', [RemitoController::class, 'clientes'])->name('clientes');
        Route::post('/', [RemitoController::class, 'store'])->name('store');
        Route::get('{remito}', [RemitoController::class, 'show'])->whereNumber('remito')->name('show');
        Route::post('{remito}/anular', [RemitoController::class, 'anular'])->whereNumber('remito')->name('anular');
        Route::get('{remito}/imprimir', [RemitoController::class, 'imprimir'])->whereNumber('remito')->name('imprimir');
    });

    Route::middleware(['permiso:combos', 'modulo:combos'])->group(function () {
        Route::post('combos/{combo}/activar', [ComboController::class, 'activar'])->name('combos.activar');
        Route::resource('combos', ComboController::class)->except('show');
    });

    Route::middleware('permiso:categorias')->group(function () {
        Route::get('categorias/papelera', [CategoriaController::class, 'papelera'])->name('categorias.papelera');
        Route::post('categorias/{categoria}/restaurar', [CategoriaController::class, 'restaurar'])->name('categorias.restaurar');
        Route::resource('categorias', CategoriaController::class)->except('show');
    });

    // ---------------------------------------------------------- reportes
    Route::middleware('permiso:informe')->group(function () {
        Route::get('reportes', [ReporteController::class, 'index'])->name('reportes.index');
        Route::get('reportes/datos', [ReporteController::class, 'datos'])->name('reportes.datos');
    });

    // ---------------------------------------------------- roles y permisos
    Route::middleware('permiso:roles-permisos')->prefix('roles')->name('roles.')->group(function () {
        Route::get('/', [RolController::class, 'index'])->name('index');
        Route::post('/', [RolController::class, 'store'])->name('store');
        Route::put('{role}/permisos', [RolController::class, 'permisos'])->name('permisos');
        Route::put('{role}', [RolController::class, 'update'])->name('update');
        Route::delete('{role}', [RolController::class, 'destroy'])->name('destroy');
        Route::put('usuarios/{user}', [RolController::class, 'usuario'])->name('usuario');
    });
});
