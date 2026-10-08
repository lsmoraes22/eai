<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadMovimentoMotivosBloqueio extends Model
{
    protected $table = 'cad_movimento_motivo_bloqueio';

    protected $primaryKey = 'id_movimento';

    public $timestamps = false;
}
