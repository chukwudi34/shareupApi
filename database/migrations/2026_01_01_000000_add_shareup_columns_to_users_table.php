<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('role')->default('PARTICIPANT')->after('password');
            $table->string('status')->default('ACTIVE')->after('role');
            $table->string('kyc_status')->default('UNVERIFIED')->after('status');
            $table->string('avatar_url')->nullable()->after('kyc_status');
            $table->unsignedBigInteger('wallet_balance')->default(0)->after('avatar_url');
            $table->boolean('phone_verified')->default(false)->after('wallet_balance');
            $table->boolean('mfa_enabled')->default(false)->after('phone_verified');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone', 'role', 'status', 'kyc_status', 'avatar_url',
                'wallet_balance', 'phone_verified', 'mfa_enabled',
            ]);
        });
    }
};
