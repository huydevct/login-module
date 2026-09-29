<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project Laravel nao cung da co bang users (migration mac dinh), nen module chi
 * them cac cot no can. Neu chua co bang users thi tao moi day du.
 */
return new class extends Migration
{
    private array $columns = ['login_name', 'device_id', 'social_id', 'social_type', 'role', 'avatar', 'refresh_token', 'is_online', 'active'];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('password');
                $table->timestamp('email_verified_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'login_name')) {
                $table->string('login_name')->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'device_id')) {
                $table->string('device_id')->default(0)->index();
            }
            if (! Schema::hasColumn('users', 'social_id')) {
                $table->string('social_id')->index()->nullable();
            }
            if (! Schema::hasColumn('users', 'social_type')) {
                $table->integer('social_type')->index()->default(0)->comment('0: Tạo bởi admin, 1: Google,2: Facebook, 3: Apple, 4: Devices ID');
            }
            if (! Schema::hasColumn('users', 'role')) {
                $table->integer('role')->index()->default(0)->comment('0: user normal, 1: admin');
            }
            if (! Schema::hasColumn('users', 'avatar')) {
                $table->json('avatar')->nullable();
            }
            if (! Schema::hasColumn('users', 'refresh_token')) {
                $table->string('refresh_token')->nullable();
            }
            if (! Schema::hasColumn('users', 'is_online')) {
                $table->tinyInteger('is_online')->default(0);
            }
            if (! Schema::hasColumn('users', 'active')) {
                $table->tinyInteger('active')->default(1)->index();
            }
        });
    }

    public function down(): void
    {
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
