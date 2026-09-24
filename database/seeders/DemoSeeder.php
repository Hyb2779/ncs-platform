<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(OwnerSeeder::class);

        $owner = User::query()->where('role', 'owner')->first();

        if ($owner === null) {
            return;
        }

        $hierarchy = app(HierarchyService::class);
        $trees = [
            ['username' => 'demo-tr', 'language' => 'tr', 'currency' => 'TRY', 'timezone' => 'Europe/Istanbul'],
            ['username' => 'demo-de', 'language' => 'de', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin'],
        ];

        foreach ($trees as $index => $tree) {
            $superadmin = $hierarchy->create($owner, [
                'username' => $tree['username'],
                'password' => 'password',
                'commission_rate' => 10,
                'user_limit' => null,
                'note' => null,
                'language' => $tree['language'],
                'currency' => $tree['currency'],
                'timezone' => $tree['timezone'],
            ]);

            foreach ([1, 2] as $bayiNumber) {
                $bayi = $hierarchy->create($superadmin, [
                    'username' => $tree['username'].'-bayi-'.$bayiNumber,
                    'password' => 'password',
                    'commission_rate' => 5,
                    'user_limit' => null,
                    'note' => null,
                ]);

                foreach ([1, 2, 3] as $memberNumber) {
                    $hierarchy->create($bayi, [
                        'username' => $tree['username'].'-uye-'.$bayiNumber.'-'.$memberNumber,
                        'password' => 'password',
                        'commission_rate' => 0,
                        'user_limit' => null,
                        'note' => null,
                    ]);
                }
            }
        }
    }
}
