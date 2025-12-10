<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadInterfaceStatus extends Model
{
    protected $table = 'cad_interface_status';

    protected $primaryKey = 'int_id';

    public $timestamps = false;

    protected $fillable = [
//	'int_id',
	'int_direcao',
	'int_interface',
	'int_arquivo',
	'int_idoc',
	'int_status',
	'int_data_processamento',
	'int_envio',
	'int_mensagem',
	'int_data_envio'
    ];
}
