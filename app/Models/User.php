<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Str;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
    'name',
    'full_name',
    'address',
    'email',
    'password',
    'role',
    'referral_code',
    'provider',
    'provider_id',
    'avatar',
    'avatar_path',
    ];


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function withdrawRequests()
    {
        return $this->hasMany(WithdrawRequest::class);
    }

    public function referral()
    {
        return $this->hasOne(Referral::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    protected static function booted(): void
    {
        static::creating(function ($user) {
            if (!empty($user->referral_code)) return;

            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            do {
                $code = '';
                for ($i=0; $i<7; $i++) {
                    $code .= $alphabet[random_int(0, strlen($alphabet)-1)];
                }
            } while (static::where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });
    }
    

}
