<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AIProviderService
{
    /**
     * Send an OpenAI-compatible chat-completions request.
     * $task can be "chat" or "vision".
     * Gemini is primary; Groq is fallback.
     */
    public function complete(
        array $payload,
        string $task = 'chat'
    ): Response {
        $task = $task === 'vision' ? 'vision' : 'chat';

        $providers = [
            [
                'name' => 'gemini',
                'api_key' => config('services.gemini.api_key'),
                'base_url' => config('services.gemini.base_url'),
                'model' => config(
                    $task === 'vision'
                        ? 'services.gemini.vision_model'
                        : 'services.gemini.model'
                ),
            ],
            [
                'name' => 'groq',
                'api_key' => config('services.groq.api_key'),
                'base_url' => config('services.groq.base_url'),
                'model' => config(
                    $task === 'vision'
                        ? 'services.groq.vision_model'
                        : 'services.groq.model'
                ),
            ],
        ];

        $lastResponse = null;
        $lastConnectionException = null;
        $configuredProviderCount = 0;

        foreach ($providers as $provider) {
            if (
                empty($provider['api_key']) ||
                empty($provider['base_url']) ||
                empty($provider['model'])
            ) {
                Log::warning('AI provider is not fully configured.', [
                    'provider' => $provider['name'],
                    'task' => $task,
                ]);

                continue;
            }

            $configuredProviderCount++;

            $requestPayload = $payload;
            $requestPayload['model'] = $provider['model'];

            try {
                $response = Http::timeout(
                    $task === 'vision' ? 75 : 45
                )
                    ->connectTimeout(10)
                    ->withToken($provider['api_key'])
                    ->acceptJson()
                    ->post(
                        rtrim($provider['base_url'], '/')
                            . '/chat/completions',
                        $requestPayload
                    );
            } catch (ConnectionException $exception) {
                $lastConnectionException = $exception;

                Log::warning('AI provider connection failed.', [
                    'provider' => $provider['name'],
                    'task' => $task,
                    'model' => $provider['model'],
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($response->successful()) {
                Log::info('AI provider request succeeded.', [
                    'provider' => $provider['name'],
                    'task' => $task,
                    'model' => $provider['model'],
                ]);

                return $response;
            }

            $lastResponse = $response;

            Log::warning(
                'AI provider returned an error; trying fallback if available.',
                [
                    'provider' => $provider['name'],
                    'task' => $task,
                    'model' => $provider['model'],
                    'status' => $response->status(),
                    'response' => mb_substr(
                        $response->body(),
                        0,
                        1200
                    ),
                ]
            );

            // Try the next provider for API errors too.
            // The next provider's own model/configuration must work.
        }

        // If providers returned HTTP errors, return the last response
        // so the controller can handle it.
        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        if ($configuredProviderCount === 0) {
            throw new RuntimeException(
                'No AI provider is fully configured. Check services config and environment variables.'
            );
        }

        throw new RuntimeException(
            'All configured AI providers are unreachable.',
            0,
            $lastConnectionException
        );
    }
}