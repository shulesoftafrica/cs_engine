<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class KnowledgeBaseService
{
    public function resolveAnswerability(
        int $productId,
        string $userMessage,
        array $userPermissions,
        array $requiredPermissions,
    ): array
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
        $allowed = $this->isAllowed($userPermissions, $requiredPermissions)
            ? $candidates->values()
            : collect();

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
            'required_permissions' => array_values($requiredPermissions),
        ];
    }



    private function isAllowed(array $userPermissions, array $requiredPermissions): bool
    {
        $requiredPermissions = array_values(array_filter($requiredPermissions, fn ($permission) => is_string($permission) && $permission !== ''));

        if ($requiredPermissions === []) {
            return true;
        }
        Log::info('Checking permissions for KB access.', [
            'user_permissions' => $userPermissions,
            'required_permissions' => $requiredPermissions,
        ]);

        return array_diff($requiredPermissions, $userPermissions) === [];
    }
}
