<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Dte extends Model
{
    use HasFactory;

    public function caf(): HasMany
    {
        return $this->hasMany(Caf::class,
            'id',
            'caf_id',
        );
    }

    public function dte(): HasOne
    {
        return $this->hasOne(Rcof::class,
            'id',
            'id');
    }
}
