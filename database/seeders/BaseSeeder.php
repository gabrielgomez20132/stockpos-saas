<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class BaseSeeder extends Seeder
{
    /** Mismos ids que control-soft: el sistema depende de ellos. */
    public const FORMAS_PAGO = [
        1 => 'Efectivo', 2 => 'Tarjeta Débito', 3 => 'Efectivo/Tarjeta Débito', 4 => 'FIADO',
        5 => 'Transferencia', 6 => 'Cheque', 7 => 'Dolares', 8 => 'Efectivo/Transferencia',
        9 => 'Efectivo/Cheque', 10 => 'Efectivo/Dolares', 11 => 'Transferencia/Tarjeta Débito',
        12 => 'Transferencia/Cheque', 13 => 'Transferencia/Dolares', 14 => 'Tarjeta Débito/Cheque',
        15 => 'Tarjeta Débito/Dolares', 16 => 'Cheque/Dolares', 17 => 'Tarjeta Crédito',
        18 => 'Tarjeta Crédito/Cheque', 19 => 'Tarjeta Crédito/Tarjeta Débito',
        20 => 'Tarjeta Crédito/Transferencia', 21 => 'Tarjeta Crédito/Efectivo', 22 => 'QR',
        23 => 'QR/Efectivo', 24 => 'QR/Tarjeta Débito', 25 => 'QR/Tarjeta Crédito',
        26 => 'QR/Transferencia', 27 => 'QR/Cheque', 28 => 'Vale', 29 => 'Efectivo/Vale',
        30 => 'Tarjeta Débito/Vale', 31 => 'Transferencia/Vale', 32 => 'QR/Vale',
    ];

    public function run(): void
    {
        $ahora = now();

        foreach ([1 => 'encargado', 2 => 'cliente', 3 => 'proveedor'] as $id => $nombre) {
            if (! DB::table('user_types')->where('id', $id)->exists()) {
                DB::table('user_types')->insert(['id' => $id, 'nombre' => $nombre, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        if (! DB::table('orders_type')->where('id', 1)->exists()) {
            DB::table('orders_type')->insert(['id' => 1, 'nombre' => 'productos', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        foreach (self::FORMAS_PAGO as $id => $nombre) {
            if (! DB::table('formas_pago')->where('id', $id)->exists()) {
                DB::table('formas_pago')->insert(['id' => $id, 'nombre' => $nombre, 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
        }

        if (! DB::table('canales')->where('id', 1)->exists()) {
            DB::table('canales')->insert(['id' => 1, 'nombre' => 'Kiosco', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        // id 1: Administrador. id 2: cliente "General" (consumidor final).
        if (! DB::table('users')->where('id', User::ID_ADMIN)->exists()) {
            DB::table('users')->insert([
                'id' => User::ID_ADMIN, 'nombre' => 'Administrador', 'dni' => 1234,
                'email' => 'admin@stokpos.local', 'password' => Hash::make('admin'),
                'id_uType' => User::TIPO_ENCARGADO, 'activo' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $this->command?->warn('Usuario admin creado: admin@stokpos.local / DNI 1234 / contraseña "admin". CAMBIALA al entrar.');
        }

        if (! DB::table('users')->where('id', User::ID_CLIENTE_GENERAL)->exists()) {
            DB::table('users')->insert([
                'id' => User::ID_CLIENTE_GENERAL, 'nombre' => 'General', 'id_uType' => User::TIPO_CLIENTE,
                'activo' => 1, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }
}
