<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('apps')) {
            return;
        }

        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index()->nullable();
            $table->integer('platform')->default(0)->index()->comment('0: Không xác định, 1: Android, 2: IOS');
            $table->string('package_id')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apps');
    }
};
