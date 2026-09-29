<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('devices')) {
            return;
        }

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->bigInteger('app_id')->index()->default(0);
            $table->string('client_id');
            $table->tinyInteger('active')->default(1)->index();
            $table->string('client_id_md5', 32)->unique();
            $table->tinyInteger('platform')->index()->default(0)->comment('0: None, 1: Android, 2: IOS');
            $table->integer('last_login')->default(0);
            $table->string('secret', 32)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
