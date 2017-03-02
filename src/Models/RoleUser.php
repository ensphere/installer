<?php

namespace Ensphere\Installer\Models;

class RoleUser
{
    protected $table = 'role_user';

    protected $fillable = [ 'role_id', 'user_id', 'created_at', 'updated_at' ];
}
