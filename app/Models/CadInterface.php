<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadInterface extends Model
{
    protected $primaryKey = 'ci_id';
    protected $table = 'cad_interfaces';
    protected $fillable = [
	'ci_tipo',
	'ci_ot1',
	'ci_data',
	'ci_usuario',
	'ci_interface_gerada'
    ];
}
