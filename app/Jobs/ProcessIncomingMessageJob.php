<?php

namespace App\Jobs;

use App\Ai\Agents\IntentClassifierAgent;
use App\Ai\Agents\SupportResponseAgent;
use App\Exceptions\AiProcessingException;
use App\Exceptions\ProductApiException;
use App\Exceptions\WasenderDeliveryException;
use App\Models\InteractionLog;
use App\Models\User;
use App\Services\KnowledgeBaseService;
use App\Services\ProductResolverService;
use App\Services\UserIdentificationService;
use App\Services\WasenderSenderService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public array $backoff = [2];

    public function __construct(
        public readonly int $productId,
        public readonly string $senderPhone,
        public readonly string $messageText,
        public readonly string $wasenderMessageId,
    ) {}

    public function handle(
        ProductResolverService $productResolver,
        UserIdentificationService $userIdentificationService,
        KnowledgeBaseService $knowledgeBaseService,
        WasenderSenderService $wasenderSenderService,
    ): void {
        $startedAt = microtime(true);
        $product = $productResolver->findById($this->productId);

        if ($product === null) {
            Log::warning('ProcessIncomingMessageJob could not find product.', [
                'product_id' => $this->productId,
                'sender_phone' => $this->senderPhone,
                'message_id' => $this->wasenderMessageId,
            ]);

            return;
        }

        try {
            $identity = $userIdentificationService->identify($product, $this->senderPhone);
            Log::info('User identification completed.', [
                'product_id' => $product->id,
                'message_id' => $this->wasenderMessageId,
                'identified' => $identity !== null,
            ]);
            $detectedLanguage = $this->detectLanguage($this->messageText);

            if ($identity === null) {
                $wasenderSenderService->sendText(
                    product: $product,
                    phoneNumber: $this->senderPhone,
                    text: (string) config('cs_engine.messages.unregistered'),
                );

                $this->logInteraction(
                    productId: $product->id,
                    processingStartedAt: $startedAt,
                    intent: null,
                    detectedLanguage: $detectedLanguage,
                    wasResolved: false,
                    wasFallback: true,
                );

                return;
            }

            $localUser = data_get($identity, 'local_user');

            if (! $localUser instanceof User) {
                throw new ProductApiException('Local user identity could not be resolved.');
            }

            $intent = $this->classifyIntent($this->messageText);
            Log::info('Intent classification completed.', [
                'product_id' => $product->id,
                'message_id' => $this->wasenderMessageId,
                'intent' => $intent,
            ]);

            if ($intent !== 'SUPPORT') {
                $wasenderSenderService->sendText(
                    product: $product,
                    phoneNumber: $this->senderPhone,
                    text: (string) config('cs_engine.messages.intent_fallback'),
                );

                $this->logInteraction(
                    productId: $product->id,
                    processingStartedAt: $startedAt,
                    intent: $intent,
                    detectedLanguage: $detectedLanguage,
                    wasResolved: false,
                    wasFallback: true,
                );

                return;
            }

            $kbResolution = $knowledgeBaseService->resolveAnswerability(
                productId: $product->id,
                userMessage: $this->messageText,
                userPermissions: (array) ($identity['permissions'] ?? []),
            );
            Log::info('Knowledge base search completed.', [
                'product_id' => $product->id,
                'message_id' => $this->wasenderMessageId,
                'resolution_status' => $kbResolution,
            ]);
            $status = (string) ($kbResolution['status'] ?? 'no_match');
            $articles = $kbResolution['articles'] ?? collect();
            $requiredPermissions =  Arr::get($kbResolution['required_permissions'], 'permissions', []);
            

            if ($status === 'forbidden_match') {
                $wasenderSenderService->sendText(
                    product: $product,
                    phoneNumber: $this->senderPhone,
                    text: str_replace(
                        ':required_permissions',
                        implode(', ', $requiredPermissions),
                        (string) config('cs_engine.messages.permission_denied')
                    ),
                );

                $this->logInteraction(
                    productId: $product->id,
                    processingStartedAt: $startedAt,
                    intent: $intent,
                    detectedLanguage: $detectedLanguage,
                    wasResolved: false,
                    wasFallback: true,
                );

                return;
            }

            if ($status !== 'accessible_match' || $articles->isEmpty()) {
                $wasenderSenderService->sendText(
                    product: $product,
                    phoneNumber: $this->senderPhone,
                    text: str_replace(
                        ':support_email',
                        $product->support_email ?: 'support',
                        (string) config('cs_engine.messages.no_kb_match')
                    ),
                );

                $this->logInteraction(
                    productId: $product->id,
                    processingStartedAt: $startedAt,
                    intent: $intent,
                    detectedLanguage: $detectedLanguage,
                    wasResolved: false,
                    wasFallback: true,
                );

                return;
            }
            $content = $articles->pluck('content')->join("\n\n---\n\n");


            $responseText = $this->generateSupportResponse(
                productName: $product->name,
                userName: $this->firstName((string) data_get($identity, 'user.name', 'there')),
                kbContent: $content,
                permissions: (array) ($identity['permissions'] ?? []),
                language: $detectedLanguage,
                message: $this->messageText,
                localUser: $localUser,
            );

            $wasenderSenderService->sendText(
                product: $product,
                phoneNumber: $this->senderPhone,
                text: $responseText,
            );

            $this->logInteraction(
                productId: $product->id,
                processingStartedAt: $startedAt,
                intent: $intent,
                detectedLanguage: $detectedLanguage,
                wasResolved: true,
                wasFallback: false,
            );
        } catch (Throwable $throwable) {
            if ($throwable instanceof WasenderDeliveryException) {
                Log::critical('Wasender delivery failed after retries.', [
                    'product_id' => $product->id,
                    'sender_phone' => $this->senderPhone,
                    'message_id' => $this->wasenderMessageId,
                    'exception' => $throwable,
                ]);

                return;
            }

            Log::error('Processing incoming Wasender message failed.', [
                'product_id' => $product->id,
                'sender_phone' => $this->senderPhone,
                'message_id' => $this->wasenderMessageId,
                'exception' => $throwable,
            ]);

            throw $throwable;
        }
    }

    public function failed(Throwable $throwable): void
    {
        $productResolver = app(ProductResolverService::class);
        $wasenderSenderService = app(WasenderSenderService::class);
        $product = $productResolver->findById($this->productId);

        if ($product === null) {
            return;
        }

        $message = match (true) {
            $throwable instanceof ProductApiException => (string) config('cs_engine.messages.technical_issue'),
            $throwable instanceof AiProcessingException => (string) config('cs_engine.messages.technical_issue_shortly'),
            default => null,
        };

        if ($message === null) {
            return;
        }

        try {
            $wasenderSenderService->sendText($product, $this->senderPhone, $message);

            $this->logInteraction(
                productId: $product->id,
                processingStartedAt: microtime(true),
                intent: null,
                detectedLanguage: $this->detectLanguage($this->messageText),
                wasResolved: false,
                wasFallback: true,
            );
        } catch (WasenderDeliveryException $deliveryException) {
            Log::critical('Wasender delivery failed while sending the terminal fallback message.', [
                'product_id' => $product->id,
                'sender_phone' => $this->senderPhone,
                'message_id' => $this->wasenderMessageId,
                'exception' => $deliveryException,
            ]);
        }
    }

    private function logInteraction(
        int $productId,
        float $processingStartedAt,
        ?string $intent,
        ?string $detectedLanguage,
        bool $wasResolved,
        bool $wasFallback,
    ): void {
        InteractionLog::query()->create([
            'product_id' => $productId,
            'sender_phone' => $this->senderPhone,
            'wasender_message_id' => $this->wasenderMessageId,
            'intent' => $intent,
            'detected_language' => $detectedLanguage,
            'was_resolved' => $wasResolved,
            'was_fallback' => $wasFallback,
            'processing_ms' => (int) round((microtime(true) - $processingStartedAt) * 1000),
        ]);
    }

    private function detectLanguage(string $message): string
    {
        $normalized = Str::lower($message);

        foreach (['habari', 'hujambo', 'tafadhali', 'msaada', 'salio', 'akaunti', 'malipo', 'asante', 'naomba'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return 'sw';
            }
        }

        return 'en';
    }

    private function firstName(string $fullName): string
    {
        $segments = preg_split('/\s+/', trim($fullName));

        return $segments[0] !== '' ? $segments[0] : 'there';
    }

    private function classifyIntent(string $message): string
    {
        try {
            $intentResponse = (new IntentClassifierAgent)->prompt($message);

            return Str::upper((string) ($intentResponse['intent'] ?? 'UNKNOWN'));
        } catch (Throwable $throwable) {
            Log::error('Intent classification failed.', [
                'message' => $message,
                'exception' => $throwable,
            ]);
            throw new AiProcessingException('Intent classification failed.', previous: $throwable);
        }
    }

    private function generateSupportResponse(
        string $productName,
        string $userName,
        string $kbContent,
        array $permissions,
        string $language,
        string $message,
        User $localUser,
    ): string {
        try {
            $response = (new SupportResponseAgent(
                productName: $productName,
                userName: $userName,
                kbContent: $kbContent,
                permissions: $permissions,
                language: $language,
            ))->continueLastConversation($localUser)->prompt($message);

            return $response->text;
        } catch (Throwable $throwable) {
            throw new AiProcessingException('Support response generation failed.', previous: $throwable);
        }
    }
}
