<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadProcesso extends Model
{
    protected $fillable = [
	'name',
	'input_endpoint_id',
	'output_endpoint_id',
	'input_collection_path',
	'output_mode',
	'initial_format',
	'final_format',
	'user_create_id',
	'active'
    ];

    protected $casts = [
	'active' => 'boolean'
    ];

    public function inputEndpoint()
    {
        return $this->belongsTo(CadEndpoint::class, 'input_endpoint_id');
    }

    public function outputEndpoint()
    {
        return $this->belongsTo(CadEndpoint::class, 'output_endpoint_id');
    }

    public function txtLayout()
    {
        return $this->hasMany(CadProcessoTxtLayout::class, 'layout_id');
    }

    public function processoDepara()
    {
        return $this->hasMany(CadProcessosDepara::class, 'processo_id');
    }

}
