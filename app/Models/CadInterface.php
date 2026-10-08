<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadInterface extends Model
{
    protected $primaryKey = 'ci_id';
    protected $table = 'cad_interfaces';
    public $timestamps = false;

    protected $fillable = [
	'ci_tipo',
	'ci_ot',
	'ci_data',
	'ci_usuario',
	'ci_interface_gerada'
    ];
}
