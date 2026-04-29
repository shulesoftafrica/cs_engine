<?php

namespace App\Services;

use App\Exceptions\ProductApiException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class UserIdentificationService
{
    public function identify(Product $product, string $phoneNumber): ?array
    {
        $cacheKey = sprintf('cs:user:%d:%s', $product->id, $phoneNumber);

        $identity = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($product, $phoneNumber) {
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

            if (!is_null($user)) {
                return null;
            }

            return [
                'user' => $user,
                'permissions' => array_keys((array) Arr::get($payload, 'permissions', [])),
            ];
        });

        if ($identity === null) {
            return null;
        }

        $localUser = $this->findOrCreateLocalUser(
            phoneNumber: $phoneNumber,
            name: (string) Arr::get($identity, 'user.name', 'Customer'),
        );

        $identity['local_user'] = $localUser;

        return $identity;
    }

    private function findOrCreateLocalUser(string $phoneNumber, string $name): User
    {
        $user = User::query()->where('phone', $phoneNumber)->first();
        
        if ($user !== null) {
            if ($name !== '' && $user->name !== $name) {
                $user->forceFill(['name' => $name])->save();
            }

            return $user;
        }

        return User::query()->create([
            'name' => $name !== '' ? $name : 'Customer',
            'email' => $phoneNumber.'@shulesoft.africa',
            'phone' => $phoneNumber,
            'password' => Str::random(40),
        ]);
    }

    private function normalizePhone(string $phoneNumber): string
    {
        $normalized = preg_replace('/\D+/', '', $phoneNumber) ?? '';

        return $normalized !== '' ? $normalized : $phoneNumber;
    }
}