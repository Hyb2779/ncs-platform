<?php

namespace Database\Seeders;

use App\Enums\Currency;
use App\Enums\Language;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OwnerSeeder extends Seeder
{
    public function run(): void
    {
        $username = (string) env('OWNER_USERNAME', '');

        if ($username === '') {
            $this->command?->error('OWNER_USERNAME is empty.');

            return;
        }

        if (User::query()->where('username', $username)->exists()) {
            return;
        }

        $password = (string) env('OWNER_PASSWORD', '');
        $generated = false;

        if ($password === '') {
            $password = Str::password(24);
            $generated = true;
        }

        $owner = User::query()->create([
            'username' => $username,
            'password' => $password,
            'role' => UserRole::Owner,
            'parent_id' => null,
            'path' => '/',
            'depth' => 0,
            'superadmin_id' => null,
            'language' => Language::Tr,
            'currency' => Currency::Try,
            'timezone' => 'UTC',
            'commission_rate' => 0,
            'status' => UserStatus::Active,
            'user_limit' => null,
        ]);

        $owner->path = '/'.$owner->id.'/';
        $owner->save();

        if ($generated) {
            $this->command?->warn('OWNER_PASSWORD was empty. Generated password: '.$password);
        }
    }
}
