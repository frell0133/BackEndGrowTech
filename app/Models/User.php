<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const TIER_MEMBER   = 'member';
    public const TIER_RESELLER = 'reseller';
    public const TIER_VIP      = 'vip';

    public static function allowedTiers(): array
    {
        return [self::TIER_MEMBER, self::TIER_RESELLER, self::TIER_VIP];
    }

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
        'tier',
        'referral_code',
        'provider',
        'provider_id',
        'avatar',
        'avatar_path',
        'admin_role_id',
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

    public function referredUsers()
    {
        return $this->hasMany(Referral::class, 'referred_by');
    }

    public function referrerRelation()
    {
        return $this->hasOne(Referral::class, 'user_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function cart()
    {
        return $this->hasOne(\App\Models\Cart::class);
    }

    protected static function booted(): void
    {
        static::creating(function ($user) {
            if (!empty($user->referral_code)) {
                $user->referral_code = static::normalizeReferralCode((string) $user->referral_code);
                return;
            }

            $user->referral_code = static::generateUniqueReferralCode();
        });
    }

    public static function normalizeReferralCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function generateUniqueReferralCode(): string
    {
        $prefix = 'GTC-';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $suffix = '';

            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            $code = $prefix . $suffix;
        } while (static::where('referral_code', $code)->exists());

        return $code;
    }

    public function favorites()
    {
        return $this->hasMany(\App\Models\Favorite::class);
    }

    public function adminRole()
    {
        return $this->belongsTo(\App\Models\AdminRole::class, 'admin_role_id');
    }

    public function isAdmin(): bool
    {
        return ($this->role === 'admin') && !is_null($this->admin_role_id);
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && ($this->adminRole?->is_super === true);
    }

    public function adminPermissionKeys(): array
    {
        if (!$this->isAdmin()) return [];
        if ($this->isSuperAdmin()) return ['*'];

        $this->loadMissing(['adminRole.permissions']);
        return $this->adminRole?->permissions?->pluck('key')->values()->all() ?? [];
    }

    public function canAdmin(string $permissionKey): bool
    {
        if (!$this->isAdmin()) return false;
        if ($this->isSuperAdmin()) return true;

        $this->loadMissing(['adminRole.permissions']);
        return $this->adminRole?->permissions?->contains('key', $permissionKey) ?? false;
    }
}