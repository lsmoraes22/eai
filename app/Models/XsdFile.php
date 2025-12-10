<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Client;

class XsdFile extends Model
{
    protected $fillable = [
        'client_id',
	'name',
        'filename',
        'description',
        'active',
	'path',
	'temp_file',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

}
