<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenSii extends Model
{
    use HasFactory;

    protected $table = 'tokens_sii';

    protected $fillable = [
        'rut',
        'tokens',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'tokens' => 'array',
    ];
}
