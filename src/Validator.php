<?php

declare(strict_types=1);

namespace Translator;

final class Validator
{
    public function validate(string $source, string $target): bool
    {
        return $this->validateDetailed($source, $target)['ok'];
    }

    /**
     * @return array{ok: bool, reason: string, line: int|null, source: string|null, target: string|null}
     */
    public function validateDetailed(string $source, string $target): array
    {
        $sourceLines = $this->splitLines($source);
        $targetLines = $this->splitLines($target);

        if (count($sourceLines) !== count($targetLines)) {
            return [
                'ok' => false,
                'reason' => 'line-count',
                'line' => null,
                'source' => null,
                'target' => null,
            ];
        }

        $mismatch = $this->firstMismatch($sourceLines, $targetLines, fn($line) => str_contains($line, '```'));
        if ($mismatch !== null) {
            return $mismatch + ['reason' => 'code-fence'];
        }

        $mismatch = $this->firstMismatch($sourceLines, $targetLines, fn($line) => $this->isEmptyLine($line));
        if ($mismatch !== null) {
            return $mismatch + ['reason' => 'empty-line'];
        }

        $mismatch = $this->firstMismatch($sourceLines, $targetLines, fn($line) => $this->isListItemLine($line));
        if ($mismatch !== null) {
            return $mismatch + ['reason' => 'list-item'];
        }

        $commentMismatch = $this->commentLinesMismatch($sourceLines, $targetLines);
        if ($commentMismatch !== null) {
            return $commentMismatch + ['reason' => 'html-comment'];
        }

        $linkMismatch = $this->linkUrlMismatch($sourceLines, $targetLines);
        if ($linkMismatch !== null) {
            return $linkMismatch + ['reason' => 'link-url'];
        }

        $untranslated = $this->untranslatedMismatch($source, $target);
        if ($untranslated !== null) {
            return $untranslated + ['reason' => 'untranslated'];
        }

        return [
            'ok' => true,
            'reason' => 'ok',
            'line' => null,
            'source' => null,
            'target' => null,
        ];
    }

    /**
     * @return string[]
     */
    private function splitLines(string $content): array
    {
        $content = str_replace("\r\n", "\n", $content);
        $content = str_replace("\r", "\n", $content);
        return explode("\n", $content);
    }

    /**
     * @param string[] $sourceLines
     * @param string[] $targetLines
     */
    private function positionsMatch(array $sourceLines, array $targetLines, callable $predicate): bool
    {
        $sourcePositions = [];
        $targetPositions = [];
        foreach ($sourceLines as $i => $line) {
            if ($predicate($line)) {
                $sourcePositions[] = $i;
            }
        }
        foreach ($targetLines as $i => $line) {
            if ($predicate($line)) {
                $targetPositions[] = $i;
            }
        }
        return $sourcePositions === $targetPositions;
    }

    /**
     * @param string[] $sourceLines
     * @param string[] $targetLines
     * @return array{ok: bool, line: int, source: string, target: string}|null
     */
    private function firstMismatch(array $sourceLines, array $targetLines, callable $predicate): ?array
    {
        $max = max(count($sourceLines), count($targetLines));
        for ($i = 0; $i < $max; $i++) {
            $sourceLine = $i < count($sourceLines) ? $sourceLines[$i] : '';
            $targetLine = $i < count($targetLines) ? $targetLines[$i] : '';
            $sourceHas = $predicate($sourceLine);
            $targetHas = $predicate($targetLine);
            if ($sourceHas !== $targetHas) {
                return [
                    'ok' => false,
                    'line' => $i + 1,
                    'source' => $sourceLine,
                    'target' => $targetLine,
                ];
            }
        }
        return null;
    }

    /**
     * @param string[] $sourceLines
     * @param string[] $targetLines
     */
    private function commentLinesMatch(array $sourceLines, array $targetLines): bool
    {
        return $this->commentLinesMismatch($sourceLines, $targetLines) === null;
    }

    /**
     * @param string[] $sourceLines
     * @param string[] $targetLines
     * @return array{ok: bool, line: int, source: string, target: string}|null
     */
    private function commentLinesMismatch(array $sourceLines, array $targetLines): ?array
    {
        $sourcePositions = [];
        $targetPositions = [];
        $sourceContents = [];
        $targetContents = [];

        foreach ($sourceLines as $i => $line) {
            if ($this->isHtmlCommentOnly($line)) {
                $sourcePositions[] = $i;
                $sourceContents[] = $line;
            }
        }
        foreach ($targetLines as $i => $line) {
            if ($this->isHtmlCommentOnly($line)) {
                $targetPositions[] = $i;
                $targetContents[] = $line;
            }
        }

        if ($sourcePositions === $targetPositions && $sourceContents === $targetContents) {
            return null;
        }

        $max = max(count($sourceLines), count($targetLines));
        for ($i = 0; $i < $max; $i++) {
            $sourceLine = $i < count($sourceLines) ? $sourceLines[$i] : '';
            $targetLine = $i < count($targetLines) ? $targetLines[$i] : '';
            $sourceIs = $this->isHtmlCommentOnly($sourceLine);
            $targetIs = $this->isHtmlCommentOnly($targetLine);
            if ($sourceIs !== $targetIs || ($sourceIs && $sourceLine !== $targetLine)) {
                return [
                    'ok' => false,
                    'line' => $i + 1,
                    'source' => $sourceLine,
                    'target' => $targetLine,
                ];
            }
        }

        return [
            'ok' => false,
            'line' => 1,
            'source' => $sourceLines[0] ?? '',
            'target' => $targetLines[0] ?? '',
        ];
    }

    private function isEmptyLine(string $line): bool
    {
        return trim($line) === '';
    }

    private function isListItemLine(string $line): bool
    {
        return (bool)preg_match('/^[\\t ]*(?:[-*]\\s+|\\d+[.)]\\s+)/', $line);
    }

    private function isHtmlCommentOnly(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '<!--') {
            return true;
        }
        return (bool)preg_match('/^<!--.*-->$/', $trimmed);
    }

    /**
     * @param string[] $sourceLines
     * @param string[] $targetLines
     * @return array{ok: bool, line: int, source: string, target: string}|null
     */
    private function linkUrlMismatch(array $sourceLines, array $targetLines): ?array
    {
        $max = max(count($sourceLines), count($targetLines));
        for ($i = 0; $i < $max; $i++) {
            $sourceLine = $i < count($sourceLines) ? $sourceLines[$i] : '';
            $targetLine = $i < count($targetLines) ? $targetLines[$i] : '';
            $sourceLinks = $this->linkUrls($sourceLine);
            if ($sourceLinks === []) {
                continue;
            }
            $targetLinks = $this->linkUrls($targetLine);
            if ($targetLinks === []) {
                continue;
            }
            $sourceSet = array_fill_keys($sourceLinks, true);
            foreach ($targetLinks as $url) {
                if (!isset($sourceSet[$url])) {
                    return [
                        'ok' => false,
                        'line' => $i + 1,
                        'source' => $sourceLine,
                        'target' => $targetLine,
                    ];
                }
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function linkUrls(string $line): array
    {
        $count = preg_match_all('/(?<!\!)\[[^\]]+\]\(([^)]+)\)/', $line, $matches);
        if ($count === false || $count === 0) {
            return [];
        }
        $urls = $matches[1] ?? [];
        return array_map(static fn(string $url): string => trim($url), $urls);
    }

    /**
     * @return array{ok: bool, line: int|null, source: string|null, target: string|null, jaccard: float, lcs: float}|null
     */
    private function untranslatedMismatch(string $source, string $target): ?array
    {
        $sourceTokens = $this->normalizeTokens($source);
        $targetTokens = $this->normalizeTokens($target);
        if ($sourceTokens === [] || $targetTokens === []) {
            return null;
        }

        $sourceSet = array_unique($sourceTokens);
        $targetSet = array_unique($targetTokens);
        $intersection = array_intersect($sourceSet, $targetSet);
        $unionCount = count($sourceSet) + count($targetSet) - count($intersection);
        if ($unionCount === 0) {
            return null;
        }

        $jaccard = count($intersection) / $unionCount;
        $lcsRatio = $this->lcsRatio($sourceTokens, $targetTokens);
        if ($jaccard >= 0.8 && $lcsRatio >= 0.8) {
            return [
                'ok' => false,
                'line' => null,
                'source' => null,
                'target' => null,
                'jaccard' => $jaccard,
                'lcs' => $lcsRatio,
            ];
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function normalizeTokens(string $text): array
    {
        $lower = function (string $value): string {
            if (function_exists('mb_strtolower')) {
                return mb_strtolower($value);
            }
            return strtolower($value);
        };

        $text = $lower($text);
        $text = preg_replace('/`[^`]*`/', ' ', $text) ?? $text;
        $text = preg_replace('/https?:\\/\\/\\S+/i', ' ', $text) ?? $text;
        $text = preg_replace('/(?<!\\!)\\[[^\\]]+\\]\\([^)]+\\)/', ' ', $text) ?? $text;
        $parts = preg_split('/[^\\p{L}\\p{N}]+/u', $text) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $tokens[] = $part;
        }
        return $tokens;
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private function lcsRatio(array $a, array $b): float
    {
        $lenA = count($a);
        $lenB = count($b);
        if ($lenA === 0 || $lenB === 0) {
            return 0.0;
        }
        $dp = array_fill(0, $lenB + 1, 0);
        for ($i = 1; $i <= $lenA; $i++) {
            $prev = 0;
            for ($j = 1; $j <= $lenB; $j++) {
                $temp = $dp[$j];
                if ($a[$i - 1] === $b[$j - 1]) {
                    $dp[$j] = $prev + 1;
                } else {
                    $dp[$j] = max($dp[$j], $dp[$j - 1]);
                }
                $prev = $temp;
            }
        }
        $lcs = $dp[$lenB];
        return $lcs / max($lenA, $lenB);
    }
}
