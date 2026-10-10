<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateSubOwnerCommand extends Command
{
    protected $signature = 'wegas:create-sub-owner {username} {--password= : İlk şifre; ilk girişte değiştirilmesi zorunlu}';

    protected $description = 'Ana owner altında ikinci bir owner açar';

    public function handle(ActivityLogger $activity): int
    {
        if (User::withTrashed()->where('role', UserRole::Owner)->whereNotNull('parent_id')->exists()) {
            $this->error('İkinci owner zaten var.');

            return self::FAILURE;
        }

        $root = User::query()->where('role', UserRole::Owner)->whereNull('parent_id')->orderBy('id')->first();
        if ($root === null) {
            $this->error('Ana owner yok.');

            return self::FAILURE;
        }

        $username = (string) $this->argument('username');
        $password = (string) $this->option('password');
        $check = Validator::make(
            ['username' => $username, 'password' => $password],
            ['username' => ['required', 'string', 'max:64', 'alpha_dash'], 'password' => ['required', 'string', 'min:4', 'max:255']],
            [
                'username.required' => 'Kullanıcı adı gerekli.',
                'username.alpha_dash' => 'Kullanıcı adı yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
                'username.max' => 'Kullanıcı adı en fazla 64 karakter olmalı.',
                'password.required' => 'Şifre gerekli: --password=',
                'password.min' => 'Şifre en az 4 karakter olmalı.',
                'password.max' => 'Şifre en fazla 255 karakter olmalı.',
            ],
        );
        if ($check->fails()) {
            foreach ($check->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::withTrashed()->where('username', $username)->exists()) {
            $this->error('Bu kullanıcı adı kullanılıyor.');

            return self::FAILURE;
        }

        $user = new User([
            'username' => $username,
            'password' => $password,
            'role' => UserRole::Owner,
            'parent_id' => $root->id,
            'path' => '/',
            'depth' => $root->depth + 1,
            'superadmin_id' => null,
            'language' => $root->language,
            'currency' => $root->currency,
            'timezone' => $root->timezone,
            'commission_rate' => 0,
            'status' => UserStatus::Active,
        ]);
        $user->must_change_password = true;
        $user->save();
        $user->path = $root->path.$user->id.'/';
        $user->save();

        $activity->write($root, 'user.created', $user, [
            'role' => $user->role->value,
            'username' => $user->username,
        ]);

        $this->info($user->username.' #'.$user->id.' açıldı. parent_id='.$root->id.' path='.$user->path.' İlk girişte şifre değişecek.');

        return self::SUCCESS;
    }
}
