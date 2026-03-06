<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminPermission extends Model
{
    protected $fillable = ['key', 'label', 'group'];

    public function roles()
    {
        return $this->belongsToMany(AdminRole::class, 'admin_role_permissions')
            ->withTimestamps();
    }
}