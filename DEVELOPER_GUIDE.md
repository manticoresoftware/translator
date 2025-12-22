# Developer Guide

This document explains how the translator works internally and where to start when contributing. It focuses on the runtime flow, core functions, and the cache/validation model.

## Entry Points

- `bin/auto-translate`: PHP CLI entry point.
- `bin/run-tests`: test helper.

## High-Level Flow (auto-translate)

1. Parse arguments and resolve project directory + optional single file path.
2. Load configuration from `translator.config.yaml` and `translator.models.yaml`.
3. Resolve target languages from config or target directories.
4. Clean up deleted translations.
5. Translate files (optionally in parallel).
6. Copy non-markdown assets to each language.

## Core Modules and Their Roles

### Configuration and models

- `Config::load(projectDir)` (`src/Config.php`): reads config, resolves paths, languages, models, and OpenRouter settings.
- `Config::modelsForLanguage(language)`: returns the model list for the given language or `default`.

### Cache and locking

- `Cache::getCachedTranslation(hash, lang, relativePath)` (`src/Cache.php`): reads a cached translation from per-document JSON.
- `Cache::saveToCache(hash, original, lang, translation, isCodeOrComment, relativePath, model)` writes a chunk entry with locking.
- `Cache::clearFileCache(relativePath)` clears the per-document cache file.
- Locks use a `*.lock` directory per cache file with a PID file for stale detection.

### Chunking and placeholders

- `Chunker::extractCodeBlocks(content)` (`src/Chunker.php`): replaces fenced code blocks with `CODE_BLOCK_N` placeholders.
- `Chunker::splitIntoChunks(content, maxBytes)` splits by paragraph boundaries and enforces a soft byte limit.
- `Chunker::restoreCodeBlocks(content, blocks)` rehydrates code blocks into the translated output.

### Validation

- `Validator::validateDetailed(source, target)` (`src/Validator.php`): enforces line counts and exact positions of code fences, empty lines, list items, and HTML comment-only lines. Returns the first mismatch detail.
- Chunk validation runs per chunk before caching; file validation runs after reassembly and sync.

### Translation pipeline

- `Translator::translateAll()` (`src/Translator.php`): drives full translation for a project.
- `Translator::translateFileForLanguage(...)`: translates one file and validates the result (retries once on validation failure).
- `Translator::translateChunk(...)`: resolves cache, runs OpenRouter, validates chunk, then caches.
- `Translator::checkAll()` / `checkSingleFile()` power `-c` output and exit status.

### OpenRouter client

- `OpenRouterClient::translate(model, rolePrompt, chunk, file, lang, chunk)` (`src/OpenRouterClient.php`): handles requests, retries, and debug dumps.

## Chunking Rule (how text is split)

Chunking happens after code blocks are replaced with placeholders. The rules:

1. Split on two-or-more newlines (`\n{2,}`) to get paragraph chunks.
2. Accumulate paragraphs until the next addition would exceed `translation_chunk_size`.
3. Chunks never start with empty lines; leading newlines are attached to the previous chunk.
4. Trailing empty lines are preserved (and re-added if the model drops them).

This keeps chunk boundaries aligned to paragraph breaks while preserving spacing.

## Cache System Details

The cache lives under `.translation-cache/` as JSON per source file. Each entry is keyed by a SHA-256 hash of the chunk content and stores:

- `original`: the chunk text
- `translations`: language → translated text
- `is_code_or_comment`
- `model` (when available)

The cache is used both for translation and for `-c` checks (missing or mismatched chunks are reported).

## Practical Contribution Guide

- Start with `src/Translator.php` to follow the end-to-end flow.
- When changing chunking, update both the chunker and any structure checks to keep cache hashes stable.
- When changing validation, update both `Validator` and any chunk-level checks so they agree.

## Testing

- Run the existing test suite from the repository root:
  - `./run-all-tests.sh`

## Files to Know

- `bin/auto-translate`: main PHP CLI entry point.
- `bin/auto-translate`: main PHP CLI.
- `src/Translator.php`: core pipeline.
- `src/Chunker.php`: chunking and code block handling.
- `src/Validator.php`: structure validation.
- `src/Cache.php`: cache I/O and locking.
- `src/OpenRouterClient.php`: OpenRouter API access.
- `translator.config.yaml`: project config.
- `translator.models.yaml`: model lists (including per-language overrides).
- `translator.role.tpl`: prompt template.
