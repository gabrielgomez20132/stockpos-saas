<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AtributoValor extends Model
{
    protected $table = 'atributo_valores';

    public $timestamps = false;

    protected $fillable = ['atributo_id', 'valor', 'orden'];
}
