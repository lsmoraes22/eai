<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CadEndpoint extends Model
{
    protected $table = 'cad_endpoints';

    protected $fillable = [
        'nome', 'tipo', 'metodo', 'client_id', 'url', 'extensao', 'namespace',
	'headers', 'autenticacao', 'auth_user', 'direcao',
	'auth_pass', 'auth_token', 'ativo', 'timeout', 'tentativas', 'descricao', 'payload'
    ];

    protected $casts = [
        'headers' => 'array',
        'ativo' => 'boolean',
    ];


    public function processo()
    {
        return $this->hasMany(CadProcesso::class);
    }

}
