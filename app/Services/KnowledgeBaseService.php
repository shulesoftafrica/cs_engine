<?php

namespace App\Services;

use App\Models\KnowledgeBase;

class KnowledgeBaseService
{
    public function findBestMatch(int $productId, string $userMessage, array $userPermissions)
    {
        $query = KnowledgeBase::query()
            ->where('product_id', $productId)
            ->whereNotNull('embedding_vector')
            ->where(function ($builder) use ($userPermissions): void {
                $builder->whereNull('permissions');

                if ($userPermissions !== []) {
                    $builder->orWhereRaw('permissions::jsonb <@ ?::jsonb', [json_encode(array_values($userPermissions))]);
                }
            })
            ;

        return $query
            ->whereVectorSimilarTo('embedding_vector', $userMessage, minSimilarity: 0.1)
            ->get();
    }
}