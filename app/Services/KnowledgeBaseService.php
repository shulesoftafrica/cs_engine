<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    public function resolveAnswerability(int $productId, string $userMessage, array $userPermissions): array
    {
        // Step 1: Check if any KB articles exist for this product at all.
        $anyExists = KnowledgeBase::query()
            ->where('product_id', $productId)
            ->whereNotNull('embedding_vector')
            ->exists();

        if (! $anyExists) {
            return [
                'status' => 'no_match',
                'articles' => collect(),
                'required_permissions' => [],
            ];
        }

        // Step 2: Find the most semantically relevant candidates (no hard similarity
        // floor — limit alone keeps only the top matches).
        $queryVector = Str::of($userMessage)->toEmbeddings();

        $candidates = KnowledgeBase::query()
            ->where('product_id', $productId)
            ->whereNotNull('embedding_vector')
            ->whereVectorSimilarTo('embedding_vector', $userMessage, minSimilarity: 0.1)
            ->limit(1)
            ->get();

        if ($candidates->isEmpty()) {
            return [
                'status' => 'no_match',
                'articles' => collect(),
                'required_permissions' => [],
            ];
        }

        // Step 3: Apply permission filter on the retrieved candidates.
        $allowed = $candidates->filter(function (KnowledgeBase $article) use ($userPermissions): bool {
            return $this->isAllowed($article, $userPermissions);
        })->values();

        if ($allowed->isNotEmpty()) {
            return [
                'status' => 'accessible_match',
                'articles' => $allowed->take(1),
                'required_permissions' => [],
            ];
        }

        $forbidden = $candidates->first();

        return [
            'status' => 'forbidden_match',
            'articles' => collect(),
            'required_permissions' => array_keys((array) ($forbidden?->permissions ?? [])),
        ];
    }



    private function isAllowed(KnowledgeBase $article, array $userPermissions)
    {
        $requiredPermissions = array_keys((array) ($article->permissions ?? []));

        if ($requiredPermissions === []) {
            return true;
        }
        return array_diff($userPermissions, $requiredPermissions) === [];
    }
}
