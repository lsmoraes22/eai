<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CadWebhook extends Model
{
    protected $fillable = [
        'client_id',
        'nome',
        'ativo',
        'webhook_verify',
        'webhook_header',
        'webhook_algo',
        'descricao',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    protected static function booted()
    {
        static::saving(function ($webhook) {
            // Transforma "Bling Contatos" em "bling-contatos" automaticamente
            $webhook->nome = \Illuminate\Support\Str::slug($webhook->nome);
        });
    }

}
