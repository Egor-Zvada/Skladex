<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('auth_provider', ['local', 'ldap'])->default('local');
            $table->char('directory_guid', 32)->nullable()->charset('ascii')->collation('ascii_bin')->unique();
            $table->dateTime('directory_checked_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('users')->where('auth_provider', 'ldap')->exists()) {
            throw new RuntimeException('Directory users exist; do not turn them into local password accounts.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['directory_guid', 'directory_checked_at', 'auth_provider']));
    }
};
