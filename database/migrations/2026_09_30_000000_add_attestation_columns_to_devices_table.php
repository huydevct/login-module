<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public key cua key trong Android Keystore sau khi attest thanh cong.
 * public_key_pem = null nghia la device chua attest.
 */
return new class extends Migration
{
    private array $columns = ['public_key_pem', 'security_level', 'attested_at'];

    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            if (! Schema::hasColumn('devices', 'public_key_pem')) {
                $table->text('public_key_pem')->nullable();
            }
            if (! Schema::hasColumn('devices', 'security_level')) {
                $table->string('security_level', 20)->nullable()->comment('TEE | StrongBox');
            }
            if (! Schema::hasColumn('devices', 'attested_at')) {
                $table->timestamp('attested_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach ($this->columns as $column) {
            if (Schema::hasColumn('devices', $column)) {
                Schema::table('devices', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
