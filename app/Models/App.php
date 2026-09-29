<?php

namespace Modules\Login\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ung dung (package id) ma device dang ky qua add-device.
 */
class App extends Model
{
    protected $table = 'apps';

    protected $fillable = [
        'platform',
        'name',
        'package_id',
        'created_at',
        'updated_at',
    ];

    public function devices()
    {
        return $this->hasMany(Device::class, 'app_id');
    }
}
