<?php

namespace Tests\Feature;

use App\Ai\Agents\IntentClassifierAgent;
use App\Exceptions\AiProcessingException;
use App\Exceptions\ProductApiException;
use App\Exceptions\WasenderDeliveryException;
use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Product;
use App\Services\UserIdentificationService;
use App\Services\WasenderSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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