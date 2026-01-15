<?php

declare(strict_types=1);

namespace Translator;

final class Cache
{
    public function __construct(private readonly Config $config)
    {
    }

    public function init(): void
    {
        $dir = $this->config->cacheDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    public function getCacheFilePath(string $relativePath): string
    {
        $cacheFile = $this->config->cacheDir() . '/' . $relativePath . '.json';
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0777, true);
        }
        return $cacheFile;
    }

    public function getCachedTranslation(string $hash, string $language, string $relativePath): ?string
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        if (!is_file($cacheFile)) {
            return null;
        }
        $data = $this->readJson($cacheFile);
        if (!isset($data[$hash]['translations'][$language])) {
            return null;
        }
        $value = $data[$hash]['translations'][$language];
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCachedEntry(string $hash, string $relativePath): ?array
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        if (!is_file($cacheFile)) {
            return null;
        }
        $data = $this->readJson($cacheFile);
        $entry = $data[$hash] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public function saveToCache(
        string $hash,
        string $original,
        string $language,
        string $translation,
        bool $isCodeOrComment,
        string $relativePath,
        ?string $model = null,
        bool $valuesOnly = false
    ): void {
        $this->init();
        $cacheFile = $this->getCacheFilePath($relativePath);
        $lockDir = $cacheFile . '.lock';
        if (!$this->acquireLock($lockDir, 10)) {
            return;
        }

        $data = $this->readJson($cacheFile);
        $entry = $data[$hash] ?? [
            'original' => $original,
            'translations' => [],
            'is_code_or_comment' => $isCodeOrComment,
        ];
        if (!is_array($entry['translations'])) {
            $entry['translations'] = [];
        }
        $entry['translations'][$language] = $isCodeOrComment ? $original : $translation;
        if ($model !== null) {
            $entry['model'] = $model;
        }
        if ($valuesOnly) {
            $entry['values_only'] = true;
        }
        $entry['updated_at'] = time();

        $data[$hash] = $entry;
        $this->writeJsonAtomic($cacheFile, $data);
        $this->releaseLock($lockDir);
    }

    public function clearFileCache(string $relativePath): void
    {
        $this->init();
        $cacheFile = $this->getCacheFilePath($relativePath);
        $lockDir = $cacheFile . '.lock';
        if (!$this->acquireLock($lockDir, 10)) {
            return;
        }
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
        $this->releaseLock($lockDir);
    }

    public function clearCacheEntry(string $hash, string $language, string $relativePath): void
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        $lockDir = $cacheFile . '.lock';
        if (!$this->acquireLock($lockDir, 10)) {
            return;
        }
        $data = $this->readJson($cacheFile);
        if (isset($data[$hash]['translations'][$language])) {
            unset($data[$hash]['translations'][$language]);
            $this->writeJsonAtomic($cacheFile, $data);
        }
        $this->releaseLock($lockDir);
    }

    public function hasCacheEntries(string $relativePath): bool
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        if (!is_file($cacheFile)) {
            return false;
        }
        $data = $this->readJson($cacheFile);
        foreach ($data as $key => $_value) {
            if ($key === '__meta') {
                continue;
            }
            return true;
        }
        return false;
    }

    public function getFileSourceHash(string $relativePath): ?string
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        if (!is_file($cacheFile)) {
            return null;
        }
        $data = $this->readJson($cacheFile);
        if (!isset($data['__meta']['source_md5'])) {
            return null;
        }
        $value = $data['__meta']['source_md5'];
        return is_string($value) ? $value : null;
    }

    public function setFileSourceHash(string $relativePath, string $hash): void
    {
        $this->init();
        $cacheFile = $this->getCacheFilePath($relativePath);
        $lockDir = $cacheFile . '.lock';
        if (!$this->acquireLock($lockDir, 10)) {
            return;
        }
        $data = $this->readJson($cacheFile);
        if (!isset($data['__meta']) || !is_array($data['__meta'])) {
            $data['__meta'] = [];
        }
        $data['__meta']['source_md5'] = $hash;
        $data['__meta']['updated_at'] = time();
        $this->writeJsonAtomic($cacheFile, $data);
        $this->releaseLock($lockDir);
    }

    public function removeCacheEntry(string $hash, string $relativePath): void
    {
        $cacheFile = $this->getCacheFilePath($relativePath);
        $lockDir = $cacheFile . '.lock';
        if (!$this->acquireLock($lockDir, 10)) {
            return;
        }
        $data = $this->readJson($cacheFile);
        if (isset($data[$hash])) {
            unset($data[$hash]);
            $this->writeJsonAtomic($cacheFile, $data);
        }
        $this->releaseLock($lockDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJsonAtomic(string $path, array $data): void
    {
        $dir = dirname($path);
        $temp = $dir . '/cache.' . getmypid() . '.' . microtime(true) . '.json';
        file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($temp, $path);
    }

    private function acquireLock(string $lockDir, int $timeoutSeconds): bool
    {
        $start = time();
        while (true) {
            if (@mkdir($lockDir)) {
                file_put_contents($lockDir . '/pid', (string)getmypid());
                return true;
            }
            if (is_dir($lockDir)) {
                $pidFile = $lockDir . '/pid';
                if (is_file($pidFile)) {
                    $pidRaw = @file_get_contents($pidFile);
                    $pid = $pidRaw !== false ? (int)trim($pidRaw) : 0;
                    if ($pid > 0 && function_exists('posix_kill') && !posix_kill($pid, 0)) {
                        @rmdir($lockDir);
                        continue;
                    }
                } else {
                    $age = time() - filemtime($lockDir);
                    if ($age > 60) {
                        @rmdir($lockDir);
                        continue;
                    }
                }
            }
            if (time() - $start >= $timeoutSeconds) {
                return false;
            }
            usleep(100000);
        }
    }

    private function releaseLock(string $lockDir): void
    {
        if (is_dir($lockDir)) {
            $pidFile = $lockDir . '/pid';
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
            @rmdir($lockDir);
        }
    }
}
