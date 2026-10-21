<?php

namespace Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Seeder;

/**
 * Catalogo de un kiosco argentino con marcas reales, para arrancar sin cargar
 * todo a mano.
 *
 *   php artisan db:seed --class='Database\Seeders\CatalogoKioscoSeeder' --force
 *
 * - Idempotente: si el codigo ya existe NO lo toca (no pisa precios ni stock).
 * - Codigos internos K0001...: al escanear el producto real por primera vez,
 *   editalo y reemplaza el codigo por el de barras del paquete.
 * - Precios de referencia (octubre 2026): ajustalos con "Actualizar precios"
 *   o exportando a Excel, cambiando y volviendo a importar.
 * - Stock en 0: cargalo con el Excel (columna Stock + "reemplazar stock").
 */
class CatalogoKioscoSeeder extends Seeder
{
    /** categoria => [ [nombre, precio de venta], ... ] */
    private const CATALOGO = [
        'Gaseosas' => [
            ['Coca-Cola 500 ml', 1900], ['Coca-Cola 1,5 L', 3600], ['Coca-Cola 2,25 L', 4600], ['Coca-Cola Zero 500 ml', 1900],
            ['Coca-Cola Zero 1,5 L', 3600], ['Coca-Cola lata 354 ml', 1500], ['Sprite 500 ml', 1800], ['Sprite 1,5 L', 3300],
            ['Fanta Naranja 500 ml', 1800], ['Fanta Naranja 1,5 L', 3300], ['Pepsi 500 ml', 1700], ['Pepsi 1,5 L', 3100],
            ['7Up 500 ml', 1700], ['Mirinda Naranja 1,5 L', 2900], ['Paso de los Toros Pomelo 1,5 L', 3000],
            ['Schweppes Tónica 1,5 L', 3200], ['Manaos Cola 2,25 L', 2200], ['Manaos Lima Limón 2,25 L', 2200],
        ],
        'Aguas y jugos' => [
            ['Agua Villavicencio sin gas 500 ml', 1100], ['Agua Villavicencio sin gas 1,5 L', 1800], ['Agua Villa del Sur 500 ml', 1000],
            ['Agua Eco de los Andes con gas 500 ml', 1200], ['Aquarius Pomelo 500 ml', 1700], ['Aquarius Naranja 1,5 L', 3000],
            ['Levité Pomelo 1,5 L', 2600], ['Cepita Naranja 1 L', 2600], ['Cepita Multifruta 1 L', 2600], ['Baggio Durazno 200 ml', 700],
            ['Baggio Naranja 1 L', 1900], ['Powerade Mountain Blast 500 ml', 2000], ['Gatorade Naranja 500 ml', 2100],
        ],
        'Energizantes' => [
            ['Monster Energy 473 ml', 3000], ['Monster Ultra 473 ml', 3000], ['Red Bull 250 ml', 2900], ['Speed Unlimited 250 ml', 1700],
        ],
        'Cervezas' => [
            ['Quilmes Clásica lata 473 ml', 2100], ['Quilmes Clásica 1 L', 3300], ['Brahma lata 473 ml', 2000], ['Brahma 1 L', 3100],
            ['Stella Artois lata 473 ml', 2800], ['Heineken lata 473 ml', 2900], ['Andes Origen Rubia lata 473 ml', 2300],
            ['Corona 330 ml', 2900], ['Imperial Lager lata 473 ml', 2300], ['Schneider lata 473 ml', 1900],
        ],
        'Alfajores' => [
            ['Alfajor Jorgito chocolate', 1000], ['Alfajor Jorgito blanco', 1000], ['Alfajor Guaymallén chocolate', 500],
            ['Alfajor Guaymallén blanco', 500], ['Alfajor Fantoche triple', 1400], ['Alfajor Milka Oreo', 1800],
            ['Alfajor Milka Mousse', 1800], ['Alfajor Capitán del Espacio', 1300], ['Alfajor Tatín triple', 800],
            ['Alfajor Havanna chocolate', 2700], ['Alfajor Bon o Bon', 1300], ['Alfajor Terrabusi triple', 1600],
        ],
        'Chocolates' => [
            ['Chocolate Milka con leche 55 g', 2200], ['Chocolate Cofler Block 38 g', 1800], ['Chocolate Shot 35 g', 1500],
            ['Chocolate Águila 100 g', 3800], ['Kinder Bueno', 2400], ['Kinder Sorpresa', 3500], ['Bon o Bon', 600],
            ['Tofi', 900], ['Rocklets 40 g', 1500], ['Mantecol 110 g', 2800], ['Barra Cereal Mix', 700],
        ],
        'Golosinas' => [
            ['Chicle Beldent Menta', 1000], ['Chicle Topline Menta', 1000], ['Chicle Bazooka', 200], ['Caramelos Sugus', 1100],
            ['Caramelos Media Hora', 900], ['Pastillas Halls Mentol', 1100], ['Mentitas', 800], ['Gomitas Mogul 30 g', 900],
            ['Rocklets en bolsa 20 g', 900], ['Chupetín Pico Dulce', 400], ['Chupetín Chupa Chups', 500], ['Turrón Misky', 400],
        ],
        'Galletitas' => [
            ['Oreo 118 g', 1700], ['Pepitos 118 g', 1700], ['Chocolinas 170 g', 1900], ['Toddy chips 160 g', 2100],
            ['Criollitas 100 g', 1100], ['Express 101 g', 1100], ['Traviata 101 g', 1200], ['Sonrisas 118 g', 1500],
            ['Rumba 112 g', 1400], ['Melba 120 g', 1600], ['Club Social 3 paquetes', 1500], ['Tita', 600], ['Rhodesia', 700],
        ],
        'Snacks' => [
            ['Papas Lay\'s clásicas 85 g', 2500], ['Papas Lay\'s clásicas 145 g', 3800], ['Pringles Original 124 g', 5200],
            ['Doritos queso 77 g', 2500], ['Cheetos 75 g', 2000], ['Saladix pizza', 1200], ['Krachitos papas 80 g', 1600],
            ['Maní salado Pehuamar 120 g', 1600], ['Palitos salados Pep 80 g', 1400], ['3D queso 70 g', 1900],
        ],
        'Cigarrillos' => [
            ['Marlboro Box 20', 4500], ['Marlboro Box 10', 2400], ['Philip Morris Box 20', 4100], ['Philip Morris Box 10', 2200],
            ['Lucky Strike Box 20', 4100], ['Camel Box 20', 4000], ['Chesterfield Box 20', 3700], ['Parliament Box 20', 4700],
            ['Encendedor Bic', 1500], ['Papel para armar OCB', 900],
        ],
        'Lácteos' => [
            ['Leche La Serenísima entera 1 L', 1800], ['Leche La Serenísima descremada 1 L', 1800], ['Chocolatada Cindor 1 L', 2900],
            ['Cindor 200 ml', 900], ['Yogur Ser bebible frutilla 900 g', 2500], ['Yogur Yogurísimo frutilla 160 g', 1100],
            ['Manteca La Serenísima 100 g', 1700], ['Queso crema Casancrem 290 g', 3200],
        ],
        'Almacén' => [
            ['Yerba Playadito 500 g', 3400], ['Yerba Taragüi 500 g', 3300], ['Yerba Rosamonte 500 g', 3100],
            ['Azúcar Ledesma 1 kg', 1500], ['Café Nescafé Clásico 100 g', 6900], ['Té La Virginia x25', 1300],
            ['Mate cocido Taragüi x25', 1400], ['Fideos Matarazzo spaghetti 500 g', 1800], ['Arroz Gallo Oro 1 kg', 2300],
            ['Aceite Natura girasol 900 ml', 3200], ['Pan lactal Bimbo 390 g', 3100], ['Galletas de arroz Gallo', 1500],
        ],
        'Helados y hielo' => [
            ['Hielo en bolsa 2 kg', 1800], ['Palito helado Frigor', 900], ['Bombón helado Frigor', 1500],
        ],
        'Higiene y varios' => [
            ['Pañuelos descartables Carilina', 700], ['Preservativos Prime x3', 3500], ['Pilas Duracell AA x2', 4200],
            ['Toallitas femeninas Always x8', 2200], ['Cepillo dental Colgate', 2000], ['Pasta dental Colgate 90 g', 2600],
            ['Desodorante Rexona aerosol', 3800], ['Shampoo Sedal 190 ml', 3200], ['Curitas x10', 1300],
        ],
    ];

    public function run(): void
    {
        $ahora = now();
        $cats = [];
        foreach (array_keys(self::CATALOGO) as $c) {
            $id = DB::table('product_categories')->whereRaw('LOWER(nombre) = ?', [mb_strtolower($c)])->value('id');
            $cats[$c] = $id ?: DB::table('product_categories')->insertGetId(['nombre' => $c, 'unidad' => 'Uds', 'activa' => 1, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        $creados = 0;
        $i = 0;
        foreach (self::CATALOGO as $cat => $lista) {
            foreach ($lista as [$nombre, $precio]) {
                $codigo = 'K'.str_pad((string) ++$i, 4, '0', STR_PAD_LEFT);
                // ni el codigo ni el nombre: si ya esta cargado (o se renombro con su codigo real) no se duplica
                if (DB::table('products')->where('codigo', $codigo)->orWhere('nombre', $nombre)->exists()) {
                    continue;
                }
                $costo = round($precio / 1.4 / 10) * 10; // margen de referencia ~40%
                $id = DB::table('products')->insertGetId([
                    'id_categoria' => $cats[$cat], 'codigo' => $codigo, 'nombre' => $nombre, 'pedido' => 0, 'quedan' => 0,
                    'aviso' => 3, 'costo' => $costo, 'monto' => $precio, 'archivo' => 'sinImagen.png', 'activo' => 1,
                    'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                DB::table('ingreso_producto')->insert(['id_producto' => $id, 'id_user' => 1, 'cantidad' => 0, 'costo' => $costo, 'monto' => $precio,
                    'created_at' => $ahora, 'updated_at' => $ahora]);
                $creados++;
            }
        }
        $this->command?->info("Catálogo de kiosco: {$creados} productos nuevos (".($i - $creados).' ya estaban).');
    }
}
