<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AIProviderService
{
    /**
     * Send an OpenAI-compatible chat-completions request.
     * $task can be "chat" or "vision".
     *
     * Fallback chain: Gemini primary -> Gemini secondary -> Groq primary -> Groq secondary.
     * If a model fails (rate limit, quota, outage, timeout, wrong model name),
     * the next model in the chain is tried automatically.
     */
    public function complete(
        array $payload,
        string $task = 'chat'
    ): Response {
        $task = $task === 'vision' ? 'vision' : 'chat';

        $steps = $this->buildSteps($task);

        if (empty($steps)) {
            throw new RuntimeException(
                'No AI provider is fully configured. Check services config and environment variables.'
            );
        }

        // Skip models that recently hit a limit. If all are cooling down, try all anyway.
        $active = array_values(array_filter(
            $steps,
            fn (array $step) => !Cache::has($this->cooldownKey($step))
        ));
        if (empty($active)) {
            $active = $steps;
        }

        $lastResponse = null;
        $lastException = null;

        foreach ($active as $step) {
            $requestPayload = $payload;
            $requestPayload['model'] = $step['model'];

            try {
                $response = Http::timeout($task === 'vision' ? 75 : 45)
                    ->connectTimeout(10)
                    ->withToken($step['api_key'])
                    ->acceptJson()
                    ->post(
                        rtrim($step['base_url'], '/') . '/chat/completions',
                        $requestPayload
                    );
            } catch (Throwable $exception) {
                $lastException = $exception;

                Log::warning('AI provider connection failed.', [
                    'provider' => $step['provider'],
                    'task' => $task,
                    'model' => $step['model'],
                    'message' => $exception->getMessage(),
                ]);

                $this->cooldown($step, 30);
                continue;
            }

            if ($response->successful() && is_array($response->json('choices.0.message'))) {
                Log::info('AI provider request succeeded.', [
                    'provider' => $step['provider'],
                    'task' => $task,
                    'model' => $step['model'],
                ]);

                return $response;
            }

            $lastResponse = $response;

            Log::warning('AI model failed; trying next model in chain.', [
                'provider' => $step['provider'],
                'task' => $task,
                'model' => $step['model'],
                'status' => $response->status(),
                'response' => mb_substr($response->body(), 0, 1200),
            ]);

            $this->cooldown($step, $this->cooldownSeconds($response));
        }

        // Every model failed: return the last response so the controller can handle it.
        if ($lastResponse instanceof Response) {
            return $lastResponse;
        }

        throw new RuntimeException(
            'All configured AI models are unreachable.',
            0,
            $lastException
        );
    }

    /**
     * @return array<int, array{provider:string,model:string,base_url:string,api_key:string}>
     */
    private function buildSteps(string $task): array
    {
        $steps = [];

        foreach (['gemini', 'groq'] as $name) {
            $cfg = (array) config("services.{$name}", []);

            if (empty($cfg['api_key']) || empty($cfg['base_url'])) {
                Log::warning('AI provider is not fully configured.', [
                    'provider' => $name,
                    'task' => $task,
                ]);
                continue;
            }

            $models = $cfg['models'][$task] ?? null;

            // Backward compatible with the old single-model config keys.
            if (empty($models)) {
                $models = [$task === 'vision' ? ($cfg['vision_model'] ?? null) : ($cfg['model'] ?? null)];
            }

            $models = array_values(array_unique(array_filter(
                array_map(fn ($m) => trim((string) $m), (array) $models)
            )));

            foreach ($models as $model) {
                $steps[] = [
                    'provider' => $name,
                    'model' => $model,
                    'base_url' => $cfg['base_url'],
                    'api_key' => $cfg['api_key'],
                ];
            }
        }

        return $steps;
    }

    private function cooldownKey(array $step): string
    {
        return 'ai_cooldown:' . $step['provider'] . ':' . $step['model'];
    }

    private function cooldown(array $step, int $seconds): void
    {
        if ($seconds > 0) {
            Cache::put($this->cooldownKey($step), true, $seconds);
        }
    }

    private function cooldownSeconds(Response $response): int
    {
        $status = $response->status();

        if ($status === 429) {
            $retryAfter = (int) $response->header('Retry-After');
            return $retryAfter > 0 ? min($retryAfter, 3600) : 60;
        }
        if ($status === 404) {
            return 3600; // model name not found on this provider
        }
        if (in_array($status, [401, 403], true)) {
            return 600; // bad key / no access / quota blocked
        }
        if ($status === 400) {
            return 0; // likely a payload issue, do not disable the model
        }

        return 30; // 5xx / timeouts
    }
}