<?php

namespace App\Services;

use App\Exceptions\ProductApiException;
use App\Models\Product;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class UserIdentificationService
{
    public function identify(Product $product, string $phoneNumber): ?array
    {
        $cacheKey = sprintf('cs:user:%d:%s', $product->id, $phoneNumber);

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($product, $phoneNumber) {
            $config = (array) $product->config;
            $apiUrl = (string) Arr::get($config, 'api_url', '');

            if ($apiUrl === '') {
                return null;
            }

            $method = strtolower((string) Arr::get($config, 'method', 'post'));
            $authKey = (string) Arr::get($config, 'auth_key', '');

            $request = Http::acceptJson()->asJson()->timeout(10);

            if ($authKey !== '') {
                $request = $request->withToken($authKey);
            }

            if (! method_exists($request, $method)) {
                $method = 'post';
            }

            try {
                $response = $request->{$method}($apiUrl, [
                    'phone' => $phoneNumber,
                ]);
            } catch (Throwable $throwable) {
                throw new ProductApiException('The product API request failed.', previous: $throwable);
            }

            if ($response->serverError()) {
                throw new ProductApiException('The product API returned a server error.');
            }

            if ($response->failed()) {
                return null;
            }

            $payload = $response->json();
            $user = Arr::get($payload, 'user');

            if (! is_array($user) || $user === [] || Arr::get($user, 'id') === null) {
                return null;
            }

            return [
                'user' => $user,
                'permissions' => array_keys((array) Arr::get($payload, 'permissions', [])),
            ];
        });
    }
}