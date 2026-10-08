<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadHistoricoMovimentoLote extends Model
{
    protected $table = 'cad_historico_movimento_lote';

    protected $primaryKey = 'ID_HIST';

    public $timestamps = false;
}
