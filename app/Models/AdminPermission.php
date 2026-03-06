<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPermission extends Model
{
    protected $fillable = [
        'key',
        'label',
        'group',
        'is_protected',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
    ];

    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_permissions')
            ->withTimestamps();
    }
}