<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifierAgent;
use App\Exceptions\AiProcessingException;
use App\Exceptions\ProductApiException;
use App\Exceptions\WasenderDeliveryException;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\KnowledgeBase;
use App\Models\Product;
use App\Models\User;
use App\Services\UserIdentificationService;
use App\Services\WasenderSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Embeddings;
use Tests\TestCase;

class PhaseThreeHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_identification_throws_for_product_api_server_errors(): void
    {
        Http::fake([
            'https://product.test/user' => Http::response(['error' => 'down'], 500),
        ]);

        $product = $this->makeProduct();

        $this->expectException(ProductApiException::class);

        app(UserIdentificationService::class)->identify($product, '255700123456');
    }

    public function test_failed_product_api_job_sends_the_technical_issue_message(): void
    {
        Http::fake([
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        $product = $this->makeProduct();
        $job = new ProcessIncomingMessageJob($product->id, '255700123456', 'Need help', 'msg-100');

        $job->failed(new ProductApiException('down'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['text'] === 'Brief technical issue. Please try again.';
        });
    }

    public function test_failed_ai_job_sends_the_short_delay_message(): void
    {
        Http::fake([
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        $product = $this->makeProduct();
        $job = new ProcessIncomingMessageJob($product->id, '255700123456', 'Need help', 'msg-101');

        $job->failed(new AiProcessingException('llm timeout'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['text'] === 'Brief technical issue. Please try again shortly.';
        });
    }

    public function test_wasender_sender_throws_when_delivery_fails_after_retries(): void
    {
        Http::fake([
            'https://www.wasenderapi.com/api/send-message' => Http::response(['error' => 'failed'], 500),
        ]);

        $this->expectException(WasenderDeliveryException::class);

        app(WasenderSenderService::class)->sendText($this->makeProduct(), '255700123456', 'Hello');
    }

    public function test_ai_failures_are_wrapped_as_ai_processing_exceptions(): void
    {
        IntentClassifierAgent::fake([
            function (): never {
                throw new \RuntimeException('timeout');
            },
        ]);

        $product = $this->makeProduct();

        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 1, 'name' => 'John Doe'],
                'permissions' => ['view_balance' => 'Can view balance'],
            ]),
        ]);

        $this->expectException(AiProcessingException::class);

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'How do I view my balance?',
            wasenderMessageId: 'msg-102',
        );
    }

    public function test_identified_user_is_created_in_local_users_table_by_phone(): void
    {
        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 10, 'name' => 'John Local'],
                'permissions' => ['view_balance' => 'Can view balance'],
            ]),
        ]);

        $product = $this->makeProduct();

        $identity = app(UserIdentificationService::class)->identify($product, '+255700123456');

        $this->assertNotNull($identity);
        $this->assertDatabaseHas('users', [
            'phone' => '+255700123456',
            'name' => 'John Local',
            'email' => '255700123456@cs-engine.local',
        ]);
    }

    public function test_support_agent_prompt_creates_conversation_records_for_local_user(): void
    {
        $vector = array_fill(0, 1536, 0.01);

        Http::fake([
            'https://product.test/user' => Http::response([
                'user' => ['id' => 11, 'name' => 'Conversation User'],
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

        $product = $this->makeProduct();

        KnowledgeBase::query()->create([
            'product_id' => $product->id,
            'content' => 'Use the balance section to view your account balance.',
            'permissions' => ['view_balance'],
            'embedding_vector' => $vector,
        ]);

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'How can I view my balance?',
            wasenderMessageId: 'msg-103',
        );

        $localUser = User::query()->where('phone', '+255700123456')->firstOrFail();

        $this->assertDatabaseHas('agent_conversations', [
            'user_id' => $localUser->id,
        ]);

        $this->assertDatabaseHas('agent_conversation_messages', [
            'user_id' => $localUser->id,
            'agent' => 'App\\Ai\\Agents\\SupportResponseAgent',
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