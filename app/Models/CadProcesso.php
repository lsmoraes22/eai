<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadProcesso extends Model
{
    protected $fillable = [
	'name',
	'initial_format',
	'final_format',
	'user_create_id',
	'active'
    ];

    protected $cast = [
	'active' => 'boolean'
    ];

    public function endpoint()
    {
        return $this->belongsTo(CadEndpoint::class);
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
