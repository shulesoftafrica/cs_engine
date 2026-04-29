<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessageJob;
use App\Services\ProductResolverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WasenderWebhookController extends Controller
{
    public function __construct(
        private readonly ProductResolverService $productResolver,
    ) {
    }

    public function handle(Request $request, string $session_id): JsonResponse
    {
        $product = $this->productResolver->resolveBySessionId($session_id);

        if ($product === null) {
            return response()->json(['status' => 'not-found'], 404);
        }

        if (! $this->hasValidSecret($request, $product->webhook_secret)) {
            Log::warning('Invalid Wasender webhook secret.', [
                'product_id' => $product->id,
                'session_id' => $session_id,
            ]);

            return response()->json(['status' => 'ok']);
        }

        if ($request->input('event') !== 'messages-personal.received') {
            return response()->json(['status' => 'ignored']);
        }

        $messageId = (string) $request->input('data.messages.key.id', '');
        $senderPhone = (string) $request->input('data.messages.key.cleanedSenderPn', '');
        $messageText = (string) ($request->input('data.messages.messageBody')
            ?? $request->input('data.messages.message.conversation', ''));

        if ($messageId === '' || $senderPhone === '' || trim($messageText) === '') {
            Log::warning('Wasender payload missing required fields.', [
                'product_id' => $product->id,
                'session_id' => $session_id,
            ]);

            return response()->json(['status' => 'ok']);
        }

        if (! Cache::add(sprintf('cs:msgid:%s', $messageId), true, now()->addMinutes(5))) {
            return response()->json(['status' => 'ok']);
        }

        ProcessIncomingMessageJob::dispatch(
            productId: $product->id,
            senderPhone: $senderPhone,
            messageText: trim($messageText),
            wasenderMessageId: $messageId,
        );

        return response()->json(['status' => 'ok']);
    }

    private function hasValidSecret(Request $request, string $expectedSecret): bool
    {
        $headerName = 'X-Webhook-Signature';
        $providedSecret = (string) $request->header($headerName, '');

        return $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret);
    }
}
