<?php

namespace Database\Seeders;

use App\Models\KnowledgeBase;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $product = Product::factory()->create([
            'name' => 'Shulesoft MS',
            'config' => [
                'api_url' => 'http://localhost/staging/api/user-by-phone',
                'method' => 'POST',
                'auth_key' => '',
            ],
        ]);

        KnowledgeBase::factory()->for($product)->create([
            'content' => 'Open academic menu\n2. Follow Academic > Academic Insights\n3. System opens /insight/academic.',
        ]);
    }
}
