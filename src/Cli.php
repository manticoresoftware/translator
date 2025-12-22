<?php

declare(strict_types=1);

namespace Translator;

use Dotenv\Dotenv;

final class Cli
{
    public function run(array $argv): int
    {
        $args = $this->parseArgs($argv);
        if ($args['help']) {
            $this->printHelp();
            return 0;
        }

        $projectDir = $args['project_dir'] ?? $this->inferProjectDir();
        $this->loadEnv($projectDir);
        $translator = new Translator($projectDir);

        if ($args['retranslate_cache_id'] !== null) {
            return $translator->retranslateCachedChunk($args['retranslate_cache_id']);
        }

        if ($args['file_path'] !== null) {
            return $args['check_only']
                ? $translator->checkSingleFile($args['file_path'])
                : $translator->translateSingleFile($args['file_path']);
        }

        return $args['check_only'] ? $translator->checkAll() : $translator->translateAll();
    }

    private function parseArgs(array $argv): array
    {
        $args = [
            'help' => false,
            'retranslate_cache_id' => null,
            'project_dir' => null,
            'file_path' => null,
            'check_only' => false,
        ];

        $positionals = [];
        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];
            if ($arg === '-h' || $arg === '--help') {
                $args['help'] = true;
                continue;
            }
            if ($arg === '-r' || $arg === '--retranslate-cache-id') {
                $args['retranslate_cache_id'] = $argv[$i + 1] ?? null;
                $i++;
                continue;
            }
            if ($arg === '-c' || $arg === '--check-only') {
                $args['check_only'] = true;
                continue;
            }
            $positionals[] = $arg;
        }

        if (count($positionals) === 1) {
            $args['project_dir'] = $positionals[0];
        } elseif (count($positionals) >= 2) {
            $args['project_dir'] = $positionals[0];
            $args['file_path'] = $positionals[1];
        }

        return $args;
    }

    private function inferProjectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private function printHelp(): void
    {
        $help = <<<TXT
Usage: auto-translate [options] [project_directory] [file_path]

Automatically translates markdown files from the source language to all target languages.

Options:
  -r, --retranslate-cache-id CACHE_ID  Retranslate a cached chunk by its cache ID.
  -c, --check-only                      Only check which files/chunks need translation.
  -h, --help                            Show this help message.

Environment variables:
  OPENROUTER_TRANSLATOR_API_KEY OpenRouter API key (required for translation)
  OPENROUTER_BASE_URL           Override OpenRouter base URL (default: https://openrouter.ai/api/v1)
  OPENROUTER_TIMEOUT            OpenRouter request timeout in seconds (default: 30)
  OPENROUTER_RETRIES            Max OpenRouter retry attempts (default: 2)
  OPENROUTER_DEBUG              Enable OpenRouter debug logs (or use DEBUG=1)
  OPENROUTER_DUMP_RESPONSE      Dump OpenRouter JSON responses to temp dir (or use DEBUG=1)
  OPENROUTER_DUMP_CONTENT_BYTES Dump raw response content bytes to temp dir (or use DEBUG=1)
  TRANSLATION_CHUNK_SIZE        Override chunk size in bytes (default: 6144)
  TRANSLATOR_LANGUAGES          Comma-separated language override (default: auto-detect)
  TRANSLATOR_MODEL              Single model override (default: model list)
  TRANSLATOR_MODELS             Comma-separated model list override (default: model list)
  TRANSLATOR_DEBUG              Enable translator debug logs (or use DEBUG=1)
  TRANSLATOR_STOP_ON_MISMATCH   Stop after first chunk structure mismatch (default: false)
  TRANSLATOR_DUMP_MISMATCH      Dump chunk mismatch source/target to temp dir (or use DEBUG=1)
  TRANSLATOR_DUMP_PROMPT        Dump prompt+chunk to temp dir (or use DEBUG=1)
  DEBUG                         Enable both translator + OpenRouter debug logs and dumps
TXT;
        fwrite(STDOUT, $help . PHP_EOL);
    }

    private function loadEnv(string $projectDir): void
    {
        if (class_exists(Dotenv::class)) {
            Dotenv::createImmutable($projectDir)->safeLoad();
        }
    }
}
