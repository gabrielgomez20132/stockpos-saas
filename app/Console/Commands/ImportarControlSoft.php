<?php

namespace App\Console\Commands;

use Database\Seeders\BaseSeeder;
use Database\Seeders\RolesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copia todos los datos de la base de control-soft (mismo servidor MySQL) a la
 * base de este sistema. Las tablas tienen los mismos nombres y columnas, asi
 * que es una copia fila por fila. La base vieja NO se modifica.
 */
class ImportarControlSoft extends Command
{
    protected $signature = 'stock:importar-control-soft
        {--base=control_soft : Nombre de la base de datos de control-soft}
        {--force : No pedir confirmacion}';

    protected $description = 'Importa los datos de control-soft (ventas, productos, clientes, caja...) a esta base';

    /** En orden, para respetar las claves foraneas. */
    private const TABLAS = [
        'user_types', 'users', 'roles', 'permissions', 'permission_role', 'role_user',
        'product_categories', 'products', 'ingreso_producto',
        'orders_type', 'formas_pago', 'canales', 'orders', 'orders_products', 'orders_services',
        'controls', 'deudas', 'cierres_caja',
        'devoluciones', 'devolucion_productos', 'vales', 'order_vales',
        'afip', 'afip_config',
        // tablas nuevas que dependen de productos y ventas: se vacian (en la base vieja no existen)
        'combos', 'combo_items', 'stock_sucursal', 'transferencias', 'transferencia_items',
        'listas_precios', 'lista_precio_items', 'producto_proveedor',
        'presupuestos', 'presupuesto_items', 'remitos', 'remito_items',
    ];

    public function handle(): int
    {
        $base = $this->option('base');
        $destino = config('database.connections.'.config('database.default').'.database');

        if ($base === $destino) {
            $this->error('La base de origen y la de destino son la misma ('.$base.').');

            return self::FAILURE;
        }

        config(['database.connections.origen' => array_merge(config('database.connections.mysql'), ['database' => $base])]);

        try {
            $origen = DB::connection('origen');
            $origen->getPdo();
        } catch (\Throwable $e) {
            $this->error('No me pude conectar a la base "'.$base.'": '.$e->getMessage());

            return self::FAILURE;
        }

        if (! Schema::connection('origen')->hasTable('orders')) {
            $this->error('La base "'.$base.'" no parece ser de control-soft (no tiene la tabla orders).');

            return self::FAILURE;
        }

        $this->warn('Se van a BORRAR los datos actuales de "'.$destino.'" y reemplazar por los de "'.$base.'".');
        $this->line('La base "'.$base.'" no se toca.');
        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (self::TABLAS as $tabla) {
                if (! Schema::hasTable($tabla)) {
                    continue;
                }
                DB::table($tabla)->truncate();

                if (! Schema::connection('origen')->hasTable($tabla)) {
                    $this->line(str_pad($tabla, 24).'  (no existe en la base vieja, queda vacía)');

                    continue;
                }

                $cols = array_values(array_intersect(
                    Schema::connection('origen')->getColumnListing($tabla),
                    Schema::getColumnListing($tabla),
                ));

                $total = 0;
                $orden = in_array('id', $cols, true) ? 'id' : $cols[0];
                $origen->table($tabla)->select($cols)->orderBy($orden)->chunk(500, function ($filas) use ($tabla, &$total) {
                    DB::table($tabla)->insert($filas->map(fn ($f) => (array) $f)->all());
                    $total += $filas->count();
                });

                $this->line(str_pad($tabla, 24).'  '.$total.' filas');
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        // completa lo que la base vieja no tenga (formas de pago 28-32, permisos nuevos, canal 1...)
        $this->callSilently('db:seed', ['--class' => BaseSeeder::class, '--force' => true]);
        $this->callSilently('db:seed', ['--class' => RolesSeeder::class, '--force' => true]);

        $this->newLine();
        $this->info('Listo. Entrá con tu usuario y contraseña de control-soft.');
        $this->line('Acordate de copiar las fotos: C:\laragon\www\control-soft\public\uploads  ->  public\uploads');

        return self::SUCCESS;
    }
}
