<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingMessageJob;
use App\Models\InteractionLog;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessIncomingMessageJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_the_unregistered_message_when_user_lookup_fails(): void
    {
        Http::fake([
            'https://product.test/user' => Http::response([], 404),
            'https://www.wasenderapi.com/api/send-message' => Http::response(['ok' => true]),
        ]);

        $product = Product::query()->create([
            'name' => 'Alpha',
            'phone_number' => '255700000001',
            'session_id' => '80866',
            'wasender_api_key' => 'token',
            'webhook_secret' => 'correct-secret',
            'config' => [
                'api_url' => 'https://product.test/user',
                'method' => 'POST',
                'auth_key' => 'product-token',
            ],
            'support_email' => 'support@example.com',
        ]);

        ProcessIncomingMessageJob::dispatchSync(
            productId: $product->id,
            senderPhone: '255700123456',
            messageText: 'I need help',
            wasenderMessageId: '3EB0X123456789',
        );

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://www.wasenderapi.com/api/send-message'
                && $request['to'] === '+255700123456'
                && $request['text'] === 'Your number is not registered. Contact support.';
        });

        $this->assertDatabaseHas('interaction_logs', [
            'product_id' => $product->id,
            'sender_phone' => '255700123456',
            'wasender_message_id' => '3EB0X123456789',
            'was_fallback' => true,
        ]);

        $this->assertSame(1, InteractionLog::query()->count());
    }
}