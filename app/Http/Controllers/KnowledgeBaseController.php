<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKnowledgeBaseRequest;
use App\Http\Requests\UpdateKnowledgeBaseRequest;
use App\Models\KnowledgeBase;
use App\Services\KnowledgeBaseChunker;
use App\Services\KnowledgeBaseContentExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KnowledgeBaseController extends Controller
{
    public function __construct(
        private readonly KnowledgeBaseContentExtractor $contentExtractor,
        private readonly KnowledgeBaseChunker $chunker,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $knowledgeBases = KnowledgeBase::query()
            ->when($request->filled('product_id'), function ($query) use ($request): void {
                $query->where('product_id', (int) $request->query('product_id'));
            })
            ->latest()
            ->paginate(15);

        return response()->json($knowledgeBases);
    }

    public function store(StoreKnowledgeBaseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $content = $this->buildStoredContent($request->file('content'));

        $knowledgeBase = KnowledgeBase::query()->create([
            'product_id' => $validated['product_id'],
            'content' => $content,
            'document_key' => null,
            'chunk_index' => 0,
            'embedding_vector' => Str::of($content)->toEmbeddings(),
        ]);

        return response()->json($knowledgeBase, 201);
    }

    public function show(KnowledgeBase $knowledgeBase): JsonResponse
    {
        return response()->json($knowledgeBase);
    }

    public function update(UpdateKnowledgeBaseRequest $request, KnowledgeBase $knowledgeBase): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('content', $validated)) {
            $validated['content'] = $this->buildStoredContent($request->file('content'));
            $validated['embedding_vector'] = Str::of($validated['content'])->toEmbeddings();
            $validated['document_key'] = null;
            $validated['chunk_index'] = 0;
        }

        $knowledgeBase->update($validated);

        return response()->json($knowledgeBase->refresh());
    }

    public function destroy(KnowledgeBase $knowledgeBase): JsonResponse
    {
        $knowledgeBase->delete();

        return response()->json(status: 204);
    }

    private function buildStoredContent($file): string
    {
        $content = $this->contentExtractor->extract($file);

        return implode(' ', $this->chunker->chunk($content));
    }
}