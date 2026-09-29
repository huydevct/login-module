<?php

namespace Modules\Login\Models;

use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    protected $table = 'devices';

    protected $fillable = [
        'name',
        'client_id',
        'client_id_md5',
        'app_id',
        'platform',
        'last_login',
        'secret',
    ];

    protected $hidden = [
        'secret',
    ];

    public function app()
    {
        return $this->belongsTo(App::class, 'app_id', 'id');
    }
}
