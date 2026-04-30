<?php

namespace Tests\Feature;

use App\Models\KnowledgeBase;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class KnowledgeBaseCrudTest extends TestCase
{
    public function test_it_creates_a_knowledge_base_and_generates_embedding_vector(): void
    {
        $product = Product::factory()->create();
        $vector = array_fill(0, 1536, 0.01);
        $file = UploadedFile::fake()->createWithContent('article.txt', 'How to view balance in the dashboard');

        Embeddings::fake([
            [$vector],
        ]);

        $response = $this->post('/api/knowledge-bases', [
            'product_id' => $product->id,
            'content' => $file,
            'permissions' => ['view_balance'],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('product_id', $product->id)
            ->assertJsonPath('content', 'How to view balance in the dashboard')
            ->assertJsonPath('permissions.0', 'view_balance');

        $knowledgeBase = KnowledgeBase::query()->firstOrFail();

        $this->assertSame($vector, $knowledgeBase->embedding_vector);
        $this->assertSame(0, $knowledgeBase->chunk_index);
        $this->assertNull($knowledgeBase->document_key);
    }

    public function test_it_processes_long_uploaded_documents_in_chunks_but_stores_one_row(): void
    {
        $product = Product::factory()->create();
        $content = implode(' ', array_fill(0, 600, Str::random(5)));
        $file = UploadedFile::fake()->createWithContent('article.txt', $content);
        $vector = array_fill(0, 1536, 0.01);

        Embeddings::fake([
            [$vector],
        ]);

        $response = $this->post('/api/knowledge-bases', [
            'product_id' => $product->id,
            'content' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertCreated();

        $knowledgeBases = KnowledgeBase::query()->get();

        $this->assertCount(1, $knowledgeBases);
        $this->assertSame(trim(preg_replace('/\s+/', ' ', $content) ?? ''), $knowledgeBases->first()->content);
    }

    public function test_it_validates_required_payload_fields_for_create(): void
    {
        $response = $this->postJson('/api/knowledge-bases', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id', 'content']);
    }

    public function test_it_rejects_unsupported_uploaded_file_types(): void
    {
        $product = Product::factory()->create();
        $file = UploadedFile::fake()->createWithContent('article.csv', 'bad');

        $response = $this->post('/api/knowledge-bases', [
            'product_id' => $product->id,
            'content' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_it_lists_and_filters_knowledge_bases_by_product_id(): void
    {
        $productA = Product::factory()->create();
        $productB = Product::factory()->create();

        KnowledgeBase::query()->create([
            'product_id' => $productA->id,
            'content' => 'A article',
            'permissions' => null,
            'embedding_vector' => array_fill(0, 1536, 0.01),
        ]);

        KnowledgeBase::query()->create([
            'product_id' => $productB->id,
            'content' => 'B article',
            'permissions' => null,
            'embedding_vector' => array_fill(0, 1536, 0.02),
        ]);

        $response = $this->getJson('/api/knowledge-bases?product_id='.$productA->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $productA->id);
    }

    public function test_it_shows_a_single_knowledge_base(): void
    {
        $knowledgeBase = KnowledgeBase::factory()->create([
            'embedding_vector' => array_fill(0, 1536, 0.01),
        ]);

        $response = $this->getJson('/api/knowledge-bases/'.$knowledgeBase->id);

        $response->assertOk()
            ->assertJsonPath('id', $knowledgeBase->id)
            ->assertJsonPath('content', $knowledgeBase->content);
    }

    public function test_it_updates_content_permissions_and_regenerates_embedding_vector(): void
    {
        $knowledgeBase = KnowledgeBase::factory()->create([
            'permissions' => ['old_permission'],
            'chunk_index' => 0,
            'embedding_vector' => array_fill(0, 1536, 0.01),
        ]);

        $newVector = array_fill(0, 1536, 0.03);
        $file = UploadedFile::fake()->createWithContent('updated.txt', 'New support article content');

        Embeddings::fake([
            [$newVector],
        ]);

        $response = $this->post('/api/knowledge-bases/'.$knowledgeBase->id, [
            '_method' => 'PATCH',
            'content' => $file,
            'permissions' => ['view_balance', 'make_payment'],
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('content', 'New support article content')
            ->assertJsonPath('permissions.0', 'view_balance')
            ->assertJsonPath('permissions.1', 'make_payment');

        $knowledgeBase->refresh();

        $this->assertSame($newVector, $knowledgeBase->embedding_vector);
        $this->assertNull($knowledgeBase->document_key);
    }

    public function test_it_updates_a_long_document_and_keeps_one_record(): void
    {
        $knowledgeBase = KnowledgeBase::factory()->create([
            'content' => 'Old content',
            'chunk_index' => 0,
            'embedding_vector' => array_fill(0, 1536, 0.01),
        ]);

        $replacement = implode(' ', array_fill(0, 600, 'updated'));
        $file = UploadedFile::fake()->createWithContent('updated.txt', $replacement);
        $vector = array_fill(0, 1536, 0.03);

        Embeddings::fake([
            [$vector],
        ]);

        $response = $this->post('/api/knowledge-bases/'.$knowledgeBase->id, [
            '_method' => 'PATCH',
            'content' => $file,
        ], ['Accept' => 'application/json']);

        $response->assertOk();

        $this->assertCount(1, KnowledgeBase::query()->get());
        $knowledgeBase->refresh();
        $this->assertSame(trim(preg_replace('/\s+/', ' ', $replacement) ?? ''), $knowledgeBase->content);
        $this->assertSame($vector, $knowledgeBase->embedding_vector);
    }

    public function test_it_deletes_a_knowledge_base(): void
    {
        $knowledgeBase = KnowledgeBase::factory()->create([
            'chunk_index' => 0,
            'embedding_vector' => array_fill(0, 1536, 0.01),
        ]);

        $response = $this->deleteJson('/api/knowledge-bases/'.$knowledgeBase->id);

        $response->assertNoContent();

        $this->assertDatabaseMissing('knowledge_bases', ['id' => $knowledgeBase->id]);
    }
}