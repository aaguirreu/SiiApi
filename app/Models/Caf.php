<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Caf extends Model
{
    use HasFactory;

    public function dte(): BelongsToMany
    {
        return $this->belongsToMany(EnvioDteM::class,
            'setdte_caf',
            'caf_id',
            'enviodte_id'
        );
    }
}
