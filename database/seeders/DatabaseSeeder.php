<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a fully populated demo store.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@marketplace.test'],
            [
                'name' => 'Store Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ],
        );

        User::firstOrCreate(
            ['email' => 'staff@marketplace.test'],
            [
                'name' => 'Ops Staff',
                'password' => Hash::make('password'),
                'role' => 'staff',
                'email_verified_at' => now(),
            ],
        );

        $this->call([
            MarketplaceSeeder::class,
            CatalogSeeder::class,
            SalesSeeder::class,
        ]);

        foreach ([
            'store_name' => 'Marketplace',
            'store_email' => 'support@marketplace.test',
            'store_phone' => '+91 98100 00000',
            'currency' => 'INR',
            'weight_unit' => 'kg',
            'order_prefix' => '#',
            'default_commission' => '12',
            'auto_approve_vendors' => '0',
            'auto_approve_cancellations' => '0',
            'low_stock_threshold' => '8',
            'address' => 'Plot 21, Sector 62, Noida, Uttar Pradesh 201301, India',
        ] as $key => $value) {
            Setting::put($key, $value);
        }
    }
}
