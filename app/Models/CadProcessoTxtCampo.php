<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadProcessoTxtCampo extends Model
{
    protected $fillable = [
        'layout_id',
        'campo_origem',
        'campo_destino',
        'posicao_inicial',
        'tamanho',
        'align',
        'mask',
        'uppercase',
        'ordem'
    ];

    public function txtLayout()
    {
        return $this->belongsTo(CadProcessoTxtLayout::class, 'layout_id');
    }
}
