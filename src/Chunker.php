<?php

declare(strict_types=1);

namespace Translator;

final class Chunker
{
    /**
     * @return array{content: string, blocks: array<int, string>}
     */
    public function extractCodeBlocks(string $content): array
    {
        $lines = $this->splitLines($content);
        $blocks = [];
        $out = [];
        $inCode = false;
        $current = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\s*)(```)(.*)$/', $line) === 1) {
                if (!$inCode) {
                    $inCode = true;
                    $current = [$line];
                } else {
                    $current[] = $line;
                    $blocks[] = implode("\n", $current);
                    $out[] = 'CODE_BLOCK_' . (count($blocks) - 1);
                    $inCode = false;
                    $current = [];
                }
                continue;
            }
            if ($inCode) {
                $current[] = $line;
                continue;
            }
            $out[] = $line;
        }

        if ($inCode) {
            $blocks[] = implode("\n", $current);
            $out[] = 'CODE_BLOCK_' . (count($blocks) - 1);
        }

        return [
            'content' => implode("\n", $out),
            'blocks' => $blocks,
        ];
    }

    /**
     * @param array<int, string> $blocks
     */
    public function restoreCodeBlocks(string $content, array $blocks): string
    {
        $lines = $this->splitLines($content);
        $out = [];
        foreach ($lines as $line) {
            if (preg_match('/^CODE_BLOCK_([0-9]+)$/', $line, $matches) === 1) {
                $index = (int)$matches[1];
                if (array_key_exists($index, $blocks)) {
                    $out[] = $blocks[$index];
                } else {
                    $out[] = $line;
                }
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * @return string[]
     */
    public function splitIntoChunks(string $content, int $maxSize): array
    {
        if ($content === '') {
            return [];
        }

        $rawChunks = preg_split('/\n{2,}/', $content);
        $chunks = [];
        $current = '';
        $currentSize = 0;

        foreach ($rawChunks as $raw) {
            if ($raw !== '' && str_starts_with($raw, "\n")) {
                $leadingBreaks = strlen($raw) - strlen(ltrim($raw, "\n"));
                if ($leadingBreaks > 0) {
                    if ($current === '' && !empty($chunks)) {
                        $chunks[count($chunks) - 1] .= str_repeat("\n", $leadingBreaks);
                    } else {
                        $current .= str_repeat("\n", $leadingBreaks);
                        $currentSize += $leadingBreaks;
                    }
                    $raw = ltrim($raw, "\n");
                }
            }
            if ($raw === '') {
                if ($current === '') {
                    continue;
                }
                $current .= "\n\n";
                $currentSize += 2;
                continue;
            }
            $rawSize = strlen($raw);
            if ($current !== '' && $currentSize + $rawSize + 2 > $maxSize) {
                $chunks[] = $current;
                $current = $raw;
                $currentSize = $rawSize;
            } elseif ($current === '') {
                $current = $raw;
                $currentSize = $rawSize;
            } else {
                $current .= "\n\n" . $raw;
                $currentSize += $rawSize + 2;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
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
}
