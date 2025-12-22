<?php

declare(strict_types=1);

namespace Translator;

use Symfony\Component\Yaml\Yaml;

final class Config
{
    public string $projectDir;
    public string $translatorDir;
    public string $sourceDirectory = 'content/english';
    public string $targetDirectory = 'content';
    public int $translationChunkSize = 6144;
    public int $translationParallelChunks = 4;
    public int $translationParallelFiles = 1;
    public int $openrouterTimeout = 30;
    public string $roleTemplate = 'translator.role.tpl';
    public string $cacheDirectory = '.translation-cache';
    public bool $checkYamlKeys = false;
    /** @var string[] */
    public array $languages = [];

    /** @var array<int, string> */
    public array $models = [];
    /** @var array<string, array<int, string>> */
    public array $modelsByLanguage = [];

    public static function load(string $projectDir): self
    {
        $config = new self();
        $resolvedProjectDir = realpath($projectDir) ?: $projectDir;
        $config->projectDir = rtrim($resolvedProjectDir, '/');
        $config->translatorDir = dirname(__DIR__, 2);

        $configFile = $config->projectDir . '/translator.config.yaml';
        if (is_file($configFile)) {
            $data = Yaml::parseFile($configFile) ?? [];
            $config->sourceDirectory = (string)($data['source_directory'] ?? $config->sourceDirectory);
            $config->targetDirectory = (string)($data['target_directory'] ?? $config->targetDirectory);
            $config->translationChunkSize = (int)($data['translation_chunk_size'] ?? $config->translationChunkSize);
            $config->translationParallelChunks = (int)($data['translation_parallel_chunks'] ?? $config->translationParallelChunks);
            $config->translationParallelFiles = (int)($data['translation_parallel_files'] ?? $config->translationParallelFiles);
            $config->openrouterTimeout = (int)($data['openrouter_timeout'] ?? $config->openrouterTimeout);
            $config->roleTemplate = (string)($data['role_template'] ?? $config->roleTemplate);
            $config->cacheDirectory = (string)($data['cache_directory'] ?? $config->cacheDirectory);
            $config->checkYamlKeys = (bool)($data['check_yaml_keys'] ?? $config->checkYamlKeys);
            if (isset($data['languages']) && is_array($data['languages'])) {
                $config->languages = array_values(array_filter(array_map('strval', $data['languages'])));
            }
        }

        $envChunkSize = $_ENV['TRANSLATION_CHUNK_SIZE'] ?? getenv('TRANSLATION_CHUNK_SIZE') ?: '';
        if (is_string($envChunkSize) && trim($envChunkSize) !== '') {
            $config->translationChunkSize = max(256, (int)trim($envChunkSize));
        }

        $envLanguages = $_ENV['TRANSLATOR_LANGUAGES'] ?? getenv('TRANSLATOR_LANGUAGES') ?: '';
        if (is_string($envLanguages) && trim($envLanguages) !== '') {
            $config->languages = array_values(array_filter(array_map('trim', explode(',', $envLanguages))));
        }

        $modelsFile = $config->projectDir . '/translator.models.yaml';
        if (is_file($modelsFile)) {
            $data = Yaml::parseFile($modelsFile) ?? [];
            $models = $data['models'] ?? null;
            if (is_array($models)) {
                if (array_is_list($models)) {
                    $config->models = self::parseModelList($models);
                } else {
                    foreach ($models as $language => $list) {
                        if (!is_array($list)) {
                            continue;
                        }
                        $parsed = self::parseModelList($list);
                        if ($language === 'default') {
                            $config->models = $parsed;
                        } elseif (!empty($parsed)) {
                            $config->modelsByLanguage[(string)$language] = $parsed;
                        }
                    }
                }
            }
        }

        if (empty($config->models)) {
            $config->models = [
                1 => 'openai:gpt-4o-mini',
                2 => 'claude:claude-3-5-haiku-latest',
                3 => 'openai:o3-mini',
                4 => 'openai:gpt-4o',
                5 => 'claude:claude-3-5-sonnet-latest',
            ];
        }

        $envModel = $_ENV['TRANSLATOR_MODEL'] ?? getenv('TRANSLATOR_MODEL') ?: '';
        if (is_string($envModel) && trim($envModel) !== '') {
            $config->models = [1 => trim($envModel)];
            $config->modelsByLanguage = [];
        }

        $envModels = $_ENV['TRANSLATOR_MODELS'] ?? getenv('TRANSLATOR_MODELS') ?: '';
        if (is_string($envModels) && trim($envModels) !== '') {
            $models = array_values(array_filter(array_map('trim', explode(',', $envModels))));
            $config->models = [];
            foreach ($models as $index => $modelName) {
                $config->models[$index + 1] = $modelName;
            }
            $config->modelsByLanguage = [];
        }

        return $config;
    }

    /**
     * @param array<int, mixed> $entries
     * @return array<int, string>
     */
    private static function parseModelList(array $entries): array
    {
        $ordered = [];
        $withPriority = [];

        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $ordered[] = $entry;
                continue;
            }
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $priority = $entry['priority'] ?? null;
            if (is_numeric($priority)) {
                $withPriority[(int)$priority] = $name;
            } else {
                $ordered[] = $name;
            }
        }

        if (!empty($withPriority)) {
            ksort($withPriority);
            $ordered = array_values($withPriority);
        }

        $result = [];
        foreach ($ordered as $index => $model) {
            $result[$index + 1] = $model;
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function modelsForLanguage(string $language): array
    {
        $list = $this->modelsByLanguage[$language] ?? null;
        if (is_array($list) && !empty($list)) {
            return $list;
        }
        return $this->models;
    }

    public function sourceDir(): string
    {
        return $this->projectDir . '/' . $this->sourceDirectory;
    }

    public function targetDir(): string
    {
        return $this->projectDir . '/' . $this->targetDirectory;
    }

    public function cacheDir(): string
    {
        return $this->projectDir . '/' . $this->cacheDirectory;
    }
}
