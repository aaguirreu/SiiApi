<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class EnvioDteM extends Model
{
    use HasFactory;

    public function caf(): BelongsToMany
    {
        return $this->belongsToMany(Caf::class,
            'setdte_caf',
            'enviodte_id',
            'caf_id'
        );
    }
}
