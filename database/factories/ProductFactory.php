<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'phone_number' => fake()->unique()->numerify('2557########'),
            'session_id' => fake()->unique()->numerify('80###'),
            'wasender_api_key' => fake()->sha256(),
            'webhook_secret' => fake()->sha256(),
            'config' => [
                'api_url' => fake()->url(),
                'method' => 'POST',
                'auth_key' => fake()->sha256(),
            ],
            'support_email' => fake()->safeEmail(),
            'is_active' => true,
        ];
    }
}