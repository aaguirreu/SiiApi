<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * @mixin Builder
 */
class Envio extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'envio_pasarela';

    protected $fillable = ['estado', 'track_id'];

    use HasFactory;
}
