<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadInterfacePlanejamento extends Model
{
    protected $table = 'cad_interface_planejamento';

    protected $primaryKey = 'cip_id';

    public $timestamps = false;
}
