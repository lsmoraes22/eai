<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class CadEndpoint extends Model
{
    protected $table = 'cad_endpoints';

    protected $fillable = [
        'nome', 'tipo', 'metodo', 'client_id', 'url', 'extensao', 'namespace',
	'headers', 'autenticacao', 'auth_user', 'direcao', 'timer', 'next_run',
	'type_storage_token', 'auth_pass', 'auth_token', 'ativo', 'timeout',
	'tentativas', 'descricao', 'payload', 'auth_api_way'
    ];

    protected $casts = [
        'headers' => 'array',
        'ativo' => 'boolean',
	    'next_run' => 'datetime',
    ];

    /**
     * Calcula o próximo timestamp de execução arredondado.
     * Assume que $this->timer é o intervalo em minutos.
     *
     * @return Carbon
     */
    public function roundedTimestamp(): Carbon
    {
        $interval = (int) $this->timer;

        if ($interval <= 0) {
            // Se o timer for 0 ou negativo, retorna agora ou lança uma exceção.
            return Carbon::now();
        }

        // Obtém o timestamp atual
        $now = Carbon::now();

        // Clona para não modificar $now e arredonda para o intervalo de minutos
        $nextRun = $now->copy()->addMinutes($interval)->startOfMinute();

        // Subtrai o resto para encontrar o último intervalo, depois adiciona o intervalo
        // Ex: Se o timer é 15, e a hora é 15:07, ele retorna 15:15.
        $nextRun = $now->copy()->addMinutes($interval - ($now->minute % $interval))->startOfMinute();

        // Versão mais limpa do Carbon para arredondamento, se o Carbon suportar (depende da versão)
        // return $now->copy()->ceilMinute($interval);

        return $nextRun;
    }

    public function processo()
    {
        return $this->hasMany(CadProcesso::class);
    }

    public function client()
    {
	return $this->belongsTo(Client::class);
    }
}
