# Translation Patch Tests

This directory contains tests for the YAML-based diff patching system used by the translator tool.

## Running Tests

Run all tests:
```bash
bash tests/run-all-tests.sh
```

Run individual test:
```bash
bash tests/test-patch-simple-edit.sh
```

## Test Coverage

### test-patch-simple-edit.sh
Tests simple line modifications (editing lines without adding or removing).
- Edits line 2 and line 4
- Verifies that modified lines are replaced correctly

### test-patch-add-lines.sh
Tests adding new lines to a file.
- Adds line after line 2
- Adds line after line 3
- Verifies line numbers remain correct

### test-patch-delete-lines.sh
Tests deleting lines from a file.
- Deletes line 2 and line 4
- Verifies remaining lines shift up correctly

### test-patch-mixed-operations.sh
Tests a combination of edits, additions, and deletions.
- Edits line 2
- Adds new line after line 3
- Deletes line 5
- Verifies all operations are applied correctly with proper line numbering

### test-patch-multiple-edits.sh
Tests multiple consecutive line edits.
- Edits lines 2 and 3 consecutively
- Edits line 5
- Edits lines 7 and 8 consecutively
- Verifies multi-line replacement blocks work correctly

### test-patch-edge-cases.sh
Tests edge cases:
- Edit first line
- Edit last line
- Delete first line
- Delete last line

## How It Works

The patching system uses two scripts:

1. **diff-to-yaml**: Converts `git diff -U0` output to YAML format
   - `del<line>:` for deletions
   - `add<line>: |` for additions (with content block)

2. **patch-with-yaml**: Applies YAML diff to translation files
   - Processes operations in reverse order (high to low line numbers)
   - Detects del+add pairs at same line as modifications
   - Handles multi-line content blocks correctly

## Key Features

- **Reverse Order Processing**: Operations are applied from highest to lowest line number to keep line numbers stable
- **Modification Detection**: When `del<N>:` is followed by `add<N>:`, it's treated as a line replacement
- **Multi-line Support**: Add blocks can contain multiple lines for consecutive edits
- **Line Number Stability**: By processing in reverse, line numbers in the YAML remain valid throughout application
