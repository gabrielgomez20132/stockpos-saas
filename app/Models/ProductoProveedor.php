<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** codigo con el que un proveedor identifica a un producto */
class ProductoProveedor extends Model
{
    protected $table = 'producto_proveedor';

    protected $fillable = ['id_producto', 'id_proveedor', 'codigo_proveedor'];
}
