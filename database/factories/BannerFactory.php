<?php

namespace Database\Factories;

use App\Models\Banner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Banner>
 */
class BannerFactory extends Factory
{
    protected $model = Banner::class;

    public function definition(): array
    {
        return [
            'title' => 'Festive edit',
            'subtitle' => 'Up to 40% off handloom',
            'image_path' => 'cfo/banners/festive.jpg',
            'deeplink_route' => 'category',
            'deeplink_params' => ['category_id' => 1],
            'position' => 0,
            'is_active' => true,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['ends_at' => now()->subDay()]);
    }
}
