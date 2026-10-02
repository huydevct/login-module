<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Khoa tra cuu thiet bi: MD5 raw 16 byte cua (app_id, device_id) — xem DeviceIdHasher.
 * NULL cho dong chua backfill (php artisan login:backfill-device-hash) va dong trung hash
 * sau chuan hoa (giu dong cu nhat). client_id_md5 + cac index cu giu lai de rollback duoc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('devices', 'device_id_hash')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->binary('device_id_hash', 16, true)->nullable();
            $table->unique('device_id_hash', 'uk_devices_device_id_hash');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('devices', 'device_id_hash')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique('uk_devices_device_id_hash');
            $table->dropColumn('device_id_hash');
        });
    }
};
