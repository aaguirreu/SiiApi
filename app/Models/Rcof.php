<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rcof extends Model
{
    use HasFactory;
    public function dte(): BelongsTo
    {
        return $this->belongsTo(Dte::class, 'id', 'id');
    }
}
