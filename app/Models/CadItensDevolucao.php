<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadItensDevolucao extends Model
{
    protected $table = 'cad_itens_devolucao';

    protected $primaryKey = 'id_item';

    public $timestamps = false;
}
