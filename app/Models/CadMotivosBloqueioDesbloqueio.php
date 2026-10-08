<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadMotivosBloqueioDesbloqueio extends Model
{
    protected $table = 'cad_motivos_bloqueio_desbloqueio';

    protected $primaryKey = 'blo_id';

    public $timestamps = false;
}
