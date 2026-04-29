<?php

namespace App\Services;

class KnowledgeBaseChunker
{
    public function __construct(
        private readonly int $maxChunkLength = 1500,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function chunk(string $content): array
    {
        $content = trim(preg_replace('/\s+/', ' ', $content) ?? '');

        if ($content === '') {
            return [];
        }

        if (mb_strlen($content) <= $this->maxChunkLength) {
            return [$content];
        }

        $words = preg_split('/\s+/', $content, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $buffer = '';

        foreach ($words as $word) {
            $candidate = $buffer === '' ? $word : $buffer.' '.$word;

            if ($buffer !== '' && mb_strlen($candidate) > $this->maxChunkLength) {
                $chunks[] = $buffer;
                $buffer = $word;

                continue;
            }

            $buffer = $candidate;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }
}