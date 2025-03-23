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
main_branch: master               # Main branch for git diff operations

# MD5 tracking file for changes
md5_file: translation.json        # File to track content changes

# Role template location (project-specific)
role_template: translator.role.tpl  # Template for translation roles
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
You are a professional translator to $LANGUAGE language. You are specialized in translating Hugo template and markdown files with precise line-by-line translation. Your task is to:

1. Translate the entire document provided
2. Preserve the original document's structure exactly: all original formatting, spacing, empty lines and special characters
3. DO NOT translate:
   - YAML front matter keys
   - Hugo shortcodes and constructs
   - Code blocks
   - File paths
   - Variables
   - HTML tags
4. Do not ask questions or request continuations
5. ENSURE each line in the original corresponds to the same line in the translated version
6. If there is nothing to translate, just leave it as is and respond with original document, do not comment your actions

Translate the following document exactly as instructed.
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

If `project_directory` is not specified, the parent directory of the translator folder will be used as the project directory.

### Sync Translations

To synchronize translations with the source files (preserving the exact line structure):

```bash
./translator/bin/sync-translations [project_directory]
```

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

1. Scan for markdown files in the source directory
2. Calculate MD5 hash of each file to detect changes
3. Create a diff if the file has changed since last translation
4. For each target language, translate the file or apply the diff
5. Synchronize the line structure between source and translation
6. Update the MD5 hash record for the file

### File Tracking

The translator maintains a JSON file (defined by `md5_file` in config) that tracks the MD5 hash of each source file. This ensures that only files that have changed are retranslated.

## Troubleshooting

- If translations are failing, check the output for specific error messages.
- Ensure your AI service API keys are properly configured for the `aichat` tool.
- For line-count mismatch errors, the tool will attempt multiple models based on priority until a good translation is found.

## License

This project is licensed under the MIT License - see the LICENSE file for details.
