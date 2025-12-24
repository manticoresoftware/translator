<?php

declare(strict_types=1);

namespace Translator;

final class OpenRouterClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;
    private int $maxRetries;
    private float $startTime;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?int $timeoutSeconds = null,
        ?int $maxRetries = null
    )
    {
        $envKey = $_ENV['OPENROUTER_TRANSLATOR_API_KEY'] ?? null;
        $envBase = $_ENV['OPENROUTER_BASE_URL'] ?? null;
        $envTimeout = $_ENV['OPENROUTER_TIMEOUT'] ?? null;
        $envRetries = $_ENV['OPENROUTER_RETRIES'] ?? null;
        $this->apiKey = $apiKey ?? ($envKey ?: (getenv('OPENROUTER_TRANSLATOR_API_KEY') ?: ''));
        $this->baseUrl = rtrim($baseUrl ?? ($envBase ?: (getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1')), '/');
        $timeout = $timeoutSeconds ?? ($envTimeout ?? (getenv('OPENROUTER_TIMEOUT') ?: '30'));
        $this->timeoutSeconds = max(5, (int)$timeout);
        $retries = $maxRetries ?? ($envRetries ?? (getenv('OPENROUTER_RETRIES') ?: '2'));
        $this->maxRetries = max(1, (int)$retries);
        $this->startTime = microtime(true);
    }

    public function translate(
        string $model,
        string $rolePrompt,
        string $content,
        ?string $fileName = null,
        ?string $language = null,
        ?int $chunkNumber = null
    ): string
    {
        $model = $this->normalizeModel($model);
        $url = $this->baseUrl . '/chat/completions';

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $rolePrompt],
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0,
        ];

        $lastError = null;
        $fileSuffix = $fileName !== null && $fileName !== '' ? " file={$fileName}" : '';
        $langSuffix = $language !== null && $language !== '' ? " lang={$language}" : '';
        $chunkSuffix = $chunkNumber !== null ? " chunk={$chunkNumber}" : '';
        for ($i = 1; $i <= $this->maxRetries; $i++) {
            try {
                $start = microtime(true);
                $attempt = "{$i}/{$this->maxRetries}";
                $this->logEvent('STARTED', $model, $fileName, $language, $chunkNumber, $attempt, 'bytes=' . strlen($content));
                if ($this->debugEnabled()) {
                    $this->logEvent('REQUEST_START', $model, $fileName, $language, $chunkNumber, $attempt, 'bytes=' . strlen($content));
                }
                $response = $this->postJson($url, $payload);
                $elapsedMs = (int)round((microtime(true) - $start) * 1000);
                $this->logEvent('OK', $model, $fileName, $language, $chunkNumber, $attempt, "ms={$elapsedMs}");
                if ($this->debugEnabled()) {
                    $this->logEvent('REQUEST_OK', $model, $fileName, $language, $chunkNumber, $attempt, "ms={$elapsedMs}");
                }
                $text = $response['choices'][0]['message']['content'] ?? null;
                if (!is_string($text)) {
                    throw new \RuntimeException('OpenRouter response missing content');
                }
                $this->dumpRawResponse($model, $response, $content, $language, $chunkNumber, $fileName);
                $this->dumpContentBytes($model, $content, $text, $language, $chunkNumber, $fileName);
                return $this->normalizeUtf8($text);
            } catch (\Throwable $e) {
                $lastError = $e;
                $elapsedMs = isset($start) ? (int)round((microtime(true) - $start) * 1000) : 0;
                $attempt = "{$i}/{$this->maxRetries}";
                $this->logEvent('FAILED', $model, $fileName, $language, $chunkNumber, $attempt, "ms={$elapsedMs} msg=" . $e->getMessage());
                if ($this->debugEnabled()) {
                    $this->logEvent('REQUEST_FAILED', $model, $fileName, $language, $chunkNumber, $attempt, "ms={$elapsedMs} msg=" . $e->getMessage());
                }
                if ($this->isTimeoutError($e)) {
                    throw $e;
                }
                $this->sleepWithBackoff($i);
            }
        }

        throw new \RuntimeException('OpenRouter request failed after retries: ' . $lastError?->getMessage(), 0, $lastError);
    }

    private function isTimeoutError(\Throwable $error): bool
    {
        $message = strtolower($error->getMessage());
        return str_contains($message, 'timed out') || str_contains($message, 'timeout');
    }

    private function normalizeModel(string $model): string
    {
        if (str_contains($model, '/')) {
            return $model;
        }
        if (str_starts_with($model, 'openai:')) {
            return 'openai/' . substr($model, strlen('openai:'));
        }
        if (str_starts_with($model, 'claude:')) {
            return 'anthropic/' . substr($model, strlen('claude:'));
        }
        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('OPENROUTER_TRANSLATOR_API_KEY is required');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            throw new \RuntimeException('OpenRouter request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        $decoded = json_decode($raw, true);
        if ($status >= 400) {
            $message = is_array($decoded) && isset($decoded['error']['message']) ? $decoded['error']['message'] : 'HTTP ' . $status;
            throw new \RuntimeException('OpenRouter error: ' . $message, $status);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function sleepWithBackoff(int $attempt): void
    {
        $delayMs = 200 * (2 ** ($attempt - 1));
        usleep($delayMs * 1000);
    }

    private function debugLog(string $message): void
    {
        if ($this->debugEnabled()) {
            fwrite(STDERR, '[' . $this->elapsedStamp() . "] [openrouter] {$message}\n");
        }
    }

    private function logEvent(
        string $status,
        string $model,
        ?string $fileName,
        ?string $language,
        ?int $chunkNumber,
        ?string $attempt,
        string $message = ''
    ): void {
        $file = $fileName !== null && $language !== null && $fileName !== ''
            ? $language . '/' . $fileName
            : '-';
        $chunk = $chunkNumber !== null ? (string)$chunkNumber : '-';
        $attemptValue = $attempt ?? '-';
        $langValue = $language ?? '-';
        $suffix = $message !== '' ? ' ' . $message : '';
        fwrite(
            STDERR,
            '[' . $this->elapsedStamp() . "] [openrouter] status={$status} file={$file} chunk={$chunk} model={$model} attempt={$attemptValue} lang={$langValue}{$suffix}\n"
        );
    }

    private function elapsedStamp(): string
    {
        $elapsed = (int)floor(microtime(true) - $this->startTime);
        $minutes = intdiv($elapsed, 60);
        $seconds = $elapsed % 60;
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function dumpRawResponse(
        string $model,
        array $response,
        string $content,
        ?string $language,
        ?int $chunkNumber,
        ?string $fileName
    ): void
    {
        $enabled = $_ENV['OPENROUTER_DUMP_RESPONSE'] ?? getenv('OPENROUTER_DUMP_RESPONSE') ?: '';
        if ($enabled !== '1' && strtolower((string)$enabled) !== 'true' && !$this->debugEnabled()) {
            return;
        }
        $safeModel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $model) ?? 'model';
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $hash = substr(sha1($content . '|' . ($language ?? '') . '|' . ($chunkNumber ?? '')), 0, 8);
        $langSuffix = $language !== null && $language !== '' ? '-' . $language : '';
        $chunkSuffix = $chunkNumber !== null ? '-chunk' . $chunkNumber : '';
        $path = $tmpDir . '/openrouter-response-' . $safeModel . $langSuffix . $chunkSuffix . '-' . $hash . '.json';
        file_put_contents($path, json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->logEvent('DUMP', $model, $fileName, $language, $chunkNumber, null, "response={$path}");
    }

    private function dumpContentBytes(
        string $model,
        string $content,
        string $text,
        ?string $language,
        ?int $chunkNumber,
        ?string $fileName
    ): void
    {
        $enabled = $_ENV['OPENROUTER_DUMP_CONTENT_BYTES'] ?? getenv('OPENROUTER_DUMP_CONTENT_BYTES') ?: '';
        if ($enabled !== '1' && strtolower((string)$enabled) !== 'true' && !$this->debugEnabled()) {
            return;
        }
        $safeModel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $model) ?? 'model';
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $hash = substr(sha1($content . '|' . ($language ?? '') . '|' . ($chunkNumber ?? '')), 0, 8);
        $langSuffix = $language !== null && $language !== '' ? '-' . $language : '';
        $chunkSuffix = $chunkNumber !== null ? '-chunk' . $chunkNumber : '';
        $path = $tmpDir . '/openrouter-content-' . $safeModel . $langSuffix . $chunkSuffix . '-' . $hash . '.hex';
        file_put_contents($path, bin2hex($text));
        $this->logEvent('DUMP', $model, $fileName, $language, $chunkNumber, null, "content_bytes={$path}");
    }

    private function debugEnabled(): bool
    {
        $enabled = $_ENV['OPENROUTER_DEBUG'] ?? getenv('OPENROUTER_DEBUG') ?: '';
        $global = $_ENV['DEBUG'] ?? getenv('DEBUG') ?: '';
        if ($enabled === '1' || strtolower((string)$enabled) === 'true') {
            return true;
        }
        return $global === '1' || strtolower((string)$global) === 'true';
    }

    private function normalizeUtf8(string $text): string
    {
        $text = str_replace(["\xE2\x80\xA8", "\xE2\x80\xA9"], "\n", $text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return $text;
    }
}
