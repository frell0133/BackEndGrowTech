<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminRole extends Model
{
    protected $fillable = ['name', 'slug', 'is_super'];

    public function permissions()
    {
        return $this->belongsToMany(AdminPermission::class, 'admin_role_permissions')
            ->withTimestamps();
    }

    public function users()
    {
        return $this->hasMany(User::class, 'admin_role_id');
    }
}