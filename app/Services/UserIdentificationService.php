<?php

namespace App\Services;

use App\Exceptions\ProductApiException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class UserIdentificationService
{
    public function identify(Product $product, string $phoneNumber): ?array
    {
        $normalizedPhone = $this->normalizePhone($phoneNumber);
        $cacheKey = sprintf('cs:user:%d:%s', $product->id, $normalizedPhone);

        $identity = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($product, $normalizedPhone) {
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
                    'phone' => $normalizedPhone,
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

            if (! is_array($user) || $user === []) {
                return null;
            }

            $permissions = Arr::get($payload, 'permissions', []);
            if (empty($permissions)) {
                $permissions = [];
            }
            return [
                'user' => $user,
                'permissions' => array_keys((array) $permissions),
            ];
        });
        if ($identity === null) {
            return null;
        }

        $userData = Arr::get($identity, 'user', []);

        $localUser = $this->findOrCreateLocalUser(
            $userData
        );

        $identity['local_user'] = $localUser;

        return $identity;
    }

    private function findOrCreateLocalUser(array $userData): User
    {
        $phone = $userData['phone'];
        $email = $userData['email'];
        $name = $userData['name'];

        $user = User::query()
            ->where('phone', $phone)
            ->first();

        if (!empty($user)) {
            $updates = [];

            if ($name !== '' && $user->name !== $name) {
                $updates['name'] = $name;
            }

            if ($user->phone !== $phone) {
                $updates['phone'] = $phone;
            }

            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }

            return $user;
        }

        return User::query()->create([
            'name' => $name !== '' ? $name : 'Customer',
            'email' => $email !== '' ? $email : $name . '.shulesoft.africa',
            'phone' => $phone,
            'password' => Str::random(40),
        ]);
    }

    private function normalizePhone(string $phoneNumber): string
    {
        $trimmed = trim($phoneNumber);
        $normalized = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($normalized === '') {
            return $trimmed;
        }

        return '+' . $normalized;
    }
}
