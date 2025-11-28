<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadProcessosDepara extends Model
{
    protected $fillable = [
        'processo_id',
        'input_path',
        'output_path',
        'data_type',
        'default_value',
        'active',
	'order'
    ];

    public function processo()
    {
        return $this->belongsTo(CadProcesso::class, 'processo_id');
    }

}
