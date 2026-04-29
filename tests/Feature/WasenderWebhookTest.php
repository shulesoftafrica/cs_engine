<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WasenderWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_returns_404_for_unknown_session_id(): void
    {
        $response = $this->postJson('/api/wasender/webhook/unknown', []);

        $response->assertNotFound();
    }

    public function test_it_returns_200_without_dispatching_when_secret_is_invalid(): void
    {
        Queue::fake();
        $messageId = (string) Str::uuid();

        $product = Product::query()->create([
            'name' => 'Alpha',
            'phone_number' => '255700000001',
            'session_id' => '80866',
            'wasender_api_key' => 'token',
            'webhook_secret' => 'correct-secret',
            'config' => ['api_url' => 'https://product.test/user', 'method' => 'POST'],
        ]);

        $response = $this
            ->withHeader('X-Webhook-Signature', 'wrong-secret')
            ->postJson('/api/wasender/webhook/'.$product->session_id, $this->payload($messageId));

        $response->assertOk();
        Queue::assertNothingPushed();
    }

    public function test_it_dispatches_processing_for_a_valid_message_once(): void
    {
        Queue::fake();
        $messageId = (string) Str::uuid();

        $product = Product::query()->create([
            'name' => 'Alpha',
            'phone_number' => '255700000001',
            'session_id' => '80866',
            'wasender_api_key' => 'token',
            'webhook_secret' => 'correct-secret',
            'config' => ['api_url' => 'https://product.test/user', 'method' => 'POST'],
        ]);

        $response = $this
            ->withHeader('X-Webhook-Signature', 'correct-secret')
            ->postJson('/api/wasender/webhook/'.$product->session_id, $this->payload($messageId));

        $response->assertOk();
        Queue::assertPushed(ProcessIncomingMessageJob::class, 1);

        $duplicateResponse = $this
            ->withHeader('X-Webhook-Signature', 'correct-secret')
            ->postJson('/api/wasender/webhook/'.$product->session_id, $this->payload($messageId));

        $duplicateResponse->assertOk();
        Queue::assertPushed(ProcessIncomingMessageJob::class, 1);
    }

    private function payload(string $messageId): array
    {
        return [
            'event' => 'messages-personal.received',
            'data' => [
                'messages' => [
                    'key' => [
                        'id' => $messageId,
                        'cleanedSenderPn' => '255700123456',
                    ],
                    'messageBody' => 'Hello, I have a question',
                ],
            ],
        ];
    }
}