<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends Model
{
    /** slug => [nombre, grupo, descripcion] */
    public const DETALLE = [
        // ventas y caja
        'descuentos' => ['Hacer descuentos', 'Ventas y caja', 'Aplicar descuentos en % o en $ al cobrar una venta.'],
        'anular-ventas' => ['Anular ventas sin cobrar', 'Ventas y caja', 'Cancelar una venta que todavía no se cobró (devuelve el stock).'],
        'control' => ['Caja: gastos y movimientos', 'Ventas y caja', 'Gastos, servicios, pagos desde la caja, movimientos y exportar ventas.'],
        'devoluciones' => ['Devoluciones y vales', 'Ventas y caja', 'Registrar devoluciones y emitir vales para el cliente.'],
        'afip' => ['Facturación AFIP', 'Ventas y caja', 'Libro de comprobantes (Facturación) y marcar facturas pagadas.'],
        'afip-config' => ['Configuración AFIP', 'Administración', 'Datos fiscales, punto de venta, ambiente y certificados de AFIP.'],
        'informe' => ['Reportes', 'Ventas y caja', 'Ventas, costos y ganancias por período.'],
        'presupuestos' => ['Presupuestos', 'Ventas y caja', 'Armar, imprimir y editar presupuestos para clientes.'],
        'remitos' => ['Remitos', 'Ventas y caja', 'Remitos de entrega: de una venta o sueltos (los sueltos descuentan stock).'],
        // personas
        'clientes' => ['Clientes y cuentas corrientes', 'Personas', 'Alta de clientes, fiado y cobro de deudas.'],
        'proveedores' => ['Proveedores', 'Personas', 'Proveedores y su cuenta corriente (compras y pagos).'],
        'encargados' => ['Encargados y sucursales', 'Personas', 'Usuarios que entran al sistema y administración de sucursales.'],
        // mercaderia
        'productos' => ['Productos y stock', 'Mercadería', 'Alta y edición de productos, stock, precios, importar y exportar.'],
        'categorias' => ['Categorías', 'Mercadería', 'Categorías de productos (por unidad o por peso).'],
        'combos' => ['Combos y promociones', 'Mercadería', 'Crear y editar combos y promos.'],
        'listas-precios' => ['Listas de precios de proveedores', 'Mercadería', 'Subir listas de proveedores y aplicar los costos nuevos.'],
        'produccion' => ['Producción del día (panadería)', 'Mercadería', 'Cargar lo que sale del horno (entra al stock) y cerrar el día con lo que sobró.'],
        'transferencias' => ['Transferencias entre sucursales', 'Mercadería', 'Mandar mercadería de una sucursal a otra.'],
        // administracion
        'configuracion' => ['Configuración', 'Administración', 'Variantes, módulos (listas de precios, multisucursal) y ajustes generales.'],
        'roles-permisos' => ['Roles y permisos', 'Administración', 'Crear roles, elegir sus permisos y asignarlos a cada persona.'],
    ];

    /** permiso nuevo => permiso viejo del que se hereda (para no cambiarle nada a los roles que ya existen) */
    public const HEREDA = [
        'descuentos' => '*', 'anular-ventas' => '*',
        'devoluciones' => 'control', 'proveedores' => 'control',
        'afip-config' => 'afip', 'presupuestos' => '*', 'remitos' => 'productos', 'produccion' => 'productos',
        'combos' => 'productos', 'listas-precios' => 'productos', 'transferencias' => 'productos', 'configuracion' => 'productos',
    ];

    /** slug => nombre visible */
    public const LISTA = [
        'descuentos' => 'Hacer descuentos', 'anular-ventas' => 'Anular ventas sin cobrar', 'control' => 'Caja: gastos y movimientos',
        'devoluciones' => 'Devoluciones y vales', 'afip' => 'Facturación AFIP', 'afip-config' => 'Configuración AFIP', 'informe' => 'Reportes',
        'presupuestos' => 'Presupuestos', 'remitos' => 'Remitos', 'produccion' => 'Producción del día (panadería)',
        'clientes' => 'Clientes y cuentas corrientes', 'proveedores' => 'Proveedores', 'encargados' => 'Encargados y sucursales',
        'productos' => 'Productos y stock', 'categorias' => 'Categorías', 'combos' => 'Combos y promociones',
        'listas-precios' => 'Listas de precios de proveedores', 'transferencias' => 'Transferencias entre sucursales',
        'configuracion' => 'Configuración', 'roles-permisos' => 'Roles y permisos',
    ];

    protected $fillable = ['name', 'slug', 'description'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** crea los permisos que falten, les pone nombre y descripcion, y hereda los nuevos */
    public static function sincronizar(): void
    {
        $existian = static::pluck('id', 'slug');
        foreach (self::DETALLE as $slug => [$nombre, , $desc]) {
            static::updateOrCreate(['slug' => $slug], ['name' => $nombre, 'description' => $desc]);
        }
        $ids = static::pluck('id', 'slug');
        foreach (self::HEREDA as $nuevo => $viejo) {
            if (isset($existian[$nuevo])) {
                continue; // ya existia: no se toca lo configurado
            }
            $roles = $viejo === '*' ? Role::pluck('id') : (isset($ids[$viejo]) ? static::find($ids[$viejo])->roles()->pluck('roles.id') : collect());
            static::find($ids[$nuevo])->roles()->syncWithoutDetaching($roles->all());
        }
    }
}
