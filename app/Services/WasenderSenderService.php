<?php

namespace App\Services;

use App\Exceptions\WasenderDeliveryException;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class WasenderSenderService
{
    public function sendText(Product $product, string $phoneNumber, string $text): void
    {
        try {
            Http::baseUrl((string) config('cs_engine.wasender.base_url'))
                ->retry(3, 200)
                ->withToken($product->wasender_api_key)
                ->asJson()
                ->post('/api/send-message', [
                    'to' => $this->normalizePhoneNumber($phoneNumber),
                    'text' => $text,
                ])
                ->throw();
        } catch (Throwable $throwable) {
            Log::error('Failed to send message via Wasender API.', [
                'product_id' => $product->id,
                'phone_number' => $phoneNumber,
                'text' => $text,
                'exception' => $throwable,
            ]);
            throw new WasenderDeliveryException('The Wasender API request failed.', previous: $throwable);
        }
    }

    private function normalizePhoneNumber(string $phoneNumber): string
    {
        return str_starts_with($phoneNumber, '+') ? $phoneNumber : '+'.$phoneNumber;
    }
}