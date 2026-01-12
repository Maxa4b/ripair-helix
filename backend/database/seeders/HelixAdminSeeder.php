<?php

namespace Database\Seeders;

use App\Models\HelixUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class HelixAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = env('HELIX_ADMIN_PASSWORD', 'changeme123!');

        HelixUser::updateOrCreate(
            ['email' => env('HELIX_ADMIN_EMAIL', 'admin@ripair.shop')],
            [
                'first_name' => env('HELIX_ADMIN_FIRST_NAME', 'Helix'),
                'last_name' => env('HELIX_ADMIN_LAST_NAME', 'Admin'),
                'role' => 'owner',
                'is_active' => true,
                'password_hash' => Hash::make($password),
            ]
        );
    }
}
