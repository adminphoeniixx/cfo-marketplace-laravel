<?php

namespace Database\Factories;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        return [
            'question' => 'When will my order arrive?',
            'answer' => 'Most orders arrive in four to six days.',
            'topic' => 'orders',
            'position' => 0,
            'is_active' => true,
        ];
    }
}
