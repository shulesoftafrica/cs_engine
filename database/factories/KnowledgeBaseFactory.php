<?php

namespace Database\Factories;

use App\Models\KnowledgeBase;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class KnowledgeBaseFactory extends Factory
{
    protected $model = KnowledgeBase::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'content' => fake()->paragraphs(3, true),
            'permissions' => null,
            'embedding_vector' => null,
        ];
    }
}