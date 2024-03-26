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
    public mixed $rut_emisor;
    /**
     * @var \Illuminate\Support\HigherOrderCollectionProxy|mixed|string
     */
    public mixed $estado;
    /**
     * @var \Illuminate\Support\HigherOrderCollectionProxy|mixed|null
     */
    public mixed $track_id;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'envio_pasarela';

    protected $fillable = ['estado', 'trackid'];

    use HasFactory;
}
