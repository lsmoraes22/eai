<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkedResource extends Model
{
    public const PENDING = 'pending';

    public const CAPTURING = 'capturing';

    public const CAPTURED = 'captured';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $hidden = ['download_url'];

    protected $casts = [
        'download_url' => 'encrypted',
        'captured_at' => 'datetime',
        'byte_size' => 'integer',
        'attempts' => 'integer',
    ];

    public function endpoint()
    {
        return $this->belongsTo(CadEndpoint::class, 'endpoint_id');
    }
}
