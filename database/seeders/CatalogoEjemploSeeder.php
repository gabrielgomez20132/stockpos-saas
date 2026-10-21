<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Categorias y productos tipicos de un kiosco. Idempotente (no pisa precios editados). */
class CatalogoEjemploSeeder extends Seeder
{
    public function run(): void
    {
        $categorias = [
            'Gaseosas' => 'Uds', 'Aguas y jugos' => 'Uds', 'Cervezas' => 'Uds', 'Golosinas' => 'Uds',
            'Chocolates' => 'Uds', 'Galletitas' => 'Uds', 'Snacks' => 'Uds', 'Cigarrillos' => 'Uds',
            'Lácteos' => 'Uds', 'Almacén' => 'Uds', 'Fiambrería' => 'Kg', 'Panadería' => 'Kg',
        ];
        $ahora = now();
        foreach ($categorias as $nombre => $unidad) {
            if (! DB::table('product_categories')->where('nombre', $nombre)->exists()) {
                DB::table('product_categories')->insert(['nombre' => $nombre, 'unidad' => $unidad, 'activa' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        // codigo, nombre, categoria, costo, venta, stock inicial, aviso de faltante
        $productos = [
            // --- Gaseosas ---
            ['1001', 'Coca-Cola 500 ml',              'Gaseosas',      900,  1400, 24, 6],
            ['1002', 'Coca-Cola 1.5 L',               'Gaseosas',     1800,  2700, 12, 4],
            ['1003', 'Coca-Cola Zero 500 ml',         'Gaseosas',      900,  1400, 18, 6],
            ['1004', 'Sprite 500 ml',                 'Gaseosas',      850,  1300, 12, 4],
            ['1005', 'Fanta Naranja 500 ml',          'Gaseosas',      850,  1300, 12, 4],
            ['1006', 'Pepsi 500 ml',                  'Gaseosas',      800,  1250, 12, 4],
            ['1007', 'Paso de los Toros Pomelo 1.5 L','Gaseosas',     1600,  2400,  8, 3],

            // --- Aguas y jugos ---
            ['1101', 'Agua mineral sin gas 500 ml',   'Aguas y jugos', 500,   800, 24, 6],
            ['1102', 'Agua mineral con gas 500 ml',   'Aguas y jugos', 500,   800, 12, 4],
            ['1103', 'Agua saborizada pomelo 1.5 L',  'Aguas y jugos',1100,  1700, 12, 4],
            ['1104', 'Jugo en caja multifruta 200 ml','Aguas y jugos', 400,   650, 24, 8],
            ['1105', 'Isotónica naranja 500 ml',      'Aguas y jugos', 950,  1500, 12, 4],

            // --- Cervezas ---
            ['1201', 'Cerveza rubia lata 473 ml',     'Cervezas',     1200,  1900, 24, 6],
            ['1202', 'Cerveza rubia botella 1 L',     'Cervezas',     1900,  2900, 12, 4],
            ['1203', 'Cerveza negra lata 473 ml',     'Cervezas',     1400,  2200, 12, 4],

            // --- Golosinas ---
            ['1301', 'Chicles menta blister',         'Golosinas',     250,   450, 40, 10],
            ['1302', 'Caramelos masticables tira',    'Golosinas',     300,   550, 40, 10],
            ['1303', 'Gomitas frutales 40 g',         'Golosinas',     400,   700, 30, 10],
            ['1304', 'Chupetín surtido',              'Golosinas',     150,   300, 60, 15],
            ['1305', 'Pastillas frutales',            'Golosinas',     280,   500, 40, 10],
            ['1306', 'Turrón de maní',                'Golosinas',     350,   600, 30, 10],

            // --- Chocolates ---
            ['1401', 'Chocolate con leche 100 g',     'Chocolates',   1300,  2000, 20, 6],
            ['1402', 'Chocolate con almendras 100 g', 'Chocolates',   1500,  2300, 15, 5],
            ['1403', 'Alfajor simple de chocolate',   'Chocolates',    600,  1000, 40, 12],
            ['1404', 'Alfajor triple de chocolate',   'Chocolates',    950,  1500, 30, 10],
            ['1405', 'Bombón helado',                 'Chocolates',    700,  1200, 20, 6],
            ['1406', 'Oblea bañada',                  'Chocolates',    500,   850, 30, 10],

            // --- Galletitas ---
            ['1501', 'Galletitas dulces surtidas',    'Galletitas',    900,  1400, 20, 6],
            ['1502', 'Galletitas de agua 200 g',      'Galletitas',    800,  1250, 20, 6],
            ['1503', 'Galletitas rellenas chocolate', 'Galletitas',    750,  1200, 24, 8],
            ['1504', 'Bizcochos de grasa 200 g',      'Galletitas',    850,  1350, 15, 5],

            // --- Snacks ---
            ['1601', 'Papas fritas clásicas 100 g',   'Snacks',       1200,  1900, 20, 6],
            ['1602', 'Papas fritas jamón 100 g',      'Snacks',       1200,  1900, 15, 5],
            ['1603', 'Palitos salados 100 g',         'Snacks',        900,  1450, 20, 6],
            ['1604', 'Maní salado 100 g',             'Snacks',        700,  1150, 20, 6],
            ['1605', 'Nachos con queso 150 g',        'Snacks',       1400,  2200, 12, 4],

            // --- Cigarrillos ---
            ['1701', 'Cigarrillos box 10',            'Cigarrillos',  1800,  2300, 20, 5],
            ['1702', 'Cigarrillos box 20',            'Cigarrillos',  3200,  4000, 20, 5],
            ['1703', 'Encendedor',                    'Cigarrillos',   600,  1100, 25, 8],

            // --- Lácteos ---
            ['1801', 'Leche entera sachet 1 L',       'Lácteos',      1100,  1600, 12, 4],
            ['1802', 'Yogur bebible 200 ml',          'Lácteos',       700,  1150, 15, 5],
            ['1803', 'Postre de chocolate',           'Lácteos',       650,  1050, 12, 4],

            // --- Almacén ---
            ['1901', 'Yerba mate 500 g',              'Almacén',      2400,  3500, 10, 3],
            ['1902', 'Azúcar 1 kg',                   'Almacén',      1300,  1900, 10, 3],
            ['1903', 'Café instantáneo 50 g',         'Almacén',      2100,  3200,  8, 3],
            ['1904', 'Fideos guiseros 500 g',         'Almacén',       900,  1400, 12, 4],
            ['1905', 'Pan lactal 400 g',              'Almacén',      1500,  2200,  8, 3],

            // --- Fiambrería (por Kg: costo y venta son POR KILO) ---
            ['2001', 'Jamón cocido',                  'Fiambrería',   7200, 11000, 5, 1],
            ['2002', 'Queso cremoso',                 'Fiambrería',   6800, 10500, 5, 1],
            ['2003', 'Salame milán',                  'Fiambrería',   9500, 14500, 3, 1],
            ['2004', 'Mortadela',                     'Fiambrería',   4500,  7000, 4, 1],

            // --- Panadería (por Kg) ---
            ['2101', 'Pan francés',                   'Panadería',    1600,  2600, 10, 2],
            ['2102', 'Facturas surtidas',             'Panadería',    3800,  6000,  4, 1],
            ['2103', 'Pan de salvado',                'Panadería',    1900,  3000,  5, 1],
        ];

        $idUsuario = DB::table('users')->min('id') ?: 1;
        foreach ($productos as [$codigo, $nombre, $categoria, $costo, $venta, $stock, $aviso]) {
            if (DB::table('products')->where('codigo', $codigo)->exists()) {
                continue;
            }
            $idCategoria = DB::table('product_categories')->where('nombre', $categoria)->value('id');
            if (! $idCategoria) {
                continue;
            }
            $id = DB::table('products')->insertGetId([
                'id_categoria' => $idCategoria, 'codigo' => $codigo, 'nombre' => $nombre,
                'pedido' => $stock, 'quedan' => $stock, 'aviso' => $aviso, 'costo' => $costo, 'monto' => $venta,
                'archivo' => 'sinImagen.png', 'activo' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            DB::table('ingreso_producto')->insert([
                'id_producto' => $id, 'id_user' => $idUsuario, 'cantidad' => $stock, 'costo' => $costo,
                'monto' => $venta, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }
}
