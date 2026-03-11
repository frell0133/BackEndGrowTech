<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthChallenge extends Model
{
    protected $fillable = [
        'challenge_id',
        'user_id',
        'purpose',
        'channel',
        'email',
        'otp_hash',
        'expires_at',
        'resend_available_at',
        'attempt_count',
        'max_attempts',
        'resend_count',
        'remember',
        'provider',
        'meta',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'consumed_at' => 'datetime',
            'remember' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}