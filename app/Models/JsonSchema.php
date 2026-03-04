<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JsonSchema extends Model
{

    protected $fillable = [
	'client_id',
	'name',
	'filename',
	'path',
	'description'
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

}
