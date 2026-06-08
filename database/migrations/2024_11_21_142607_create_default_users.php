<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $users = [
            [
                'id' => 1,
                'name' => 'Lucas Zacharias',
                'email' => 'lucas@zacharias-net.de',
                'email_verified_at' => now(),
                'password' => '$2y$12$tJNewrPc1YwBi5HbezRPfuAJtb3IgQBj/wbx.CcOmEgjaH/vywYnS',
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'current_team_id' => 1,
                'profile_photo_path' => null,
                'role' => 'admin',
                'status' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'name' => 'Testzugang-Teilnehmer',
                'email' => 'test-teilnehmer@example.com',
                'email_verified_at' => now(),
                'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'current_team_id' => null,
                'profile_photo_path' => null,
                'role' => 'guest',
                'status' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'name' => 'Testzugang-Tutor',
                'email' => 'test-tutor@example.com',
                'email_verified_at' => now(),
                'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'current_team_id' => null,
                'profile_photo_path' => null,
                'role' => 'tutor',
                'status' => true,
                'deleted_at' => null,
            ],
            [
                'id' => 4,
                'name' => 'Testzugang-Mitarbeiter',
                'email' => 'test-mitarbeiter@example.com',
                'email_verified_at' => now(),
                'password' => '$2y$12$w791IJjpJA6w2JEyXOq0WO3qjIvYL81/JNfxE.EsuCiU6pcelRcSq',
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => null,
                'current_team_id' => null,
                'profile_photo_path' => null,
                'role' => 'staff',
                'status' => true,
                'deleted_at' => null,
            ],
        ];

        foreach ($users as $user) {
            DB::table('users')->updateOrInsert(
                ['email' => $user['email']],
                [
                    ...$user,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('users')
            ->whereIn('email', [
                'lucas@zacharias-net.de',
                'test-teilnehmer@example.com',
                'test-tutor@example.com',
                'test-mitarbeiter@example.com',
            ])
            ->delete();
    }
};
