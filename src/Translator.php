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

    public function __construct(string $projectDir)
    {
        $this->config = Config::load($projectDir);
        $this->cache = new Cache($this->config);
        $this->chunker = new Chunker();
        $this->validator = new Validator();
        $this->client = new OpenRouterClient(timeoutSeconds: $this->config->openrouterTimeout);
    }

    public function translateAll(): int
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
        $maxWorkers = max(1, $this->config->translationParallelFiles);
        if ($maxWorkers > 1 && $this->parallelAvailable()) {
            $failed = !$this->translateFilesInParallel($relativePaths, $languages, $maxWorkers);
        } else {
            foreach ($relativePaths as $relativePath) {
                if (!$this->translateFile($relativePath, $languages)) {
                    $failed = true;
                }
            }
        }

        $this->copyNonMarkdownAssets($languages);

        return $failed ? 1 : 0;
    }

    public function translateSingleFile(string $filePath): int
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

        return $this->translateFile($relativePath, $languages) ? 0 : 1;
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
        return $this->translateFile($relativePath, $languages) ? 0 : 1;
    }

    /**
     * @param string[] $languages
     */
    private function translateFile(string $relativePath, array $languages): bool
    {
        $sourceFile = $this->config->sourceDir() . '/' . $relativePath;
        if (!is_file($sourceFile)) {
            return false;
        }

        $sourceContent = (string)file_get_contents($sourceFile);
        $extracted = $this->chunker->extractCodeBlocks($sourceContent);
        $contentWithPlaceholders = $extracted['content'];
        $blocks = $extracted['blocks'];
        $chunks = $this->chunker->splitIntoChunks($contentWithPlaceholders, $this->config->translationChunkSize);

        $failed = false;
        foreach ($languages as $language) {
            if (!$this->translateFileForLanguage($relativePath, $language, $sourceContent, $blocks, $chunks, false)) {
                $failed = true;
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
        $maxWorkers = max(1, $this->config->translationParallelChunks);
        if ($maxWorkers > 1 && $this->parallelAvailable()) {
            return $this->translateChunksInParallel($relativePath, $language, $chunks, $maxWorkers, $force);
        }

        $translatedChunks = [];
        $fileName = $this->fileName($relativePath);
        foreach ($chunks as $index => $chunk) {
            $chunkNumber = $index + 1;
            $this->infoLog("Process chunk {$chunkNumber}/" . count($chunks) . ": file={$fileName} lang={$language}");
            $this->debugLog("Chunk {$chunkNumber}/" . count($chunks) . ": file={$fileName} lang={$language} bytes=" . strlen($chunk));
            $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber);
            if ($translated === null) {
                $this->infoLog("Abort file: chunk failed file={$fileName} lang={$language}");
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
                $this->infoLog("Process chunk {$chunkNumber}/{$total}: file={$fileName} lang={$language}");
                $this->debugLog("Chunk {$chunkNumber}/{$total}: file={$fileName} lang={$language} bytes=" . strlen($chunk));
                $resultPath = $prefix . $index . '.txt';
                $pid = pcntl_fork();
                if ($pid === -1) {
                    $translated = $this->translateChunk($chunk, $language, $relativePath, null, $force, $chunkNumber);
                    if ($translated === null) {
                        $this->infoLog("Chunk failed: file={$fileName} lang={$language}");
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
                    $this->infoLog("Chunk failed: file={$fileName} lang={$language}");
                    $failed = true;
                    continue;
                }
                $content = is_file($info['path']) ? (string)file_get_contents($info['path']) : null;
                if ($content === null) {
                    $this->infoLog("Chunk failed: file={$fileName} lang={$language}");
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
    private function translateFilesInParallel(array $files, array $languages, int $maxWorkers): bool
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
                    if (!$this->translateFile($relativePath, $languages)) {
                        $failed = true;
                    }
                    $index++;
                    continue;
                }
                if ($pid === 0) {
                    $ok = $this->translateFile($relativePath, $languages);
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
        bool $force
    ): bool {
        $targetFile = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
        $targetDir = dirname($targetFile);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        if (!$force && !$this->needsTranslation($sourceContent, $targetFile, $language, $relativePath, $chunks)) {
            return true;
        }

        $translatedChunks = $this->translateChunks($relativePath, $language, $chunks, $force);
        if ($translatedChunks === null) {
            return false;
        }

        $translatedContent = implode("\n\n", $translatedChunks);
        $restored = $this->chunker->restoreCodeBlocks($translatedContent, $blocks);
        $synced = $this->syncToSourceStructure($sourceContent, $restored);
        $synced = $this->ensureUtf8($synced);

        $validation = $this->validator->validateDetailed($sourceContent, $synced);
        $validationFailed = false;
        if (!$validation['ok']) {
            if (!$force) {
                $fileName = $this->fileName($relativePath);
                $detail = $this->formatValidationDetail($validation);
                $this->infoLog("Validation failed, retranslating: file={$fileName} lang={$language}{$detail}");
                return $this->translateFileForLanguage($relativePath, $language, $sourceContent, $blocks, $chunks, true);
            }
            $validationFailed = true;
            $fileName = $this->fileName($relativePath);
            $detail = $this->formatValidationDetail($validation);
            fwrite(STDERR, "Warning: Validation failed for {$targetFile} (file={$fileName}){$detail}, writing output anyway.\n");
        } else {
            $fileName = $this->fileName($relativePath);
            $this->infoLog("File validated: file={$fileName} lang={$language}");
        }

        file_put_contents($targetFile, $synced);
        return !$validationFailed;
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
        $chunks = $this->chunker->splitIntoChunks($contentWithPlaceholders, $this->config->translationChunkSize);

        $needsWork = false;
        $fileName = $this->fileName($relativePath);
        foreach ($languages as $language) {
            $targetFile = $this->config->targetDir() . '/' . $language . '/' . $relativePath;
            $status = $this->checkFileForLanguage($relativePath, $language, $sourceContent, $chunks, $targetFile);
            if ($status['needs_translation']) {
                $needsWork = true;
                $reason = $this->describeCheckReason($status['reason'], $status['validation'] ?? null);
                $msg = "[check] file={$fileName} lang={$language} reason={$reason}";
                if (!empty($status['missing_hashes'])) {
                    $msg .= " missing_chunks=" . count($status['missing_hashes']);
                }
                if (!empty($status['mismatched_hashes'])) {
                    $msg .= " mismatched_chunks=" . count($status['mismatched_hashes']);
                }
                fwrite(STDOUT, $msg . PHP_EOL);
            }
        }

        return $needsWork;
    }

    /**
     * @param string[] $chunks
     * @return array{needs_translation: bool, reason: string, validation?: array|null, missing_hashes: array<int, string>, mismatched_hashes: array<int, string>}
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
            return [
                'needs_translation' => true,
                'reason' => 'empty-lines',
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
                'missing_hashes' => $missing,
                'mismatched_hashes' => $mismatched,
            ];
        }

        return [
            'needs_translation' => false,
            'reason' => 'ok',
            'validation' => null,
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
            'validation' => 'file validation failed (comments/lists/code fences)',
            'cache-miss' => 'cache miss (one or more chunks)',
            'cache-mismatch' => 'cache mismatch (chunk structure)',
            default => $reason,
        };
        if ($reason !== 'validation' || $validation === null) {
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
        if ($source !== null || $target !== null) {
            $parts[] = " src=" . $this->shortenForLog($source);
            $parts[] = " tgt=" . $this->shortenForLog($target);
        }
        return $parts === [] ? '' : ' (' . trim(implode(' ', $parts)) . ')';
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
    private function translateChunk(
        string $chunk,
        string $language,
        string $relativePath,
        ?string $cacheIdOverride = null,
        bool $force = false,
        ?int $chunkNumber = null
    ): ?string {
        $startedAt = microtime(true);
        $hash = $cacheIdOverride ?? hash('sha256', $chunk);
        $fileName = $this->fileName($relativePath);

        if (!$force) {
            $cached = $this->cache->getCachedTranslation($hash, $language, $relativePath);
            if ($cached !== null) {
                if ($this->chunkStructureMatches($chunk, $cached) && $this->chunkValidates($chunk, $cached)) {
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    $this->infoLog("Cache hit: file={$fileName} lang={$language} hash={$hash} ms={$elapsedMs}");
                    return $cached;
                }
                $validation = $this->validator->validateDetailed($chunk, $cached);
                if (!$validation['ok']) {
                    $detail = $this->formatValidationDetail($validation);
                    $this->debugLog("Cache mismatch (validation): file={$fileName} lang={$language} hash={$hash}{$detail}");
                } else {
                    $this->debugLog("Cache mismatch: file={$fileName} lang={$language} hash={$hash}");
                }
            } else {
                $this->debugLog("Cache miss: file={$fileName} lang={$language} hash={$hash}");
            }
        }

        if ($this->isChunkNonTranslatable($chunk)) {
            $this->cache->saveToCache($hash, $chunk, $language, $chunk, true, $relativePath);
            return $chunk;
        }

        $rolePrompt = $this->renderRolePrompt($language);
        $lastError = null;
        foreach ($this->config->modelsForLanguage($language) as $model) {
            try {
                $this->infoLog("Try model: file={$fileName} lang={$language} model={$model}");
                $this->debugLog("Translate chunk: file={$fileName} lang={$language} model={$model} bytes=" . strlen($chunk));
                $this->dumpPrompt($relativePath, $language, $model, $rolePrompt, $chunk, $chunkNumber);
                $translated = $this->client->translate($model, $rolePrompt, $chunk, $fileName, $language, $chunkNumber);
                if (!$this->isValidUtf8($translated)) {
                    fwrite(STDERR, "Warning: Non-UTF8 translation for {$relativePath} (file={$fileName}) ({$language}), falling back to source chunk.\n");
                    $translated = $chunk;
                }
                $translated = $this->normalizeHeadingLines($translated);
                $translated = $this->ensureTrailingEmptyLines($chunk, $translated);
                $translated = $this->restoreCommentLines($chunk, $translated);
                if ($this->lineCountSplit($chunk) !== $this->lineCountSplit($translated)) {
                    $translated = $this->syncToSourceStructure($chunk, $translated);
                }
                $translated = $this->restoreListMarkers($chunk, $translated);
                $chunkValidation = $this->validator->validateDetailed($chunk, $translated);
                if (!$chunkValidation['ok']) {
                    $detail = $this->formatValidationDetail($chunkValidation);
                    $this->debugLog("Chunk validation failed: file={$fileName} lang={$language} model={$model}{$detail}");
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
                        $this->debugLog($message);
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
                        $this->debugLog($message);
                        return null;
                    }
                }
                if ($this->chunkStructureMatches($chunk, $translated)) {
                    $this->cache->saveToCache($hash, $chunk, $language, $translated, false, $relativePath, $model);
                    $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
                    $this->infoLog("Chunk ok (validated): file={$fileName} lang={$language} model={$model} ms={$elapsedMs}");
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
                $this->debugLog($message);
            } catch (\Throwable $e) {
                $lastError = $e;
                continue;
            }
        }

        if ($lastError !== null) {
            fwrite(STDERR, "Translation failed for {$relativePath} (file={$fileName}) ({$language}): {$lastError->getMessage()}\n");
        }
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);
        $this->infoLog("Chunk failed: file={$fileName} lang={$language} ms={$elapsedMs}");
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
            fwrite(STDERR, "[translator] {$message}\n");
        }
    }

    private function infoLog(string $message): void
    {
        fwrite(STDERR, "[translator] {$message}\n");
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
        return basename($relativePath);
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
        ?int $chunkNumber = null
    ): void
    {
        $enabled = $_ENV['TRANSLATOR_DUMP_PROMPT'] ?? getenv('TRANSLATOR_DUMP_PROMPT') ?: '';
        if ($enabled !== '1' && strtolower((string)$enabled) !== 'true' && !$this->debugEnabled()) {
            return;
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
        $this->debugLog("Prompt dumped: {$path}");
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
        return null;
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

        foreach ($chunks as $chunk) {
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

        $fileName = $this->fileName($relativePath);
        fwrite(STDERR, "[translator] Skip: up-to-date file={$fileName} lang={$language}\n");
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
