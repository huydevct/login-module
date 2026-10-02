<?php

namespace Modules\Login\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Login\Services\DeviceService\DeviceIdHasher;

class Device extends Model
{
    protected $table = 'devices';

    protected $fillable = [
        'name',
        'client_id',
        'client_id_md5',
        'device_id_hash',
        'app_id',
        'platform',
        'last_login',
        'secret',
        'public_key_pem',
        'security_level',
        'attested_at',
    ];

    /**
     * device_id_hash la BINARY(16), khong phai UTF-8 hop le: de lo ra JSON se nem "Malformed UTF-8".
     */
    protected $hidden = [
        'secret',
        'device_id_hash',
    ];

    protected $casts = [
        'attested_at' => 'datetime',
    ];

    /**
     * Device::forDeviceId($deviceId, $appId)->first(). Co y khong dung cast cho cot hash:
     * cast khong ap dung cho where(), quen 1 cho la query tra rong am tham.
     */
    public function scopeForDeviceId(Builder $query, string $deviceId, int $appId): Builder
    {
        return $query->where('device_id_hash', DeviceIdHasher::hashDevice($appId, $deviceId));
    }

    public function app()
    {
        return $this->belongsTo(App::class, 'app_id', 'id');
    }
}
