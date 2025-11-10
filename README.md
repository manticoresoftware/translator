# Translator Tool

A standalone translation utility for multilingual web projects. This tool can be integrated into any project to manage the automatic translation of content using AI-powered translation services.

## Installation

1. Clone this repository into your project:

```bash
git clone https://github.com/your-org/translator.git
cd translator
chmod +x bin/*
```

## Configuration Overview

The translator uses three main configuration files, all located in your project root:

| File | Purpose | Default Template |
|------|---------|------------------|
| `translator.config.yaml` | Project-specific settings | `translator/config/translator.config.template.yaml` |
| `translator.models.yaml` | Translation model configuration | `translator/config/translator.models.template.yaml` |
| `translator.role.tpl` | Translation role template | `translator/config/translator.role.template.tpl` |

If any of these files are not found in your project root, the default templates from the translator directory will be used.

### Configuration Workflow

1. **Setup**: Clone the translator repository into your project
2. **Configure**: Create the configuration files in your project root
3. **Run**: Execute the translation scripts

The translator will automatically:
- Load project configuration
- Load model definitions
- Find or create translation roles
- Process your content

### Project Configuration

Create a `translator.config.yaml` file in your project root directory:

```yaml
# Project translation configuration

# Source and target directories
source_directory: content/english  # Path to your source content
target_directory: content         # Parent directory containing all languages

# Translation parameters
translation_chunk_size: 6144      # Maximum size of text chunks for translation
translation_parallel_chunks: 50   # Number of chunks to process in parallel within a file
translation_parallel_files: 1     # Number of files to process in parallel (set to 1 to disable)

# Cache directory for translations (per-document cache structure)
cache_directory: .translation-cache  # Directory for per-document cache files

# Role template location (project-specific)
role_template: translator.role.tpl  # Template for translation roles

# YAML front matter validation
check_yaml_keys: false  # If true, validates that YAML keys contain only valid characters (a-z, A-Z, 0-9, _, ., -)
                       # Rejects translations that would corrupt YAML structure. Set to false for Hugo sites
                       # where front matter keys should remain in English.
```

A template file is available at `translator/config/translator.config.template.yaml`.

### Translation Models Configuration

Create a `translator.models.yaml` file in your project root directory to configure the AI models used for translation:

```yaml
# Translation models configuration
# Models are sorted by token price in ascending order (input/output)
models:
  - name: openai:gpt-4o-mini
    priority: 1
    price_notes: $0.15 / $0.6
  
  - name: claude:claude-3-5-haiku-latest
    priority: 2
    price_notes: $0.8 / $4
  
  # Add more models as needed
```

A template file is available at `translator/config/translator.models.template.yaml`.

If either of these configuration files are not found, default values will be used.

## Translation Role Configuration

The translation role template (`translator.role.tpl`) defines the instructions given to the AI model for translation tasks. This is a critical component that affects translation quality and accuracy.

### File Location

- **Primary location**: `<project_root>/translator.role.tpl`
- **Default template**: `translator/config/translator.role.template.tpl`

### Role Template Format

Create a `translator.role.tpl` file in your project root with instructions for the translation model. Here's a template:

```markdown
You are a professional translator to $LANGUAGE language. You are specialized in translating markdown files with precise line-by-line translation. Your task is to:

1. Translate the entire document provided
2. Preserve the original document's structure exactly: all original formatting, spacing, empty lines and special characters
3. DO NOT translate:
   - comment blocks in <!-- ... -->
   - Any code blocks
   - File paths
   - Variables
   - HTML tags
4. Do not ask questions or request continuations
5. ENSURE each line in the original corresponds to the same line in the translated version, even EMPTY line follows EMPTY line, very important to make translation LINE perfect same as original
6. Do not translate lines with the following strings: CODE_BLOCK_0 where 0 can be any number, this is a special string that indicates the start of a code block

Translate the following document exactly as instructed. Reply with just the translation WITHOUT adding anything additional from your side.
```

### Environment Variables

The template uses the following environment variables for substitution:

- `$LANGUAGE`: Replaced with the target language name during role creation

### Custom Roles

You can customize the role template to provide more specific instructions for your content types. For example:

- Add specific terminology to maintain across translations
- Include special instructions for domain-specific content
- Define rules for handling particular content elements

### How Roles are Used

During translation:

1. The translator checks if the role already exists in the aichat roles directory
2. If not, it creates the role using your template or the default template
3. When translating, files are sent to the AI model with the appropriate role instruction

The role files are created in the aichat roles directory as `translate-to-<language>.md`.

## Usage

### Auto-Translate

To automatically translate all content:

```bash
./translator/bin/auto-translate [project_directory]
```

If `project_directory` is not specified, the current directory will be used as the project directory.

The auto-translate script will:
- Detect which files need translation by comparing line counts and checking the cache
- Use cached translations when available to avoid retranslating unchanged content
- Preserve code blocks and HTML comments exactly as they appear
- Maintain the exact line structure between source and translation
- Automatically clean up deleted source files from translations

## Prerequisites

The translator tool requires the following dependencies:

- `aichat` - CLI tool for interacting with AI models
- `jq` - Command-line JSON processor
- `yq` - Command-line YAML processor
- `envsubst` - Substitutes environment variables in shell format strings

## Advanced Configuration

### Adding New Languages

To add a new language, create a directory with the language name inside your `target_directory`. The auto-translate tool will automatically detect and translate content for all language directories found.

### Content Structure

The translator expects the following directory structure:

```
project/
├── content/               # target_directory
│   ├── english/          # source_directory
│   │   ├── file1.md
│   │   └── folder/
│   │       └── file2.md
│   ├── spanish/          # target language
│   └── french/           # target language
└── translator/           # translator directory
    ├── bin/
    └── config/
```

### Translation Process

The translation process works as follows:

1. **Scan for markdown files** in the source directory
2. **Check if translation is needed** by:
   - Comparing line counts between source and target files
   - Checking if all content chunks are already in the cache
3. **Extract code blocks and comments** - These are preserved exactly as-is and cached separately
4. **Chunk the content** - Split the document into manageable chunks (default: 6144 bytes)
5. **Check cache first** - For each chunk, check if a translation already exists in the cache
6. **Translate missing chunks** - Only translate chunks that aren't in the cache, using AI models in priority order
7. **Preserve structure** - Ensure the translated file has the exact same line structure as the source (same line numbers for code blocks, comments, and empty lines)
8. **Update cache** - Store all translated chunks in the cache for future use
9. **Clean up** - Remove translation files for deleted source files

### Caching System

The translator uses a cache-based approach to optimize performance:

- **Cache directory**: Defined by `cache_directory` in config (default: `.translation-cache`)
- **Per-document cache**: Each source document has its own cache file, following the same directory structure as the source files
- **Uncompressed storage**: Cache files are stored as plain JSON files (not compressed) for easy inspection and debugging
- **Block-level caching**: Each content block is hashed and cached independently
- **Code block preservation**: Code blocks and HTML comments are cached with their original content (not translated)
- **Incremental updates**: Only new or changed chunks are translated, existing cached translations are reused
- **Multi-language support**: The cache stores translations for each language separately

**Cache Structure Example:**
```
project/
├── content/
│   └── english/
│       ├── docs/
│       │   └── guide.md
│       └── api.md
└── .translation-cache/
    ├── docs/
    │   └── guide.md.json
    └── api.md.json
```

This approach ensures:
- **Efficiency**: Unchanged content is never retranslated
- **Consistency**: Code blocks and comments are always preserved exactly
- **Speed**: Large documents with small changes translate quickly
- **Cost savings**: Reduces API calls to translation services
- **Maintainability**: Per-document cache files are easy to inspect, debug, and manage

## Testing

A comprehensive test suite is available to verify the translation system:

```bash
./run-all-tests.sh
```

This script tests:
- New document translation
- Line changes (single and multiple)
- Empty line handling (addition, removal, at various positions)
- Whitespace-only line preservation
- HTML comment preservation
- Code block preservation
- File deletion handling
- Cache reuse
- Line structure matching (line counts, code block positions, comment positions, empty line positions)

## Troubleshooting

- **Translation failures**: Check the output for specific error messages. The tool will attempt multiple models based on priority until a good translation is found.
- **API keys**: Ensure your AI service API keys are properly configured for the `aichat` tool.
- **Line count mismatches**: The tool automatically retries with different models if line counts don't match. Check that your role template emphasizes line-by-line preservation.
- **Cache issues**: If translations seem stale, you can delete the cache directory (`.translation-cache`) or specific cache files to force a full retranslation. Cache files are stored as plain JSON for easy inspection.
- **Structure preservation**: The system validates that code blocks, HTML comments, and empty lines appear on the same line numbers in source and translation. If this fails, the translation is retried.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
