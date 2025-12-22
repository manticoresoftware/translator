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
}
