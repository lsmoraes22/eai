<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadProcessoTxtLayout extends Model
{
    protected $fillable = [
        'processo_id',
        'section_type',
        'format',
        'delimiter',
        'line_order',
        'total_length',
        'active',
    ];

    public function processo()
    {
        return $this->belongsTo(CadProcesso::class);
    }

    public function campos()
    {
        return $this->hasMany(CadProcessoTxtCampo::class, 'layout_id');
    }

}
