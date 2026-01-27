<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LedgerTransaction extends Model
{
    protected $fillable = [
        'type', 'status', 'idempotency_key', 'reference_type', 'reference_id', 'note',
    ];

    public function entries()
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
