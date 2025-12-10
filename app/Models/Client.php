<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\XsdFile;

class Client extends Model
{
    protected $fillable = [
	'name',
	'code',
	'telefone',
	'endereco',
	'cnpj',
	'active',
    ];

	public function xsdFiles()
	{
	    return $this->hasMany(XsdFile::class);
	}

}
