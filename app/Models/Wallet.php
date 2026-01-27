<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $fillable = [
        'user_id', 'code', 'balance', 'currency', 'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
