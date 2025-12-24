<?php

declare(strict_types=1);

namespace Translator;

final class Translator
{
    private Config $config;
    private Cache $cache;
    private Chunker $chunker;
    private Validator $validator;
    private OpenRouterClient $client;
    private int $mismatchCounter = 0;
    private float $startTime;

    public function __construct(string $projectDir)
    {
        $this->config = Config::load($projectDir);
        $this->cache = new Cache($this->config);
        $this->chunker = new Chunker();
        $this->validator = new Validator();
        $this->client = new OpenRouterClient(timeoutSeconds: $this->config->openrouterTimeout);
        $this->startTime = microtime(true);
    }

    public function translateAll(bool $forceRender = false): int
    {
        $sourceDir = $this->config->sourceDir();
        if (!is_dir($sourceDir)) {
            fwrite(STDERR, "Source directory not found: {$sourceDir}\n");
            return 1;
        }

        $languages = $this->resolveLanguages();
        if (empty($languages)) {
            fwrite(STDERR, "No target languages found.\n");
            return 1;
        }

        $this->cleanupDeletedFiles($languages);

        $files = $this->findMarkdownFiles($sourceDir);
        $relativePaths = [];
        foreach ($files as $file) {
            $relativePaths[] = ltrim(substr($file, strlen($sourceDir)), '/');
        }

        $failed = false;
        $maxWorkers = max(1, $this->config->workers);
        if ($maxWorkers > 1 && $this->parallelAvailable()) {
            $failed = !$this->translateFilesInParallel($relativePaths, $languages, $maxWorkers, $forceRender);
        } else {
            foreach ($relativePaths as $relativePath) {
                if (!$this->translateFile($relativePath, $languages, $forceRender)) {
                    $failed = true;
                }
            }
        }

        $this->copyNonMarkdownAssets($languages);

        return $failed ? 1 : 0;
    }

    public function translateSingleFile(string $filePath, bool $forceRender = false): int
    {
        $sourceDir = $this->config->sourceDir();
        $absPath = $this->resolveFilePath($filePath, $sourceDir);
        if ($absPath === null || !is_file($absPath)) {
            fwrite(STDERR, "File not found: {$filePath}\n");
            return 1;
        }
        if (!str_ends_with($absPath, '.md')) {
            fwrite(STDERR, "File must be a markdown file: {$filePath}\n");
            return 1;
        }

        $relativePath = ltrim(substr($absPath, strlen($sourceDir)), '/');
        $languages = $this->resolveLanguages();
        if (empty($languages)) {
            fwrite(STDERR, "No target languages found.\n");
            return 1;
        }

        return $this->translateFile($relativePath, $languages, $forceRender) ? 0 : 1;
    }

    public function checkAll(): int
    {
        $sourceDir = $this->config->sourceDir();
        if (!is_dir($sourceDir)) {
            fwrite(STDERR, "Source directory not found: {$sourceDir}\n");
            return 1;
        }

        $languages = $this->resolveLanguages();
        if (empty($languages)) {
            fwrite(STDERR, "No target languages found.\n");
            return 1;
        }

        $files = $this->findMarkdownFiles($sourceDir);
        $relativePaths = [];
        foreach ($files as $file) {
            $relativePaths[] = ltrim(substr($file, strlen($sourceDir)), '/');
        }

        $needsWork = false;
        foreach ($relativePaths as $relativePath) {
            if ($this->checkFile($relativePath, $languages)) {
                $needsWork = true;
            }
        }

        return $needsWork ? 1 : 0;
    }

    public function checkSingleFile(string $filePath): int
    {
        $sourceDir = $this->config->sourceDir();
        $absPath = $this->resolveFilePath($filePath, $sourceDir);
        if ($absPath === null || !is_file($absPath)) {
            fwrite(STDERR, "File not found: {$filePath}\n");
            return 1;
        }
        if (!str_ends_with($absPath, '.md')) {
            fwrite(STDERR, "File must be a markdown file: {$filePath}\n");
            return 1;
        }

        $relativePath = ltrim(substr($absPath, strlen($sourceDir)), '/');
        $languages = $this->resolveLanguages();
        if (empty($languages)) {
            fwrite(STDERR, "No target languages found.\n");
            return 1;
        }

        return $this->checkFile($relativePath, $languages) ? 1 : 0;
    }

    public function retranslateCachedChunk(string $cacheId): int
    {
        $entry = $this->findCacheEntry($cacheId);
        if ($entry === null) {
            fwrite(STDERR, "Cache entry not found: {$cacheId}\n");
            return 1;
        }
        [$relativePath, $originalChunk] = $entry;

        $languages = $this->resolveLanguages();
        foreach ($languages as $language) {
            $translation = $this->translateChunk($originalChunk, $language, $relativePath, $cacheId, true, null);
            if ($translation === null) {
                fwrite(STDERR, "Failed to retranslate chunk for {$language}\n");
                return 1;
            }
        }

        $this->cache->clearFileCache($relativePath);
        return $this->translateFile($relativePath, $languages, true) ? 0 : 1;
    }

    /**
     * @param string[] $languages
     */
    private function translateFile(string $relativePath, array $languages, bool $forceRender): bool
    {
        $sourceFile = $this->config->sourceDir() . '/' . $relativePath;
        if (!is_file($sourceFile)) {
            return false;
        }

        $sourceContent = (string)file_get_contents($sourceFile);
        $extracted = $this->chunker->extractCodeBlocks($sourceContent);
        $contentWithPlaceholders = $extracted['content'];
        $blocks = $extracted['blocks'];
        $yamlPlan = $this->buildYamlValuePlan($sourceContent);
        if ($yamlPlan !== null) {
            $chunks = $yamlPlan['text'] === '' ? [] : [$yamlPlan['text']];
        } else {
            $chunks = $this->chunker->splitIntoChunks($contentWithPlaceholders, $this->config->translationChunkSize);
        }

        $maxWorkers = max(1, $this->config->workers);
        if ($maxWorkers > 1 && $this->parallelAvailable() && count($languages) > 1) {
            return $this->translateFileLanguagesInParallel(
                $relativePath,
                $languages,
                $sourceContent,
                $blocks,
                $chunks,
                $yamlPlan,
                $forceRender,
                $maxWorkers
            );
        }

        $failed = false;
        foreach ($languages as $language) {
            if (!$this->translateFileForLanguage($relativePath, $language, $sourceContent, $blocks, $chunks, $yamlPlan, $forceRender, false)) {
                $failed = true;
            }
        }

        return !$failed;
    }

    /**
     * @param string[] $languages
     * @param string[] $chunks
     */
    private function translateFileLanguagesInParallel(
        string $relativePath,
        array $languages,
        string $sourceContent,
        array $blocks,
        array $chunks,
        ?array $yamlPlan,
        bool $forceRender,
        int $maxWorkers
    ): bool {
        $active = [];
        $index = 0;
        $total = count($languages);
        $failed = false;

        while ($index < $total || !empty($active)) {
            while ($index < $total && count($active) < $maxWorkers) {
                $language = $languages[$index];
                $pid = pcntl_fork();
                if ($pid === -1) {
                    if (!$this->translateFileForLanguage($relativePath, $language, $sourceContent, $blocks, $chunks, $yamlPlan, $forceRender, false)) {
                        $failed = true;
                    }
                    $index++;
                    continue;
                }
                if ($pid === 0) {
                    $ok = $this->translateFileForLanguage($relativePath, $language, $sourceContent, $blocks, $chunks, $yamlPlan, $forceRender, false);
                    exit($ok ? 0 : 1);
                }
                $active[$pid] = $language;
                $index++;
            }

            $status = 0;
            $done = pcntl_wait($status);
            if ($done > 0 && isset($active[$done])) {
                $exitCode = pcntl_wexitstatus($status);
                unset($active[$done]);
                if ($exitCode !== 0) {
                    $failed = true;
                }
            }
        }

        return !$failed;
    }

    /**
     * @param string[] $chunks
     * @return string[]|null
     */
    private function translateChunks(string $relativePath, string $language, array $chunks, bool $force): ?array
    {
        $maxWorkers = max(1, $this->config->workers);
        if ($maxWorkers > 1 && $this->parallelAvailable()) {
            return $this->translateChunksInParallel($relativePath, $language, $chunks, $maxWorkers, $force);
        }

        $translatedChunks = [];
        $fileName = $this->fileName($relativePath);
        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            if ($this->debugEnabled()) {
                $this->logStep('STARTED', $relativePath, $language, $chunkNumber, null, null, 'total=' . count($chunks));
            }
            if ($this->debugEnabled()) {
                $this->logStep('DEBUG', $relativePath, $language, $chunkNumber, null, null, 'bytes=' . strlen($chunk) . ' total=' . count($chunks));
            }
            $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber);
            if ($translated === null) {
                $this->logStep('FAILED', $relativePath, $language, $chunkNumber, null, null, 'reason=chunk_failed');
                return null;
            }
            $translatedChunks[] = $translated;
        }

        return $translatedChunks;
    }

    /**
     * @param string[] $chunks
     * @return string[]|null
     */
    private function translateChunksInParallel(
        string $relativePath,
        string $language,
        array $chunks,
        int $maxWorkers,
        bool $force
    ): ?array {
        $translatedChunks = array_fill(0, count($chunks), null);
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $prefix = $tmpDir . '/translator-chunk-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $relativePath) . '-' . $language . '-' . getmypid() . '-';

        $active = [];
        $index = 0;
        $total = count($chunks);
        $failed = false;
        $fileName = $this->fileName($relativePath);

        while ($index < $total || !empty($active)) {
            while ($index < $total && count($active) < $maxWorkers) {
                $chunk = $chunks[$index];
                $chunkNumber = $index + 1;
                if ($this->debugEnabled()) {
                    $this->logStep('STARTED', $relativePath, $language, $chunkNumber, null, null, "total={$total}");
                }
                if ($this->debugEnabled()) {
                    $this->logStep('DEBUG', $relativePath, $language, $chunkNumber, null, null, 'bytes=' . strlen($chunk) . " total={$total}");
                }
                $resultPath = $prefix . $index . '.txt';
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber);
                    if ($translated === null) {
                        $this->logStep('FAILED', $relativePath, $language, $chunkNumber, null, null, 'reason=chunk_failed');
                        $failed = true;
                    } else {
                        $translatedChunks[$index] = $translated;
                    }
                    $index++;
                    continue;
                }
                if ($pid === 0) {
                    $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber);
                    if ($translated === null) {
                        exit(1);
                    }
                    file_put_contents($resultPath, $translated);
                    exit(0);
                }
                $active[$pid] = ['index' => $index, 'path' => $resultPath];
                $index++;
            }

            $status = 0;
            $done = pcntl_wait($status);
            if ($done > 0 && isset($active[$done])) {
                $exitCode = pcntl_wexitstatus($status);
                $info = $active[$done];
                unset($active[$done]);
                if ($exitCode !== 0) {
                    $this->logStep('FAILED', $relativePath, $language, null, null, null, 'reason=chunk_failed');
                    $failed = true;
                    continue;
                }
                $content = is_file($info['path']) ? (string)file_get_contents($info['path']) : null;
                if ($content === null) {
                    $this->logStep('FAILED', $relativePath, $language, null, null, null, 'reason=chunk_failed');
                    $failed = true;
                    continue;
                }
                $translatedChunks[$info['index']] = $content;
                unlink($info['path']);
            }
        }

        if ($failed) {
            return null;
        }

        foreach ($translatedChunks as $translated) {
            if ($translated === null) {
                return null;
            }
        }

        return $translatedChunks;
    }

    /**
     * @param string[] $files
     * @param string[] $languages
     */
    private function translateFilesInParallel(array $files, array $languages, int $maxWorkers, bool $forceRender): bool
    {
        $active = [];
        $index = 0;
        $total = count($files);
        $failed = false;

        while ($index < $total || !empty($active)) {
            while ($index < $total && count($active) < $maxWorkers) {
                $relativePath = $files[$index];
                $pid = pcntl_fork();
                if ($pid === -1) {
                    if (!$this->translateFile($relativePath, $languages, $forceRender)) {
                        $failed = true;
                    }
                    $index++;
                    continue;
                }
                if ($pid === 0) {
                    $ok = $this->translateFile($relativePath, $languages, $forceRender);
                    exit($ok ? 0 : 1);
                }
                $active[$pid] = $relativePath;
                $index++;
            }

            $status = 0;
            $done = pcntl_wait($status);
            if ($done > 0) {
                $exitCode = pcntl_wexitstatus($status);
                unset($active[$done]);
                if ($exitCode !== 0) {
                    $failed = true;
                }
            }
        }

        return !$failed;
    }

    private function parallelAvailable(): bool
    {
        return function_exists('pcntl_fork') && function_exists('pcntl_wait');
    }

    /**
     * @param array<int, string> $blocks
     * @param string[] $chunks
     */
    private function translateFileForLanguage(
        string $relativePath,
        string $language,
        string $sourceContent,
        array $blocks,
        array $chunks,
        ?array $yamlPlan,
        bool $forceRender,
        bool $forceTranslate
    ): bool {
        $targetFile = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (
            !$forceRender
            && !$forceTranslate
            && !$this->needsTranslation($sourceContent, $targetFile, $language, $relativePath, $chunks)
        ) {
            return true;
        }

        if ($this->promptsOnlyEnabled()) {
            $fileName = $this->fileName($relativePath);
            $this->logStep('PROMPT', $relativePath, $language, null, null, null, 'scope=file');
            if ($yamlPlan !== null) {
                if ($yamlPlan['text'] !== '') {
                    $yamlChunks = $this->splitValuesText($yamlPlan['text'], $this->config->translationChunkSize);
                    $this->dumpPromptsForChunks($relativePath, $language, $yamlChunks, true);
                }
            } else {
                $this->dumpPromptsForChunks($relativePath, $language, $chunks, false);
            }
            $this->logStep('PROMPT', $relativePath, $language, null, null, null, 'dir=' . rtrim(sys_get_temp_dir(), '/'));
            return true;
        }

        if ($yamlPlan !== null) {
            if ($yamlPlan['text'] === '') {
                $synced = $sourceContent;
            } else {
                $translatedValues = $this->translateYamlValuesOnlyChunks($relativePath, $language, $yamlPlan['text'], $forceTranslate);
                if ($translatedValues === null) {
                    return false;
                }
                $synced = $this->applyYamlValueTranslations($sourceContent, $yamlPlan, $translatedValues);
            }
        } else {
            $translatedChunks = $this->translateChunks($relativePath, $language, $chunks, $forceTranslate);
            if ($translatedChunks === null) {
                return false;
            }
            $translatedContent = implode("\n\n", $translatedChunks);
            $restored = $this->chunker->restoreCodeBlocks($translatedContent, $blocks);
            $synced = $this->syncToSourceStructure($sourceContent, $restored);
            // Merge translated YAML values while preserving skipped keys
            if (!empty($this->config->yamlKeysToSkip)) {
                $synced = $this->mergeTranslatedYamlValues($sourceContent, $restored, $synced);
            }
        }
        $synced = $this->normalizeMarkdownLinksWithSource($sourceContent, $synced);
        $synced = $this->normalizeMarkdownLinkUrls($synced);
        $synced = $this->ensureUtf8($synced);

        $validation = $this->validator->validateDetailed($sourceContent, $synced);
        $validationFailed = false;
        $untranslatedInfo = null;
        if (!$validation['ok'] && ($validation['reason'] ?? '') === 'link-url') {
            $repaired = $this->normalizeMarkdownLinksWithSource($sourceContent, $synced);
            if ($repaired !== $synced) {
                $repaired = $this->normalizeMarkdownLinkUrls($repaired);
                $repaired = $this->ensureUtf8($repaired);
                $repairCheck = $this->validator->validateDetailed($sourceContent, $repaired);
                if ($repairCheck['ok']) {
                    $synced = $repaired;
                    $validation = $repairCheck;
                    $this->logStep('OK', $relativePath, $language, null, null, null, 'repaired_link_urls');
                } else {
                    $synced = $repaired;
                    $validation = $repairCheck;
                }
            }
        }
        if ($validation['ok']) {
            $untranslatedInfo = $this->collectUntranslatedChunkDetails($sourceContent, $synced);
            if ($untranslatedInfo['chunks'] !== []) {
                if (!$forceTranslate) {
                    $fileName = $this->fileName($relativePath);
                    $detail = $this->formatUntranslatedChunkDetail($untranslatedInfo);
                    $this->logStep('RETRY', $relativePath, $language, null, null, null, "rule=untranslated {$detail}");
                    return $this->translateFileForLanguage(
                        $relativePath,
                        $language,
                        $sourceContent,
                        $blocks,
                        $chunks,
                        $yamlPlan,
                        $forceRender,
                        true
                    );
                }
                $validationFailed = true;
                $fileName = $this->fileName($relativePath);
                $detail = $this->formatUntranslatedChunkDetail($untranslatedInfo);
                $this->logStep('WARNING', $relativePath, $language, null, null, null, "untranslated {$detail} writing_output_anyway");
            }
        } elseif (!$validation['ok']) {
            if (!$forceTranslate) {
                $detail = $this->formatValidationDetail($validation);
                $chunkHint = $this->formatChunkHint($sourceContent, $validation, $yamlPlan);
                $this->logStep(
                    'RETRY',
                    $relativePath,
                    $language,
                    null,
                    null,
                    null,
                    trim($detail) !== '' ? "reason={$detail}{$chunkHint}" : 'reason=validation'
                );
                return $this->translateFileForLanguage(
                    $relativePath,
                    $language,
                    $sourceContent,
                    $blocks,
                    $chunks,
                    $yamlPlan,
                    $forceRender,
                    true
                );
            }
            $validationFailed = true;
            $detail = $this->formatValidationDetail($validation);
            $chunkHint = $this->formatChunkHint($sourceContent, $validation, $yamlPlan);
            $this->logStep(
                'WARNING',
                $relativePath,
                $language,
                null,
                null,
                null,
                "validation_failed{$detail}{$chunkHint} writing_output_anyway"
            );
        } else {
            $fileName = $this->fileName($relativePath);
            $this->logStep('OK', $relativePath, $language, null, null, null, 'validated');
        }

        file_put_contents($targetFile, $synced);
        return !$validationFailed;
    }

    private function normalizeMarkdownLinkUrls(string $content): string
    {
        return preg_replace_callback(
            '/(?<!\\!)\\[[^\\]]+\\]\\(([^)]+)\\)/',
            function (array $matches): string {
                $url = $matches[1];
                $url = trim($url);
                if (
                    (str_starts_with($url, '"') && str_ends_with($url, '"'))
                    || (str_starts_with($url, "'") && str_ends_with($url, "'"))
                ) {
                    $url = substr($url, 1, -1);
                }
                $url = preg_replace('/^https:\\s*\"\\/\\//i', 'https://', $url);
                $url = preg_replace('/^http:\\s*\"\\/\\//i', 'http://', $url);
                return str_replace($matches[1], $url, $matches[0]);
            },
            $content
        ) ?? $content;
    }

    private function normalizeMarkdownLinksWithSource(string $sourceContent, string $targetContent): string
    {
        $sourceLinks = $this->markdownLinkDestinations($sourceContent);
        if ($sourceLinks === []) {
            return $targetContent;
        }
        $targetLinks = $this->markdownLinkDestinations($targetContent);
        if (count($sourceLinks) !== count($targetLinks)) {
            return $targetContent;
        }
        $index = 0;
        return preg_replace_callback(
            '/(?<!\\!)\\[[^\\]]+\\]\\(([^)]+)\\)/',
            function (array $matches) use ($sourceLinks, &$index): string {
                $destination = $sourceLinks[$index] ?? $matches[1];
                $index++;
                return str_replace($matches[1], $destination, $matches[0]);
            },
            $targetContent
        ) ?? $targetContent;
    }

    /**
     * @return string[]
     */
    private function markdownLinkDestinations(string $line): array
    {
        if (!preg_match_all('/(?<!\\!)\\[[^\\]]+\\]\\(([^)]+)\\)/', $line, $matches)) {
            return [];
        }
        return $matches[1] ?? [];
    }

    /**
     * @param string[] $languages
     */
    private function checkFile(string $relativePath, array $languages): bool
    {
        $sourceFile = $this->config->sourceDir() . '/' . $relativePath;
        if (!is_file($sourceFile)) {
            return false;
        }
        $sourceContent = (string)file_get_contents($sourceFile);
        $extracted = $this->chunker->extractCodeBlocks($sourceContent);
        $contentWithPlaceholders = $extracted['content'];
        $yamlPlan = $this->buildYamlValuePlan($sourceContent);
        if ($yamlPlan !== null) {
            if ($yamlPlan['text'] === '') {
                $chunks = [];
            } else {
                $chunks = $this->splitValuesText($yamlPlan['text'], $this->config->translationChunkSize);
            }
        } else {
            $chunks = $this->chunker->splitIntoChunks($contentWithPlaceholders, $this->config->translationChunkSize);
        }

        $needsWork = false;
        $fileName = $this->fileName($relativePath);
        foreach ($languages as $language) {
            $targetFile = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
            $status = $this->checkFileForLanguage($relativePath, $language, $sourceContent, $chunks, $targetFile);
            if ($status['needs_translation']) {
                $needsWork = true;
                $reason = $this->describeCheckReason($status['reason'], $status['validation'] ?? null);
                $msg = "status=CHECK file={$language}/{$fileName} chunk=- model=- attempt=- lang={$language} reason={$reason}";
                if (!empty($status['untranslated_chunks'])) {
                    $msg .= " untranslated_chunks=" . count($status['untranslated_chunks']);
                    $msg .= " chunk_list=" . $this->formatChunkList($status['untranslated_chunks']);
                }
                if (!empty($status['missing_hashes'])) {
                    $msg .= " missing_chunks=" . count($status['missing_hashes']);
                }
                if (!empty($status['mismatched_hashes'])) {
                    $msg .= " mismatched_chunks=" . count($status['mismatched_hashes']);
                }
                fwrite(STDOUT, '[' . $this->elapsedStamp() . "] [translator] {$msg}" . PHP_EOL);
            }
        }

        return $needsWork;
    }

    /**
     * @param int[] $chunks
     */
    private function formatChunkList(array $chunks): string
    {
        $chunks = array_values(array_unique($chunks));
        sort($chunks);
        $display = array_slice($chunks, 0, 8);
        $suffix = count($chunks) > count($display) ? ',...' : '';
        return implode(',', $display) . $suffix;
    }

    /**
     * @param string[] $chunks
     * @return array{needs_translation: bool, reason: string, validation?: array|null, untranslated_chunks?: array<int, int>, missing_hashes: array<int, string>, mismatched_hashes: array<int, string>}
     */
    private function checkFileForLanguage(
        string $relativePath,
        string $language,
        string $sourceContent,
        array $chunks,
        string $targetFile
    ): array {
        if (!is_file($targetFile)) {
            return [
                'needs_translation' => true,
                'reason' => 'missing-target',
                'missing_hashes' => [],
                'mismatched_hashes' => [],
            ];
        }

        $targetContent = (string)file_get_contents($targetFile);
        $sourceLines = $this->splitLines($sourceContent);
        $targetLines = $this->splitLines($targetContent);
        if (count($sourceLines) !== count($targetLines)) {
            return [
                'needs_translation' => true,
                'reason' => 'line-count',
                'missing_hashes' => [],
                'mismatched_hashes' => [],
            ];
        }

        if ($this->emptyLinePositions($sourceLines) !== $this->emptyLinePositions($targetLines)) {
            $lineCount = min(count($sourceLines), count($targetLines));
            $lineNumber = null;
            for ($i = 0; $i < $lineCount; $i++) {
                $sourceEmpty = trim($sourceLines[$i]) === '';
                $targetEmpty = trim($targetLines[$i]) === '';
                if ($sourceEmpty !== $targetEmpty) {
                    $lineNumber = $i + 1;
                    break;
                }
            }
            return [
                'needs_translation' => true,
                'reason' => 'empty-lines',
                'validation' => [
                    'reason' => 'empty-line',
                    'line' => $lineNumber,
                    'source' => $lineNumber === null ? null : $sourceLines[$lineNumber - 1],
                    'target' => $lineNumber === null ? null : $targetLines[$lineNumber - 1],
                ],
                'missing_hashes' => [],
                'mismatched_hashes' => [],
            ];
        }

        $validation = $this->validator->validateDetailed($sourceContent, $targetContent);
        if (!$validation['ok']) {
            return [
                'needs_translation' => true,
                'reason' => 'validation',
                'validation' => $validation,
                'missing_hashes' => [],
                'mismatched_hashes' => [],
            ];
        }

        $untranslatedChunks = $this->findUntranslatedChunks($sourceContent, $targetContent);
        if ($untranslatedChunks !== []) {
            return [
                'needs_translation' => true,
                'reason' => 'untranslated',
                'validation' => null,
                'untranslated_chunks' => $untranslatedChunks,
                'missing_hashes' => [],
                'mismatched_hashes' => [],
            ];
        }

        $missing = [];
        $mismatched = [];
        foreach ($chunks as $chunk) {
            $hash = hash('sha256', $chunk);
            $cached = $this->cache->getCachedTranslation($hash, $language, $relativePath);
            if ($cached === null) {
                $missing[] = $hash;
                continue;
            }
            if (!$this->chunkStructureMatches($chunk, $cached)) {
                $mismatched[] = $hash;
            }
        }

        if (!empty($missing)) {
            return [
                'needs_translation' => true,
                'reason' => 'cache-miss',
                'validation' => null,
                'missing_hashes' => $missing,
                'mismatched_hashes' => $mismatched,
            ];
        }

        if (!empty($mismatched)) {
            return [
                'needs_translation' => true,
                'reason' => 'cache-mismatch',
                'validation' => null,
                'untranslated_chunks' => [],
                'missing_hashes' => $missing,
                'mismatched_hashes' => $mismatched,
            ];
        }

        return [
            'needs_translation' => false,
            'reason' => 'ok',
            'validation' => null,
            'untranslated_chunks' => [],
            'missing_hashes' => [],
            'mismatched_hashes' => [],
        ];
    }

    private function describeCheckReason(string $reason, ?array $validation): string
    {
        $base = match ($reason) {
            'missing-target' => 'missing target file',
            'line-count' => 'line count mismatch',
            'empty-lines' => 'empty-line positions mismatch',
            'validation' => 'file validation failed (comments/lists/code fences/link urls)',
            'untranslated' => 'file validation failed (untranslated chunks)',
            'cache-miss' => 'cache miss (one or more chunks)',
            'cache-mismatch' => 'cache mismatch (chunk structure)',
            default => $reason,
        };
        if (!in_array($reason, ['validation', 'empty-lines'], true) || $validation === null) {
            return $base;
        }
        return $base . $this->formatValidationDetail($validation);
    }

    private function formatValidationDetail(array $validation): string
    {
        $reason = $validation['reason'] ?? 'unknown';
        $line = $validation['line'] ?? null;
        $source = $validation['source'] ?? null;
        $target = $validation['target'] ?? null;
        $parts = [];
        if ($reason !== 'ok') {
            $parts[] = " rule={$reason}";
        }
        if (is_int($line)) {
            $parts[] = " line={$line}";
        }
        $showFullSource = $reason === 'link-url';
        if ($reason === 'link-url' && $source !== null && $target !== null) {
            $parts[] = " src_urls=" . $this->formatLinkUrls($source);
            $parts[] = " tgt_urls=" . $this->formatLinkUrls($target);
        }
        if ($reason === 'untranslated') {
            $jaccard = $validation['jaccard'] ?? null;
            $lcs = $validation['lcs'] ?? null;
            if (is_float($jaccard)) {
                $parts[] = " jaccard=" . number_format($jaccard, 3, '.', '');
            }
            if (is_float($lcs)) {
                $parts[] = " lcs=" . number_format($lcs, 3, '.', '');
            }
        }
        if ($source !== null || $target !== null) {
            $parts[] = " src=" . ($showFullSource ? $source : $this->shortenForLog($source));
            $parts[] = " tgt=" . ($showFullSource ? $target : $this->shortenForLog($target));
        }
        return $parts === [] ? '' : ' (' . trim(implode(' ', $parts)) . ')';
    }

    private function formatChunkHint(string $sourceContent, array $validation, ?array $yamlPlan): string
    {
        if ($yamlPlan !== null) {
            return '';
        }
        $line = $validation['line'] ?? null;
        if (!is_int($line) || $line <= 0) {
            return '';
        }
        $chunk = $this->chunkIndexForLine($sourceContent, $line);
        if ($chunk === null) {
            return '';
        }
        return " chunk_hint={$chunk}";
    }

    private function chunkIndexForLine(string $sourceContent, int $lineNumber): ?int
    {
        $extracted = $this->chunker->extractCodeBlocks($sourceContent);
        $chunks = $this->chunker->splitIntoChunks($extracted['content'], $this->config->translationChunkSize);
        $cursor = 1;
        foreach ($chunks as $index => $chunk) {
            $lines = $this->lineCountSplit($chunk);
            $end = $cursor + $lines - 1;
            if ($lineNumber >= $cursor && $lineNumber <= $end) {
                return $index + 1;
            }
            $cursor = $end + 2;
        }
        return null;
    }

    /**
     * @return int[]
     */
    private function findUntranslatedChunks(string $sourceContent, string $targetContent): array
    {
        return $this->collectUntranslatedChunkDetails($sourceContent, $targetContent)['chunks'];
    }

    private function formatLinkUrls(string $line): string
    {
        $count = preg_match_all('/(?<!\\!)\\[[^\\]]+\\]\\(([^)]+)\\)/', $line, $matches);
        if ($count === false || $count === 0) {
            return '';
        }
        $urls = $matches[1] ?? [];
        return implode(', ', $urls);
    }

    private function shortenForLog(?string $text, int $limit = 120): string
    {
        if ($text === null) {
            return '""';
        }
        $clean = str_replace(["\r", "\n"], ' ', $text);
        if (mb_strlen($clean) <= $limit) {
            return '"' . $clean . '"';
        }
        $short = mb_substr($clean, 0, $limit - 3) . '...';
        return '"' . $short . '"';
    }

    /**
     * @return array{chunks: int[], details: string[]}
     */
    private function collectUntranslatedChunkDetails(string $sourceContent, string $targetContent): array
    {
        $sourcePlan = $this->buildYamlValuePlan($sourceContent);
        $targetPlan = $this->buildYamlValuePlan($targetContent);
        if ($sourcePlan !== null && $targetPlan !== null) {
            $sourceChunks = $sourcePlan['text'] === ''
                ? []
                : $this->splitValuesText($sourcePlan['text'], $this->config->translationChunkSize);
            $targetChunks = $targetPlan['text'] === ''
                ? []
                : $this->splitValuesText($targetPlan['text'], $this->config->translationChunkSize);
        } else {
            $sourceExtracted = $this->chunker->extractCodeBlocks($sourceContent);
            $targetExtracted = $this->chunker->extractCodeBlocks($targetContent);
            $sourceChunks = $this->chunker->splitIntoChunks($sourceExtracted['content'], $this->config->translationChunkSize);
            $targetChunks = $this->chunker->splitIntoChunks($targetExtracted['content'], $this->config->translationChunkSize);
        }
        $count = min(count($sourceChunks), count($targetChunks));
        $untranslated = [];
        $details = [];
        for ($i = 0; $i < $count; $i++) {
            $result = $this->validator->validateDetailed($sourceChunks[$i], $targetChunks[$i]);
            if (($result['reason'] ?? '') !== 'untranslated') {
                continue;
            }
            $chunkNumber = $i + 1;
            $untranslated[] = $chunkNumber;
            $detail = "chunk={$chunkNumber}";
            $jaccard = $result['jaccard'] ?? null;
            $lcs = $result['lcs'] ?? null;
            if (is_float($jaccard)) {
                $detail .= " jaccard=" . number_format($jaccard, 3, '.', '');
            }
            if (is_float($lcs)) {
                $detail .= " lcs=" . number_format($lcs, 3, '.', '');
            }
            $details[] = $detail;
        }
        return [
            'chunks' => $untranslated,
            'details' => $details,
        ];
    }

    /**
     * @param array{chunks: int[], details: string[]} $info
     */
    private function formatUntranslatedChunkDetail(array $info): string
    {
        if ($info['chunks'] === []) {
            return 'chunks=';
        }
        $list = $this->formatChunkList($info['chunks']);
        if ($info['details'] === []) {
            return "chunks={$list}";
        }
        return "chunks={$list} (" . implode(' | ', $info['details']) . ")";
    }
    private function translateChunk(
        string $chunk,
        string $language,
        string $relativePath,
        ?string $cacheIdOverride = null,
        bool $force = false,
        ?int $chunkNumber = null,
        ?string $rolePromptOverride = null,
        bool $valuesOnly = false
    ): ?string {
        $startedAt = microtime(true);
        $hash = $cacheIdOverride ?? hash('sha256', $chunk);
        $fileName = $this->fileName($relativePath);

        if (!$force) {
            $entry = $this->cache->getCachedEntry($hash, $relativePath);
            $cached = $entry['translations'][$language] ?? null;
            $cached = is_string($cached) ? $cached : null;
            if ($cached !== null) {
                $normalized = $this->normalizeCodeBlockPlaceholders($chunk, $cached);
                if ($normalized !== $cached) {
                    $cached = $normalized;
                    $this->cache->saveToCache($hash, $chunk, $language, $cached, false, $relativePath);
                }
                $valuesOnlyEntry = !empty($entry['values_only']);
                $valid = $valuesOnlyEntry
                    ? $this->lineCountSplit($chunk) === $this->lineCountSplit($cached)
                    : $this->chunkValidates($chunk, $cached);
                if ($this->chunkStructureMatches($chunk, $cached) && $valid) {
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    $this->logStep('CACHE_HIT', $relativePath, $language, $chunkNumber, null, null, "hash={$hash} ms={$elapsedMs}");
                    return $cached;
                }
                if (!$valuesOnlyEntry) {
                    $validation = $this->validator->validateDetailed($chunk, $cached);
                    if (!$validation['ok']) {
                        $detail = $this->formatValidationDetail($validation);
                        $this->logStep('CACHE_MISMATCH', $relativePath, $language, $chunkNumber, null, null, "hash={$hash}{$detail}");
                    } else {
                        $this->logStep('CACHE_MISMATCH', $relativePath, $language, $chunkNumber, null, null, "hash={$hash}");
                    }
                } else {
                    $this->logStep('CACHE_MISMATCH', $relativePath, $language, $chunkNumber, null, null, "values_only hash={$hash}");
                }
            } else {
                $this->logStep('CACHE_MISS', $relativePath, $language, $chunkNumber, null, null, "hash={$hash}");
            }
        }

        if ($this->isChunkNonTranslatable($chunk)) {
            $this->cache->saveToCache($hash, $chunk, $language, $chunk, true, $relativePath);
            return $chunk;
        }

        $rolePrompt = $rolePromptOverride ?? $this->renderRolePrompt($language);
        $lastError = null;
        foreach ($this->config->modelsForLanguage($language) as $model) {
            try {
            $this->logStep('MODEL_TRY', $relativePath, $language, $chunkNumber, $model, null);
                $this->logStep('TRANSLATE', $relativePath, $language, $chunkNumber, $model, null, 'bytes=' . strlen($chunk));
                $this->dumpPrompt($relativePath, $language, $model, $rolePrompt, $chunk, $chunkNumber);
                $translated = $this->client->translate($model, $rolePrompt, $chunk, $fileName, $language, $chunkNumber);
                if (!$this->isValidUtf8($translated)) {
                    $this->logStep('WARNING', $relativePath, $language, $chunkNumber, $model, null, 'non_utf8_fallback');
                    $translated = $chunk;
                }
                if (!$valuesOnly) {
                    $translated = $this->normalizeHeadingLines($translated);
                    $translated = $this->ensureTrailingEmptyLines($chunk, $translated);
                    $translated = $this->restoreCommentLines($chunk, $translated);
                    if ($this->lineCountSplit($chunk) !== $this->lineCountSplit($translated)) {
                        $translated = $this->syncToSourceStructure($chunk, $translated);
                    }
                    $translated = $this->restoreListMarkers($chunk, $translated);
                }
                $translated = $this->restoreLinkUrls($chunk, $translated);
                $translated = $this->normalizeCodeBlockPlaceholders($chunk, $translated);
                if ($valuesOnly) {
                    return $translated;
                }
                $chunkValidation = $this->validator->validateDetailed($chunk, $translated);
                if (!$chunkValidation['ok']) {
                    $detail = $this->formatValidationDetail($chunkValidation);
                    $this->logStep('VALIDATION_FAILED', $relativePath, $language, $chunkNumber, $model, null, $detail);
                    if ($this->stopOnFirstMismatch()) {
                        $dumpPaths = $this->dumpMismatch($relativePath, $language, $model, $chunk, $translated);
                        $sourcePath = $this->config->sourceDir() . '/' . $relativePath;
                        $targetPath = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
                        $chunkSuffix = $chunkNumber !== null ? " chunk={$chunkNumber}" : '';
                        $message = "Chunk validation failed (stop): file={$fileName}{$chunkSuffix} src_path={$sourcePath} tgt_path={$targetPath} lang={$language} model={$model}";
                        if ($dumpPaths !== null) {
                            $message .= " dump_src={$dumpPaths['src']} dump_tgt={$dumpPaths['tgt']}";
                        }
                        $message .= $detail;
                        $this->logStep('FAILED', $relativePath, $language, $chunkNumber, $model, null, $message);
                        return null;
                    }
                    continue;
                }
                if (!$this->chunkStructureMatches($chunk, $translated)) {
                    if ($this->stopOnFirstMismatch()) {
                        $dumpPaths = $this->dumpMismatch($relativePath, $language, $model, $chunk, $translated);
                        $sourcePath = $this->config->sourceDir() . '/' . $relativePath;
                        $targetPath = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
                        $chunkSuffix = $chunkNumber !== null ? " chunk={$chunkNumber}" : '';
                        $message = "Chunk structure mismatch (stop): file={$fileName}{$chunkSuffix} src_path={$sourcePath} tgt_path={$targetPath} lang={$language} model={$model}";
                        $message .= " src_lines=" . $this->lineCountSplit($chunk) . " tgt_lines=" . $this->lineCountSplit($translated);
                        $message .= " src_nl=" . $this->lineCountByNewline($chunk) . " tgt_nl=" . $this->lineCountByNewline($translated);
                        if ($dumpPaths !== null) {
                            $message .= " dump_src={$dumpPaths['src']} dump_tgt={$dumpPaths['tgt']}";
                        }
                        $this->logStep('FAILED', $relativePath, $language, $chunkNumber, $model, null, $message);
                        return null;
                    }
                }
                if ($this->chunkStructureMatches($chunk, $translated)) {
                    $this->cache->saveToCache($hash, $chunk, $language, $translated, false, $relativePath, $model);
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    $this->logStep('OK', $relativePath, $language, $chunkNumber, $model, null, "ms={$elapsedMs}");
                    return $translated;
                }
                $dumpPaths = $this->dumpMismatch($relativePath, $language, $model, $chunk, $translated);
                $sourcePath = $this->config->sourceDir() . '/' . $relativePath;
                $targetPath = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
                $chunkSuffix = $chunkNumber !== null ? " chunk={$chunkNumber}" : '';
                $message = "Chunk structure mismatch: file={$fileName}{$chunkSuffix} src_path={$sourcePath} tgt_path={$targetPath} lang={$language} model={$model}";
                $message .= " src_lines=" . $this->lineCountSplit($chunk) . " tgt_lines=" . $this->lineCountSplit($translated);
                $message .= " src_nl=" . $this->lineCountByNewline($chunk) . " tgt_nl=" . $this->lineCountByNewline($translated);
                if ($dumpPaths !== null) {
                    $message .= " dump_src={$dumpPaths['src']} dump_tgt={$dumpPaths['tgt']}";
                }
                $this->logStep('FAILED', $relativePath, $language, $chunkNumber, $model, null, $message);
            } catch (\Throwable $e) {
                $lastError = $e;
                $this->logStep(
                    'MODEL_FAILED',
                    $relativePath,
                    $language,
                    $chunkNumber,
                    $model,
                    null,
                    'error=' . $e->getMessage()
                );
                continue;
            }
        }

        if ($lastError !== null) {
            $this->logStep('FAILED', $relativePath, $language, $chunkNumber, null, null, 'error=' . $lastError->getMessage());
        }
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        $this->logStep('FAILED', $relativePath, $language, $chunkNumber, null, null, "ms={$elapsedMs}");
        return null;
    }

    private function chunkValidates(string $source, string $target): bool
    {
        return $this->validator->validateDetailed($source, $target)['ok'];
    }

    private function chunkStructureMatches(string $source, string $target): bool
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($target);

        $lastNonEmptySource = -1;
        $lastNonEmptyTarget = -1;
        for ($i = count($sourceLines) - 1; $i >= 0; $i--) {
            if (trim($sourceLines[$i]) !== '') {
                $lastNonEmptySource = $i;
                break;
            }
        }
        for ($i = count($targetLines) - 1; $i >= 0; $i--) {
            if (trim($targetLines[$i]) !== '') {
                $lastNonEmptyTarget = $i;
                break;
            }
        }
        if ($lastNonEmptySource === -1 && $lastNonEmptyTarget === -1) {
            return true;
        }

        $maxCompare = max($lastNonEmptySource, $lastNonEmptyTarget);
        $mismatchCount = 0;
        $totalLines = $maxCompare + 1;
        $allowedMismatches = (int)floor($totalLines * 0.3) + 1;

        for ($i = 0; $i <= $maxCompare; $i++) {
            $sourceLine = $i < count($sourceLines) ? $sourceLines[$i] : '';
            $targetLine = $i < count($targetLines) ? $targetLines[$i] : '';
            $sourceEmpty = trim($sourceLine) === '';
            $targetEmpty = trim($targetLine) === '';
            $sourceComment = $this->isHtmlCommentOnly(trim($sourceLine));
            $targetComment = $this->isHtmlCommentOnly(trim($targetLine));
            $sourceList = $this->isListItemLine($sourceLine);
            $targetList = $this->isListItemLine($targetLine);

            if ($sourceEmpty !== $targetEmpty || $sourceComment !== $targetComment || $sourceList !== $targetList) {
                $mismatchCount++;
            }
        }

        return $mismatchCount <= $allowedMismatches;
    }

    private function isChunkNonTranslatable(string $chunk): bool
    {
        if (preg_match('/^CODE_BLOCK_\d+$/', trim($chunk)) === 1) {
            return true;
        }
        $lines = $this->splitLines($chunk);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (!$this->isHtmlCommentOnly($trimmed)) {
                return false;
            }
        }
        return true;
    }

    private function renderRolePrompt(string $language): string
    {
        $rolePath = $this->config->projectDir . '/' . $this->config->roleTemplate;
        if (!is_file($rolePath)) {
            $rolePath = $this->config->translatorDir . '/config/translator.role.template.tpl';
        }
        $template = is_file($rolePath) ? (string)file_get_contents($rolePath) : '';
        return str_replace('$LANGUAGE', $language, $template);
    }

    private function normalizeHeadingLines(string $text): string
    {
        $lines = $this->splitLines($text);
        $lineEnding = $this->determineLineEnding($text);
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^#{1,6}\\s*$/', $line) !== 1) {
                $out[] = $line;
                continue;
            }

            $nextIndex = $i + 1;
            while ($nextIndex < $count && trim($lines[$nextIndex]) === '') {
                $nextIndex++;
            }
            if ($nextIndex < $count && preg_match('/^#{1,6}\\s+/', $lines[$nextIndex]) !== 1) {
                $out[] = rtrim($line) . ' ' . ltrim($lines[$nextIndex]);
                $i = $nextIndex;
                continue;
            }

            $out[] = $line;
        }

        return implode($lineEnding, $out);
    }

    private function normalizeCodeBlockPlaceholders(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $translatedLines = $this->splitLines($translated);
        $count = max(count($sourceLines), count($translatedLines));

        for ($i = 0; $i < $count; $i++) {
            $sourceLine = $i < count($sourceLines) ? $sourceLines[$i] : '';
            $translatedLine = $i < count($translatedLines) ? $translatedLines[$i] : '';
            if (preg_match('/^CODE_BLOCK_\\d+$/', trim($sourceLine)) !== 1) {
                continue;
            }
            if (preg_match('/CODE_BLOCK_\\d+/', $translatedLine) === 1) {
                $translatedLines[$i] = trim($sourceLine);
            }
        }

        return implode($this->determineLineEnding($translated), $translatedLines);
    }

    private function ensureTrailingEmptyLines(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($translated);
        $sourceTrailing = $this->countTrailingEmptyLines($sourceLines);
        $targetTrailing = $this->countTrailingEmptyLines($targetLines);
        if ($targetTrailing >= $sourceTrailing) {
            return $translated;
        }
        $missing = $sourceTrailing - $targetTrailing;
        $lineEnding = $this->determineLineEnding($translated);
        $suffix = str_repeat($lineEnding, $missing);
        return $translated . $suffix;
    }

    private function restoreCommentLines(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($translated);
        if (count($sourceLines) !== count($targetLines)) {
            return $translated;
        }
        $lineEnding = $this->determineLineEnding($translated);
        $out = $targetLines;
        foreach ($sourceLines as $i => $line) {
            if ($this->isHtmlCommentOnly(trim($line))) {
                $out[$i] = $line;
            }
        }
        return implode($lineEnding, $out);
    }

    private function restoreListMarkers(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($translated);
        if (count($sourceLines) !== count($targetLines)) {
            return $translated;
        }
        $lineEnding = $this->determineLineEnding($translated);
        $out = $targetLines;
        foreach ($sourceLines as $i => $sourceLine) {
            $targetLine = $targetLines[$i];
            $sourcePrefix = $this->listPrefix($sourceLine);
            $targetPrefix = $this->listPrefix($targetLine);
            if ($sourcePrefix !== null) {
                $content = $targetPrefix !== null ? substr($targetLine, strlen($targetPrefix)) : $targetLine;
                $out[$i] = $sourcePrefix . ltrim($content);
                continue;
            }
            if ($targetPrefix !== null) {
                $content = substr($targetLine, strlen($targetPrefix));
                $out[$i] = $this->leadingWhitespace($sourceLine) . ltrim($content);
            }
        }
        return implode($lineEnding, $out);
    }

    private function restoreLinkUrls(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($translated);
        if (count($sourceLines) !== count($targetLines)) {
            return $translated;
        }
        $lineEnding = $this->determineLineEnding($translated);
        $out = $targetLines;
        foreach ($sourceLines as $i => $sourceLine) {
            $count = preg_match_all('/(?<!\!)\[[^\]]+\]\(([^)]+)\)/', $sourceLine, $sourceMatches);
            if ($count === false || $count === 0) {
                continue;
            }
            $urls = $sourceMatches[1] ?? [];
            if ($urls === []) {
                continue;
            }
            $idx = 0;
            $out[$i] = (string)preg_replace_callback(
                '/(?<!\!)\[[^\]]+\]\([^)]+\)/',
                function (array $match) use ($urls, &$idx): string {
                    if (preg_match('/^\[([^\]]+)\]\(([^)]+)\)$/', $match[0], $parts) !== 1) {
                        return $match[0];
                    }
                    $text = $parts[1];
                    $url = $urls[$idx] ?? $parts[2];
                    $idx++;
                    return '[' . $text . '](' . $url . ')';
                },
                $out[$i]
            );
        }
        return implode($lineEnding, $out);
    }

    private function listPrefix(string $line): ?string
    {
        if (preg_match('/^([\\t ]*(?:[-*]|\\d+[.)])\\s+)/', $line, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }

    private function leadingWhitespace(string $line): string
    {
        if (preg_match('/^[\\t ]+/', $line, $matches) === 1) {
            return $matches[0];
        }
        return '';
    }

    /**
     * @param string[] $lines
     */
    private function countTrailingEmptyLines(array $lines): int
    {
        $count = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (trim($lines[$i]) !== '') {
                break;
            }
            $count++;
        }
        return $count;
    }

    private function syncToSourceStructure(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $translatedLines = $this->splitLines($translated);
        $yamlRange = $this->yamlFrontMatterRange($sourceLines);
        $lineEnding = $this->determineLineEnding($source);

        $contentLines = [];
        $commentLines = [];
        foreach ($translatedLines as $index => $line) {
            if ($this->isCodeFenceLine($line)) {
                continue;
            }
            if ($this->isHtmlCommentOnly(trim($line))) {
                continue;
            }
            if ($this->isInlineCommentLine($line)) {
                $commentLines[] = $line;
                continue;
            }
            if (trim($line) === '---') {
                continue;
            }
            if (trim($line) === '') {
                continue;
            }
            if ($yamlRange !== null && $index >= $yamlRange[0] && $index <= $yamlRange[1]) {
                continue;
            }
            $contentLines[] = $line;
        }

        $out = [];
        $contentIndex = 0;
        $commentIndex = 0;
        foreach ($sourceLines as $index => $line) {
            if ($this->isCodeFenceLine($line)) {
                $out[] = $line;
                continue;
            }
            if ($this->isHtmlCommentOnly(trim($line))) {
                $out[] = $line;
                continue;
            }
            if ($this->isInlineCommentLine($line)) {
                $out[] = $commentLines[$commentIndex] ?? $line;
                $commentIndex++;
                continue;
            }
            if (trim($line) === '---') {
                $out[] = $line;
                continue;
            }
            if ($yamlRange !== null && $index >= $yamlRange[0] && $index <= $yamlRange[1]) {
                $out[] = $line;
                continue;
            }
            if (trim($line) === '') {
                $out[] = $line;
                continue;
            }
            $out[] = $contentLines[$contentIndex] ?? $line;
            $contentIndex++;
        }

        return implode($lineEnding, $out);
    }

    /**
     * @param string[] $lines
     * @param array{0:int,1:int} $yamlRange
     */
    private function hasBodyContent(array $lines, array $yamlRange): bool
    {
        foreach ($lines as $i => $line) {
            if ($i >= $yamlRange[0] && $i <= $yamlRange[1]) {
                continue;
            }
            if (trim($line) !== '') {
                return true;
            }
        }
        return false;
    }

    private function translateYamlValues(string $source, string $translated): string
    {
        $sourceLines = $this->splitLines($source);
        $translatedLines = $this->splitLines($translated);
        $yamlRange = $this->yamlFrontMatterRange($sourceLines);
        if ($yamlRange === null) {
            return $translated;
        }
        $lineEnding = $this->determineLineEnding($source);
        $out = $sourceLines;

        for ($i = $yamlRange[0]; $i <= $yamlRange[1]; $i++) {
            $src = $sourceLines[$i] ?? '';
            $tr = $translatedLines[$i] ?? $src;
            $trim = trim($src);
            if ($trim === '---') {
                $out[$i] = $src;
                continue;
            }
            if ($trim === '') {
                $out[$i] = $src;
                continue;
            }
            if (preg_match('/^(\s*)([^:#][^:]*):(\s*)(.*)$/', $src, $matches) === 1) {
                $indent = $matches[1];
                $key = rtrim($matches[2]);
                $spacing = $matches[3];
                $value = $matches[4];
                if ($value === '' || $value === '|' || $value === '>') {
                    $out[$i] = $indent . $key . ':' . $spacing . $value;
                    continue;
                }
                if (preg_match('/^(\s*)([^:#][^:]*):(\s*)(.*)$/', $tr, $tMatches) === 1) {
                    $out[$i] = $indent . $key . ':' . $tMatches[3] . $tMatches[4];
                    continue;
                }
                $out[$i] = $indent . $key . ':' . $spacing . $value;
                continue;
            }
            $out[$i] = $tr;
        }

        return implode($lineEnding, $out);
    }

    /**
     * Merge translated YAML values from restored content into synced content,
     * while preserving original values for skipped keys.
     * Only called when file has body content and yamlKeysToSkip is configured.
     */
    private function mergeTranslatedYamlValues(string $source, string $restored, string $synced): string
    {
        $sourceLines = $this->splitLines($source);
        $restoredLines = $this->splitLines($restored);
        $syncedLines = $this->splitLines($synced);
        $yamlRange = $this->yamlFrontMatterRange($sourceLines);
        if ($yamlRange === null) {
            return $synced;
        }
        
        $lineEnding = $this->determineLineEnding($source);
        $out = $syncedLines;
        $inSkippedBlock = false;
        $skippedBlockIndent = -1;
        $skippedBlockStart = -1;

        for ($i = $yamlRange[0]; $i <= $yamlRange[1]; $i++) {
            $src = $sourceLines[$i] ?? '';
            $trim = trim($src);
            
            if ($trim === '---') {
                continue;
            }
            
            if ($trim === '') {
                continue;
            }

            // Check if we're in a multi-line block for a skipped key
            if ($inSkippedBlock) {
                $indent = strlen($src) - strlen(ltrim($src, " \t"));
                // Check if we've reached the end of the block
                if (preg_match('/^(\s*)([^:#][^:]*):/', $src, $matches) === 1) {
                    $nextIndent = strlen($matches[1]);
                    if ($nextIndent <= $skippedBlockIndent) {
                        // End of skipped block - already preserved in synced
                        $inSkippedBlock = false;
                        $skippedBlockIndent = -1;
                        $skippedBlockStart = -1;
                    }
                }
                
                if ($inSkippedBlock) {
                    // Still in skipped block, keep source value
                    continue;
                }
            }

            // Check for key-value pairs
            if (preg_match('/^(\s*)([^:#][^:]*):(\s*)(.*)$/', $src, $matches) === 1) {
                $key = rtrim($matches[2]);
                $value = trim($matches[4]);
                
                // Check if this key should be skipped
                if ($this->shouldSkipYamlKey($key)) {
                    // Check if it's a multi-line block (| or >)
                    if ($value === '|' || $value === '>') {
                        $inSkippedBlock = true;
                        $skippedBlockIndent = strlen($matches[1]);
                        $skippedBlockStart = $i;
                        // Keep source value (already in synced)
                        continue;
                    }
                    
                    // Single-line value - keep source (already in synced)
                    continue;
                }
                
                // Not skipped - extract translated value from restored content
                $restoredYamlRange = $this->yamlFrontMatterRange($restoredLines);
                if ($restoredYamlRange !== null && $i >= $restoredYamlRange[0] && $i <= $restoredYamlRange[1]) {
                    $restoredLine = $restoredLines[$i] ?? '';
                    // Extract the value part from restored line
                    if (preg_match('/^(\s*)([^:#][^:]*):(\s*)(.*)$/', $restoredLine, $rMatches) === 1) {
                        $out[$i] = $matches[1] . $key . ':' . $rMatches[3] . $rMatches[4];
                        continue;
                    }
                }
            }
        }
        
        // Handle case where skipped block extends to end of YAML
        if ($inSkippedBlock && $skippedBlockStart >= 0) {
            // All lines from skippedBlockStart to end of YAML are already preserved in synced
            // No action needed
        }

        return implode($lineEnding, $out);
    }

    /**
     * @return array{text: string, slots: array<int, array{line: int, prefix: string, suffix: string, original: string}>}|null
     */
    private function buildYamlValuePlan(string $sourceContent): ?array
    {
        $sourceLines = $this->splitLines($sourceContent);
        $yamlRange = $this->yamlFrontMatterRange($sourceLines);
        if ($yamlRange === null || $this->hasBodyContent($sourceLines, $yamlRange)) {
            return null;
        }

        $values = [];
        $slots = [];
        $inBlock = false;
        $blockIndent = -1;
        $blockSkip = false;

        for ($i = $yamlRange[0]; $i <= $yamlRange[1]; $i++) {
            $line = $sourceLines[$i] ?? '';
            $trim = trim($line);
            if ($trim === '---') {
                $inBlock = false;
                continue;
            }
            $indent = strlen($line) - strlen(ltrim($line, " \t"));
            if ($inBlock) {
                if ($trim === '') {
                    continue;
                }
                if ($blockIndent < 0) {
                    $blockIndent = $indent;
                }
                if ($indent >= $blockIndent) {
                    if ($blockSkip || $this->isNumericValue($trim)) {
                        continue;
                    }
                    $prefix = substr($line, 0, $indent);
                    $valueText = ltrim($line);
                    $bulletPrefix = '';
                    if (str_starts_with($valueText, '- ')) {
                        $bulletPrefix = '- ';
                        $valueText = substr($valueText, 2);
                    }
                    [$valueText, $quotePrefix, $quoteSuffix] = $this->splitQuotedValue($valueText);
                    if ($this->isEmptyStringValue($valueText)) {
                        continue;
                    }
                    if ($this->isUrlValue($valueText)) {
                        continue;
                    }
                    if ($this->isHtmlTagValue($valueText)) {
                        continue;
                    }
                    $prefix .= $bulletPrefix . $quotePrefix;
                    $suffix = $quoteSuffix;
                    $values[] = $valueText;
                    $slots[] = ['line' => $i, 'prefix' => $prefix, 'suffix' => $suffix, 'original' => $valueText];
                    continue;
                }
                $inBlock = false;
                $blockSkip = false;
                $blockIndent = -1;
            }
            if ($trim === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (preg_match('/^(\\s*)-\\s+([A-Za-z0-9_.-]+):\\s*(.*)$/', $line, $matches) === 1) {
                $indentPrefix = $matches[1];
                $key = rtrim($matches[2]);
                if ($this->shouldSkipYamlKey($key)) {
                    $value = trim($matches[3]);
                    if ($value === '|' || $value === '>') {
                        $inBlock = true;
                        $blockIndent = -1;
                        $blockSkip = true;
                    }
                    continue;
                }
                $value = $matches[3];
                if ($value === '|' || $value === '>') {
                    $inBlock = true;
                    $blockIndent = -1;
                    $blockSkip = false;
                    continue;
                }
                $valueTrim = $value;
                $suffix = '';
                if (preg_match('/^(.*?)(\\s+#.*)$/', $valueTrim, $commentMatches) === 1) {
                    $valueTrim = $commentMatches[1];
                    $suffix = $commentMatches[2];
                }
                [$valueTrim, $quotePrefix, $quoteSuffix] = $this->splitQuotedValue($valueTrim);
                if ($this->isEmptyStringValue($valueTrim) || $this->isNumericValue(trim($valueTrim))) {
                    continue;
                }
                if ($this->isUrlValue($valueTrim)) {
                    continue;
                }
                if ($this->isHtmlTagValue($valueTrim)) {
                    continue;
                }
                $values[] = $valueTrim;
                $slots[] = [
                    'line' => $i,
                    'prefix' => $indentPrefix . '- ' . $key . ': ' . $quotePrefix,
                    'suffix' => $quoteSuffix . $suffix,
                    'original' => $valueTrim,
                ];
                continue;
            }
            if (preg_match('/^(\s*)-\\s+(.*)$/', $line, $matches) === 1) {
                $indentPrefix = $matches[1];
                $valueTrim = $matches[2];
                $suffix = '';
                if (preg_match('/^(.*?)(\\s+#.*)$/', $valueTrim, $commentMatches) === 1) {
                    $valueTrim = $commentMatches[1];
                    $suffix = $commentMatches[2];
                }
                [$valueTrim, $quotePrefix, $quoteSuffix] = $this->splitQuotedValue($valueTrim);
                if ($this->isEmptyStringValue($valueTrim) || $this->isNumericValue(trim($valueTrim))) {
                    continue;
                }
                if ($this->isUrlValue($valueTrim)) {
                    continue;
                }
                if ($this->isHtmlTagValue($valueTrim)) {
                    continue;
                }
                $values[] = $valueTrim;
                $slots[] = [
                    'line' => $i,
                    'prefix' => $indentPrefix . '- ' . $quotePrefix,
                    'suffix' => $quoteSuffix . $suffix,
                    'original' => $valueTrim,
                ];
                continue;
            }
            if (preg_match('/^(\s*)([^:#][^:]*):(\s*)(.*)$/', $line, $matches) === 1) {
                $indentPrefix = $matches[1];
                $key = rtrim($matches[2]);
                $spacing = $matches[3];
                $value = $matches[4];
                if ($this->shouldSkipYamlKey($key)) {
                    if ($value === '|' || $value === '>') {
                        $inBlock = true;
                        $blockIndent = -1;
                        $blockSkip = true;
                    }
                    continue;
                }
                if ($value === '|' || $value === '>') {
                    $inBlock = true;
                    $blockIndent = -1;
                    $blockSkip = false;
                    continue;
                }
                if ($value === '') {
                    continue;
                }
                $valueTrim = $value;
                $suffix = '';
                if (preg_match('/^(.*?)(\\s+#.*)$/', $valueTrim, $commentMatches) === 1) {
                    $valueTrim = $commentMatches[1];
                    $suffix = $commentMatches[2];
                }
                [$valueTrim, $quotePrefix, $quoteSuffix] = $this->splitQuotedValue($valueTrim);
                if ($this->isEmptyStringValue($valueTrim) || $this->isNumericValue(trim($valueTrim))) {
                    continue;
                }
                if ($this->isUrlValue($valueTrim)) {
                    continue;
                }
                if ($this->isHtmlTagValue($valueTrim)) {
                    continue;
                }
                $values[] = $valueTrim;
                $slots[] = [
                    'line' => $i,
                    'prefix' => $indentPrefix . $key . ':' . $spacing . $quotePrefix,
                    'suffix' => $quoteSuffix . $suffix,
                    'original' => $valueTrim,
                ];
            }
        }

        if ($values === []) {
            return ['text' => '', 'slots' => []];
        }

        return ['text' => implode("\n", $values), 'slots' => $slots];
    }

    private function applyYamlValueTranslations(string $sourceContent, array $plan, string $translatedValues): string
    {
        $sourceLines = $this->splitLines($sourceContent);
        $translatedLines = $this->splitLines($translatedValues);
        $lineEnding = $this->determineLineEnding($sourceContent);
        $slots = $plan['slots'] ?? [];

        foreach ($slots as $index => $slot) {
            $lineIndex = $slot['line'];
            $translated = $translatedLines[$index] ?? $slot['original'];
            $sourceLines[$lineIndex] = $slot['prefix'] . $translated . $slot['suffix'];
        }

        return implode($lineEnding, $sourceLines);
    }

    private function isNumericValue(string $value): bool
    {
        return preg_match('/^-?\\d+(?:\\.\\d+)?$/', $value) === 1;
    }

    private function isEmptyStringValue(string $value): bool
    {
        $trim = trim($value);
        if ($trim === '') {
            return true;
        }
        $trim = trim($trim, "\"'");
        return $trim === '';
    }

    private function isUrlValue(string $value): bool
    {
        $trim = trim($value);
        $trim = trim($trim, "\"'");
        return str_starts_with($trim, 'http://') || str_starts_with($trim, 'https://');
    }

    private function splitQuotedValue(string $value): array
    {
        $trimmed = trim($value);
        $length = strlen($trimmed);
        if ($length < 2) {
            return [$value, '', ''];
        }
        $first = $trimmed[0];
        $last = $trimmed[$length - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return [substr($trimmed, 1, -1), $first, $last];
        }
        return [$value, '', ''];
    }

    private function isHtmlTagValue(string $value): bool
    {
        $trim = trim($value);
        $trim = trim($trim, "\"'");
        if ($trim === '') {
            return false;
        }
        return str_starts_with($trim, '<') && str_ends_with($trim, '>');
    }

    private function shouldSkipYamlKey(string $key): bool
    {
        $keyLower = strtolower($key);
        foreach ($this->config->yamlKeysToSkip as $skipKey) {
            if (strtolower($skipKey) === $keyLower) {
                return true;
            }
        }
        return false;
    }

    private function translateYamlValuesOnlyChunks(
        string $relativePath,
        string $language,
        string $valuesText,
        bool $force
    ): ?string {
        $prompt = $this->renderRolePrompt($language);
        $chunks = $this->splitValuesText($valuesText, $this->config->translationChunkSize);
        $maxWorkers = max(1, $this->config->workers);
        if ($maxWorkers > 1 && $this->parallelAvailable()) {
            $translatedChunks = $this->translateYamlValuesChunksInParallel($relativePath, $language, $chunks, $maxWorkers, $force, $prompt);
        } else {
            $translatedChunks = $this->translateYamlValuesChunks($relativePath, $language, $chunks, $force, $prompt);
        }
        if ($translatedChunks === null) {
            return null;
        }
        $normalized = [];
        foreach ($translatedChunks as $index => $translated) {
            $chunk = $chunks[$index];
            $translated = $this->normalizeValueLines($chunk, $translated);
            $translated = $this->restoreMissingValueLines($chunk, $translated);
            $hash = hash('sha256', $chunk);
            $this->cache->saveToCache($hash, $chunk, $language, $translated, false, $relativePath, null, true);
            $normalized[] = $translated;
        }
        return implode("\n", $normalized);
    }

    /**
     * @param string[] $chunks
     * @return string[]|null
     */
    private function translateYamlValuesChunks(
        string $relativePath,
        string $language,
        array $chunks,
        bool $force,
        string $prompt
    ): ?array {
        $translatedChunks = [];
        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber, $prompt, true);
            if ($translated === null) {
                return null;
            }
            $translatedChunks[] = $translated;
        }
        return $translatedChunks;
    }

    /**
     * @param string[] $chunks
     * @return string[]|null
     */
    private function translateYamlValuesChunksInParallel(
        string $relativePath,
        string $language,
        array $chunks,
        int $maxWorkers,
        bool $force,
        string $prompt
    ): ?array {
        $translatedChunks = array_fill(0, count($chunks), null);
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $prefix = $tmpDir . '/translator-yaml-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $relativePath) . '-' . $language . '-' . getmypid() . '-';

        $active = [];
        $index = 0;
        $total = count($chunks);
        $failed = false;
        $fileName = $this->fileName($relativePath);

        while ($index < $total || !empty($active)) {
            while ($index < $total && count($active) < $maxWorkers) {
                $chunk = $chunks[$index];
                $chunkNumber = $index + 1;
                if ($this->debugEnabled()) {
                    $this->logStep('STARTED', $relativePath, $language, $chunkNumber, null, null, "total={$total}");
                }
                if ($this->debugEnabled()) {
                    $this->logStep('DEBUG', $relativePath, $language, $chunkNumber, null, null, 'bytes=' . strlen($chunk) . " total={$total}");
                }
                $resultPath = $prefix . $index . '.txt';
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber, $prompt, true);
                    if ($translated === null) {
                        $this->logStep('FAILED', $relativePath, $language, $chunkNumber, null, null, 'reason=chunk_failed');
                        $failed = true;
                    } else {
                        $translatedChunks[$index] = $translated;
                    }
                    $index++;
                    continue;
                }
                if ($pid === 0) {
                    $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber, $prompt, true);
                    if ($translated === null) {
                        exit(1);
                    }
                    file_put_contents($resultPath, $translated);
                    exit(0);
                }
                $active[$pid] = ['index' => $index, 'path' => $resultPath];
                $index++;
            }

            $status = 0;
            $done = pcntl_wait($status);
            if ($done > 0 && isset($active[$done])) {
                $exitCode = pcntl_wexitstatus($status);
                $info = $active[$done];
                unset($active[$done]);
                if ($exitCode !== 0) {
                    $this->logStep('FAILED', $relativePath, $language, null, null, null, 'reason=chunk_failed');
                    $failed = true;
                    continue;
                }
                $content = is_file($info['path']) ? (string)file_get_contents($info['path']) : null;
                if ($content === null) {
                    $this->logStep('FAILED', $relativePath, $language, null, null, null, 'reason=chunk_failed');
                    $failed = true;
                    continue;
                }
                $translatedChunks[$info['index']] = $content;
                unlink($info['path']);
            }
        }

        if ($failed) {
            return null;
        }

        foreach ($translatedChunks as $translated) {
            if ($translated === null) {
                return null;
            }
        }

        return $translatedChunks;
    }

    private function normalizeValueLines(string $sourceValues, string $translatedValues): string
    {
        $sourceLines = $this->splitLines($sourceValues);
        $translatedLines = $this->splitLines($translatedValues);
        $count = count($sourceLines);
        if (count($translatedLines) === $count) {
            return $translatedValues;
        }
        if (count($translatedLines) > $count) {
            $trimmed = array_slice($translatedLines, 0, $count);
            return implode("\n", $trimmed);
        }
        while (count($translatedLines) < $count) {
            $translatedLines[] = '';
        }
        return implode("\n", $translatedLines);
    }

    private function restoreMissingValueLines(string $sourceValues, string $translatedValues): string
    {
        $sourceLines = $this->splitLines($sourceValues);
        $translatedLines = $this->splitLines($translatedValues);
        $count = count($sourceLines);
        if (count($translatedLines) !== $count) {
            return $translatedValues;
        }
        $changed = false;
        for ($i = 0; $i < $count; $i++) {
            if (trim($sourceLines[$i]) !== '' && trim($translatedLines[$i]) === '') {
                $translatedLines[$i] = $sourceLines[$i];
                $changed = true;
            }
        }
        if (!$changed) {
            return $translatedValues;
        }
        return implode("\n", $translatedLines);
    }

    /**
     * @return string[]
     */
    private function splitValuesText(string $valuesText, int $maxBytes): array
    {
        $lines = $this->splitLines($valuesText);
        $chunks = [];
        $current = '';
        $currentSize = 0;

        foreach ($lines as $line) {
            $lineSize = strlen($line);
            $separator = $current === '' ? '' : "\n";
            $separatorSize = $current === '' ? 0 : 1;
            if ($current !== '' && $currentSize + $separatorSize + $lineSize > $maxBytes) {
                $chunks[] = $current;
                $current = $line;
                $currentSize = $lineSize;
                continue;
            }
            $current .= $separator . $line;
            $currentSize += $separatorSize + $lineSize;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @return string[]
     */
    private function resolveLanguages(): array
    {
        if (!empty($this->config->languages)) {
            return $this->config->languages;
        }
        $languages = [];
        $targetDir = $this->config->targetDir();
        $sourceDir = $this->config->sourceDir();
        foreach (glob($targetDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if ($dir === $sourceDir) {
                continue;
            }
            $name = basename($dir);
            if ($name !== 'english') {
                $languages[] = $name;
            }
        }
        return $languages;
    }

    /**
     * @return string[]
     */
    private function findMarkdownFiles(string $sourceDir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.md')) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    private function resolveFilePath(string $filePath, string $sourceDir): ?string
    {
        if (str_starts_with($filePath, '/')) {
            return $filePath;
        }
        $projectPath = $this->config->projectDir . '/' . $filePath;
        if (is_file($projectPath)) {
            return realpath($projectPath) ?: $projectPath;
        }
        $sourcePath = $sourceDir . '/' . $filePath;
        if (is_file($sourcePath)) {
            return realpath($sourcePath) ?: $sourcePath;
        }
        return null;
    }

    private function isCodeFenceLine(string $line): bool
    {
        return str_contains($line, '```');
    }

    private function isHtmlCommentOnly(string $trimmedLine): bool
    {
        if ($trimmedLine === '<!--') {
            return true;
        }
        return (bool)preg_match('/^<!--.*-->$/', $trimmedLine);
    }

    private function isListItemLine(string $line): bool
    {
        return (bool)preg_match('/^[\t ]*(?:[-*]\s+|\d+[.)]\s+)/', $line);
    }

    private function isInlineCommentLine(string $line): bool
    {
        return str_contains($line, '<!--') && !$this->isHtmlCommentOnly(trim($line));
    }

    private function isValidUtf8(string $text): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($text, 'UTF-8');
        }
        return (bool)preg_match('//u', $text);
    }

    private function ensureUtf8(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                return $converted;
            }
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        return $text;
    }


    private function debugLog(string $message): void
    {
        if ($this->debugEnabled()) {
            fwrite(STDERR, '[' . $this->elapsedStamp() . "] [translator] {$message}\n");
        }
    }

    private function infoLog(string $message): void
    {
        fwrite(STDERR, '[' . $this->elapsedStamp() . "] [translator] {$message}\n");
    }

    private function logStep(
        string $status,
        ?string $relativePath,
        ?string $language,
        ?int $chunkNumber,
        ?string $model,
        ?string $attempt,
        string $message = ''
    ): void {
        $file = $relativePath !== null && $language !== null
            ? $language . '/' . $this->fileName($relativePath)
            : '-';
        $chunk = $chunkNumber !== null ? (string)$chunkNumber : '-';
        $modelValue = $model ?? '-';
        $attemptValue = $attempt ?? '-';
        $langValue = $language ?? '-';
        $suffix = $message !== '' ? ' ' . $message : '';
        fwrite(
            STDERR,
            '[' . $this->elapsedStamp() . "] [translator] status={$status} file={$file} chunk={$chunk} model={$modelValue} attempt={$attemptValue} lang={$langValue}{$suffix}\n"
        );
    }

    private function stopOnFirstMismatch(): bool
    {
        $enabled = $_ENV['TRANSLATOR_STOP_ON_MISMATCH'] ?? getenv('TRANSLATOR_STOP_ON_MISMATCH') ?: '';
        if ($enabled === '1' || strtolower((string)$enabled) === 'true') {
            return true;
        }
        return $this->debugEnabled();
    }

    private function lineCountSplit(string $text): int
    {
        return count($this->splitLines($text));
    }

    private function lineCountByNewline(string $text): int
    {
        return substr_count($text, "\n") + (strlen($text) > 0 ? 1 : 0);
    }

    private function fileName(string $relativePath): string
    {
        return ltrim($relativePath, '/');
    }

    private function debugEnabled(): bool
    {
        $enabled = $_ENV['TRANSLATOR_DEBUG'] ?? getenv('TRANSLATOR_DEBUG') ?: '';
        $global = $_ENV['DEBUG'] ?? getenv('DEBUG') ?: '';
        if ($enabled === '1' || strtolower((string)$enabled) === 'true') {
            return true;
        }
        return $global === '1' || strtolower((string)$global) === 'true';
    }

    private function promptsOnlyEnabled(): bool
    {
        $enabled = $_ENV['PROMPT'] ?? getenv('PROMPT') ?: '';
        return $enabled === '1' || strtolower((string)$enabled) === 'true';
    }

    private function elapsedStamp(): string
    {
        $elapsed = (int)floor(microtime(true) - $this->startTime);
        $minutes = intdiv($elapsed, 60);
        $seconds = $elapsed % 60;
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    /**
     * @return string[]
     */
    private function splitLines(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        return explode("\n", $text);
    }

    /**
     * @return array{src: string, tgt: string}|null
     */
    private function dumpMismatch(string $relativePath, string $language, string $model, string $source, string $translated): ?array
    {
        $enabled = $_ENV['TRANSLATOR_DUMP_MISMATCH'] ?? getenv('TRANSLATOR_DUMP_MISMATCH') ?: '';
        if ($enabled !== '1' && strtolower((string)$enabled) !== 'true' && !$this->debugEnabled()) {
            return null;
        }
        $this->mismatchCounter++;
        $safeFile = preg_replace('/[^a-zA-Z0-9._-]/', '_', $relativePath) ?? 'chunk';
        $safeModel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $model) ?? 'model';
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $prefix = $tmpDir . '/translator-mismatch-' . $safeFile . '-' . $language . '-' . $safeModel . '-' . $this->mismatchCounter;
        $srcPath = $prefix . '-src.txt';
        $tgtPath = $prefix . '-tgt.txt';
        file_put_contents($srcPath, $source);
        file_put_contents($tgtPath, $translated);
        return ['src' => $srcPath, 'tgt' => $tgtPath];
    }

    private function dumpPrompt(
        string $relativePath,
        string $language,
        string $model,
        string $prompt,
        string $chunk,
        ?int $chunkNumber = null,
        bool $forceDump = false
    ): ?string
    {
        $enabled = $_ENV['TRANSLATOR_DUMP_PROMPT'] ?? getenv('TRANSLATOR_DUMP_PROMPT') ?: '';
        if (
            !$forceDump
            && $enabled !== '1'
            && strtolower((string)$enabled) !== 'true'
            && !$this->debugEnabled()
        ) {
            return null;
        }
        $safeFile = preg_replace('/[^a-zA-Z0-9._-]/', '_', $relativePath) ?? 'chunk';
        $safeModel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $model) ?? 'model';
        $tmpDir = rtrim(sys_get_temp_dir(), '/');
        $hash = substr(sha1($chunk), 0, 8);
        if ($chunkNumber === null) {
            $chunkNumber = $this->chunkIndexByHash($relativePath, $hash);
        }
        $chunkSuffix = $chunkNumber !== null ? '-chunk' . $chunkNumber : '';
        $path = $tmpDir . '/translator-prompt-' . $safeFile . '-' . $language . '-' . $safeModel . $chunkSuffix . '-' . $hash . '.txt';
        $fullPrompt = $prompt . "\n\n---\n\n" . $chunk;
        file_put_contents($path, $fullPrompt);
        $this->logStep('PROMPT_DUMP', $relativePath, $language, $chunkNumber, $model, null, "path={$path}");
        return $path;
    }

    /**
     * @param string[] $chunks
     */
    private function dumpPromptsForChunks(string $relativePath, string $language, array $chunks, bool $valuesOnly): void
    {
        $prompt = $this->renderRolePrompt($language);
        foreach ($chunks as $index => $chunk) {
            if (!$valuesOnly && $this->isChunkNonTranslatable($chunk)) {
                continue;
            }
            $chunkNumber = $index + 1;
            foreach ($this->config->modelsForLanguage($language) as $model) {
                $path = $this->dumpPrompt($relativePath, $language, $model, $prompt, $chunk, $chunkNumber, true);
                $promptPath = $path ?? 'unknown';
                $this->logStep('PROMPT', $relativePath, $language, $chunkNumber, $model, null, "prompt {$promptPath}");
            }
        }
    }

    private function chunkIndexByHash(string $relativePath, string $hash): ?int
    {
        $sourceFile = $this->config->sourceDir() . '/' . $relativePath;
        if (!is_file($sourceFile)) {
            return null;
        }
        $sourceContent = (string)file_get_contents($sourceFile);
        $extracted = $this->chunker->extractCodeBlocks($sourceContent);
        $chunks = $this->chunker->splitIntoChunks($extracted['content'], $this->config->translationChunkSize);
        foreach ($chunks as $index => $chunk) {
            if (hash('sha256', $chunk) === $hash) {
                return $index + 1;
            }
        }
        return null;
    }

    /**
     * @return array{string, string}|null
     */
    private function findCacheEntry(string $cacheId): ?array
    {
        $cacheDir = $this->config->cacheDir();
        if (!is_dir($cacheDir)) {
            return null;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($cacheDir));
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.json')) {
                continue;
            }
            $data = json_decode((string)file_get_contents($file->getPathname()), true);
            if (!is_array($data) || !isset($data[$cacheId]['original'])) {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($cacheDir) + 1);
            $relativePath = preg_replace('/\.json$/', '', $relativePath) ?? $relativePath;
            return [$relativePath, (string)$data[$cacheId]['original']];
        }

        return null;
    }

    /**
     * @param string[] $lines
     * @return array{int, int}|null
     */
    private function yamlFrontMatterRange(array $lines): ?array
    {
        if (count($lines) < 2) {
            return null;
        }
        if (trim($lines[0]) !== '---') {
            return null;
        }
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '---') {
                return [0, $i];
            }
        }
        return [0, count($lines) - 1];
    }

    private function determineLineEnding(string $source): string
    {
        $hasCrlf = str_contains($source, "\r\n");
        $hasLfOnly = (bool)preg_match('/(?<!\r)\n/', $source);
        if ($hasCrlf && $hasLfOnly) {
            return "\n";
        }
        return $hasCrlf ? "\r\n" : "\n";
    }

    /**
     * @param string[] $languages
     */
    private function cleanupDeletedFiles(array $languages): void
    {
        $sourceDir = $this->config->sourceDir();
        foreach ($languages as $language) {
            $langDir = $this->config->targetDir() . '/' . $language;
            if (!is_dir($langDir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($langDir));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.md')) {
                    continue;
                }
                $relativePath = ltrim(substr($file->getPathname(), strlen($langDir)), '/');
                $sourceFile = $sourceDir . '/' . $relativePath;
                if (!is_file($sourceFile)) {
                    unlink($file->getPathname());
                }
            }
        }
    }

    /**
     * @param string[] $languages
     */
    private function copyNonMarkdownAssets(array $languages): void
    {
        $sourceDir = $this->config->sourceDir();
        if (!is_dir($sourceDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceDir));
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (str_ends_with($file->getFilename(), '.md')) {
                continue;
            }
            $relativePath = ltrim(substr($file->getPathname(), strlen($sourceDir)), '/');
            foreach ($languages as $language) {
                $targetPath = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
                if (is_file($targetPath)) {
                    continue;
                }
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0777, true);
                }
                copy($file->getPathname(), $targetPath);
            }
        }
    }

    /**
     * @param string[] $chunks
     */
    private function needsTranslation(
        string $sourceContent,
        string $targetFile,
        string $language,
        string $relativePath,
        array $chunks
    ): bool {
        if (!is_file($targetFile)) {
            return true;
        }

        $targetContent = (string)file_get_contents($targetFile);
        $sourceLines = $this->splitLines($sourceContent);
        $targetLines = $this->splitLines($targetContent);
        if (count($sourceLines) !== count($targetLines)) {
            return true;
        }

        if ($this->emptyLinePositions($sourceLines) !== $this->emptyLinePositions($targetLines)) {
            return true;
        }

        $chunksToCheck = $chunks;
        $yamlPlan = $this->buildYamlValuePlan($sourceContent);
        if ($yamlPlan !== null) {
            $chunksToCheck = $yamlPlan['text'] === ''
                ? []
                : $this->splitValuesText($yamlPlan['text'], $this->config->translationChunkSize);
        }

        foreach ($chunksToCheck as $chunk) {
            $hash = hash('sha256', $chunk);
            $cached = $this->cache->getCachedTranslation($hash, $language, $relativePath);
            if ($cached === null) {
                return true;
            }
            if (!$this->chunkStructureMatches($chunk, $cached)) {
                return true;
            }
        }

        if (!$this->validator->validate($sourceContent, $targetContent)) {
            return true;
        }

        $untranslatedChunks = $this->findUntranslatedChunks($sourceContent, $targetContent);
        if ($untranslatedChunks !== []) {
            return true;
        }

        $fileName = $this->fileName($relativePath);
        $this->logStep('SKIP', $relativePath, $language, null, null, null, 'up_to_date');
        return false;
    }

    /**
     * @param string[] $lines
     * @return int[]
     */
    private function emptyLinePositions(array $lines): array
    {
        $positions = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                $positions[] = $i;
            }
        }
        return $positions;
    }
}
