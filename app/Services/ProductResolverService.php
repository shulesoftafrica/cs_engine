<?php

namespace App\Services;

use App\Models\Product;

class ProductResolverService
{
    public function resolveBySessionId(string $sessionId): ?Product
    {
        return Product::query()
            ->where('session_id', $sessionId)
            ->where('is_active', true)
            ->first();
    }

    public function findById(int $productId): ?Product
    {
        return Product::query()->find($productId);
    }
}