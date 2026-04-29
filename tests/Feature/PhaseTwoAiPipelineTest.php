<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifierAgent;
use App\Ai\Agents\SupportResponseAgent;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\KnowledgeBase;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class PhaseTwoAiPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_the_phase_one_fallback_for_non_support_intents(): void
    {
        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 1, 'name' => 'John Doe'],
                'permissions' => ['view_balance' => 'Can view balance'],
            ]),
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        IntentClassifierAgent::fake([
            ['intent' => 'GREETING'],
        ]);
        SupportResponseAgent::fake()->preventStrayPrompts();

        $product = $this->makeProduct();

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'Hi there',
            wasenderMessageId: 'msg-001',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['text'] === 'For now I can only help with support questions. Please contact support for anything else.';
        });

        $this->assertDatabaseHas('interaction_logs', [
            'wasender_message_id' => 'msg-001',
            'intent' => 'GREETING',
            'detected_language' => 'en',
            'was_resolved' => false,
            'was_fallback' => true,
        ]);
    }

    public function test_it_generates_a_support_response_for_matching_kb_content(): void
    {
        $vector = array_fill(0, 1536, 0.01);

        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 2, 'name' => 'John Doe'],
                'permissions' => ['view_balance' => 'Can view balance'],
            ]),
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        Embeddings::fake([
            [$vector],
        ]);
        IntentClassifierAgent::fake([
            ['intent' => 'SUPPORT'],
        ]);
        SupportResponseAgent::fake([
            'Habari John, unaweza kuona salio lako kwenye ukurasa wa akaunti.',
        ]);

        $product = $this->makeProduct();

        KnowledgeBase::query()->create([
            'product_id' => $product->id,
            'content' => 'Unaweza kuona salio lako kwenye ukurasa wa akaunti.',
            'permissions' => ['view_balance'],
            'embedding_vector' => $vector,
        ]);

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'Habari, naomba msaada kuhusu salio langu',
            wasenderMessageId: 'msg-002',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['text'] === 'Habari John, unaweza kuona salio lako kwenye ukurasa wa akaunti.';
        });

        $this->assertDatabaseHas('interaction_logs', [
            'wasender_message_id' => 'msg-002',
            'intent' => 'SUPPORT',
            'detected_language' => 'sw',
            'was_resolved' => true,
            'was_fallback' => false,
        ]);
    }

    public function test_it_falls_back_when_the_user_lacks_permission_for_matching_kb_content(): void
    {
        $vector = array_fill(0, 1536, 0.01);

        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 3, 'name' => 'Jane Doe'],
                'permissions' => [],
            ]),
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        Embeddings::fake([
            [$vector],
        ]);
        IntentClassifierAgent::fake([
            ['intent' => 'SUPPORT'],
        ]);
        SupportResponseAgent::fake()->preventStrayPrompts();

        $product = $this->makeProduct();

        KnowledgeBase::query()->create([
            'product_id' => $product->id,
            'content' => 'Restricted balance steps.',
            'permissions' => ['view_balance'],
            'embedding_vector' => $vector,
        ]);

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'How do I view my balance?',
            wasenderMessageId: 'msg-003',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['text'] === "I don't have info on that. Contact support@example.com.";
        });

        $this->assertDatabaseHas('interaction_logs', [
            'wasender_message_id' => 'msg-003',
            'intent' => 'SUPPORT',
            'was_resolved' => false,
            'was_fallback' => true,
        ]);
    }

    private function makeProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Alpha',
            'phone_number' => (string) fake()->unique()->numerify('2557000000##'),
            'session_id' => (string) fake()->unique()->numerify('808##'),
            'wasender_api_key' => 'token',
            'webhook_secret' => 'correct-secret',
            'config' => [
                'api_url' => 'https://product.test/user',
                'method' => 'POST',
                'auth_key' => 'product-token',
            ],
            'support_email' => 'support@example.com',
        ]);
    }
}