<?php

namespace Database\Seeders;

use App\Models\ShippingZone;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MarketplaceSeeder extends Seeder
{
    /**
     * Columns: name, store email, contact, city, state, postcode, commission rate, status.
     *
     * @var list<array{string, string, string, string, string, string, float|int, string}>
     */
    protected array $vendors = [
        ['Urban Thread', 'hello@urbanthread.in', 'Ravi Malhotra', 'Noida', 'Uttar Pradesh', '201301', 12.5, 'approved'],
        ['Sonata Audio', 'care@sonata-audio.in', 'Priya Nair', 'Bengaluru', 'Karnataka', '560034', 15, 'approved'],
        ['HearthMade', 'orders@hearthmade.in', 'Anjali Deshmukh', 'Pune', 'Maharashtra', '411004', 10, 'approved'],
        ['Glow Lab', 'support@glowlab.co.in', 'Sana Qureshi', 'Mumbai', 'Maharashtra', '400050', 18, 'approved'],
        ['Trailhead Outdoors', 'team@trailhead.in', 'Karan Bisht', 'Dehradun', 'Uttarakhand', '248001', 11, 'approved'],
        ['IronCore Fitness', 'sales@ironcore.fit', 'Deepak Yadav', 'Gurugram', 'Haryana', '122002', 14, 'approved'],
        ['Casa Luz', 'hi@casaluz.in', 'Meera Iyer', 'Chennai', 'Tamil Nadu', '600028', 13, 'approved'],
        ['Woodline Furniture', 'contact@woodline.in', 'Harpreet Singh', 'Jodhpur', 'Rajasthan', '342001', 9, 'approved'],
        ['Voltix Accessories', 'help@voltix.in', 'Nikhil Rao', 'Hyderabad', 'Telangana', '500081', 16, 'pending'],
        ['Maison Nine', 'concierge@maisonnine.in', 'Farah Khan', 'New Delhi', 'Delhi', '110016', 20, 'pending'],
        ['NeatNest Storage', 'info@neatnest.in', 'Vikram Shetty', 'Kolkata', 'West Bengal', '700019', 12, 'suspended'],
        ['Stride Footwear', 'wholesale@stride.in', 'Ayesha Siddiqui', 'Agra', 'Uttar Pradesh', '282001', 15, 'rejected'],
    ];

    public function run(): void
    {
        foreach ($this->vendors as [$name, $email, $contact, $city, $state, $postcode, $commission, $status]) {
            Vendor::create([
                'name' => $name,
                'slug' => Str::slug($name),
                'store_email' => $email,
                'phone' => '+91 '.random_int(70000, 99999).random_int(10000, 99999),
                'logo_path' => 'https://picsum.photos/seed/'.Str::slug($name).'/200/200',
                'description' => "{$name} has been supplying quality products since ".random_int(2012, 2022).'. Every order is packed and quality-checked in-house before it leaves the warehouse.',
                'status' => $status,
                'commission_type' => 'percentage',
                'commission_rate' => $commission,
                'contact_name' => $contact,
                'address_line1' => random_int(1, 240).', '.collect(['Sector', 'Phase', 'Block'])->random().' '.random_int(1, 40),
                'address_line2' => collect(['Industrial Area', 'Market Road', 'Civil Lines', null])->random(),
                'city' => $city,
                'state' => $state,
                'postcode' => $postcode,
                'country' => 'IN',
                'gst_number' => strtoupper(Str::random(2)).random_int(1000, 9999).strtoupper(Str::random(5)).random_int(1, 9).'Z'.random_int(1, 9),
                'payout_method' => collect(['bank', 'upi'])->random(),
                'bank_account_name' => $name,
                'bank_account_number' => (string) random_int(100000000000, 999999999999),
                'bank_ifsc' => strtoupper(Str::random(4)).'0'.random_int(100000, 999999),
                'rating' => round(random_int(36, 49) / 10, 2),
                'approved_at' => $status === 'approved' ? now()->subDays(random_int(30, 500)) : null,
                'rejection_reason' => $status === 'rejected' ? 'Incomplete GST documentation. Reapply once verified.' : null,
                'created_at' => now()->subDays(random_int(40, 600)),
            ]);
        }

        $this->seedShipping();
    }

    protected function seedShipping(): void
    {
        $domestic = ShippingZone::create([
            'name' => 'Domestic – India',
            'description' => 'All PIN codes across India.',
            'countries' => ['IN'],
            'states' => [],
            'is_active' => true,
            'position' => 0,
        ]);

        $domestic->rates()->createMany([
            [
                'name' => 'Standard delivery',
                'type' => 'flat',
                'rate' => 59,
                'free_above_amount' => 999,
                'delivery_days_min' => 4,
                'delivery_days_max' => 7,
                'is_active' => true,
                'position' => 0,
            ],
            [
                'name' => 'Express delivery',
                'type' => 'flat',
                'rate' => 149,
                'delivery_days_min' => 1,
                'delivery_days_max' => 2,
                'is_active' => true,
                'position' => 1,
            ],
            [
                'name' => 'Heavy items (weight based)',
                'type' => 'weight_based',
                'rate' => 99,
                'per_kg_rate' => 25,
                'min_weight' => 5,
                'delivery_days_min' => 5,
                'delivery_days_max' => 10,
                'is_active' => true,
                'position' => 2,
            ],
        ]);

        $metro = ShippingZone::create([
            'name' => 'Metro cities',
            'description' => 'Faster network in tier-1 metros.',
            'countries' => ['IN'],
            'states' => ['Delhi', 'Maharashtra', 'Karnataka', 'Tamil Nadu', 'Telangana', 'West Bengal'],
            'is_active' => true,
            'position' => 1,
        ]);

        $metro->rates()->createMany([
            [
                'name' => 'Same-day (metro)',
                'type' => 'flat',
                'rate' => 199,
                'min_order_amount' => 499,
                'delivery_days_min' => 0,
                'delivery_days_max' => 1,
                'is_active' => true,
                'position' => 0,
            ],
            [
                'name' => 'Free metro shipping',
                'type' => 'free',
                'rate' => 0,
                'min_order_amount' => 1999,
                'delivery_days_min' => 2,
                'delivery_days_max' => 4,
                'is_active' => true,
                'position' => 1,
            ],
        ]);

        $international = ShippingZone::create([
            'name' => 'International',
            'description' => 'Selected countries. Duties payable by the customer.',
            'countries' => ['AE', 'SG', 'GB', 'US', 'AU'],
            'states' => [],
            'is_active' => true,
            'position' => 2,
        ]);

        $international->rates()->create([
            'name' => 'International economy',
            'type' => 'weight_based',
            'rate' => 999,
            'per_kg_rate' => 450,
            'delivery_days_min' => 10,
            'delivery_days_max' => 21,
            'is_active' => true,
            'position' => 0,
        ]);

        // A vendor-specific rate to demonstrate per-vendor shipping.
        $vendor = Vendor::approved()->inRandomOrder()->first();

        if ($vendor) {
            $domestic->rates()->create([
                'name' => "{$vendor->name} — free shipping",
                'vendor_id' => $vendor->id,
                'type' => 'free',
                'rate' => 0,
                'min_order_amount' => 799,
                'delivery_days_min' => 3,
                'delivery_days_max' => 6,
                'is_active' => true,
                'position' => 3,
            ]);
        }
    }
}
