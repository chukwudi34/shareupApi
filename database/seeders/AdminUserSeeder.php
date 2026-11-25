<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the credentials used to operate and test ShareUp.
 *
 * Run standalone with:
 *   php artisan db:seed --class=AdminUserSeeder
 */
class AdminUserSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@shareup.ng';

    public const PARTICIPANT_EMAIL = 'demo@shareup.ng';

    public const PASSWORD = 'ShareUp2026!';

    public function run(): void
    {
        $password = Hash::make(self::PASSWORD);

        $admin = User::firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'Tunde Bakare',
                'phone' => '+234 801 000 0001',
                'password' => $password,
                'role' => 'ADMIN',
                'status' => 'ACTIVE',
                'kyc_status' => 'VERIFIED',
                'email_verified_at' => now(),
            ],
        );

        $participant = User::firstOrCreate(
            ['email' => self::PARTICIPANT_EMAIL],
            [
                'name' => 'Adaeze Okafor',
                'phone' => '+234 802 345 6789',
                'password' => $password,
                'role' => 'PARTICIPANT',
                'status' => 'ACTIVE',
                'kyc_status' => 'VERIFIED',
                'wallet_balance' => 4_250_000,
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info('Admin ready: '.$admin->email.' / '.self::PASSWORD);
        $this->command?->info('Test participant ready: '.$participant->email.' / '.self::PASSWORD);
    }
}
