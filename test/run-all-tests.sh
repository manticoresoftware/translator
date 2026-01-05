#!/usr/bin/env bash

# Comprehensive test script for the translation system
# Runs all tests from scratch: removes cache, translations, and tests each scenario
# Usage: ./run-all-tests.sh [test_number]
#   If test_number is provided, only that specific test will run
# Ensure OPENROUTER_TRANSLATOR_API_KEY is set (env var or .env at project root).

TEST_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
TRANSLATOR_DIR="$( cd "$TEST_DIR/.." && pwd )"
cd "$TRANSLATOR_DIR"

# Check if a specific test number was provided
SPECIFIC_TEST="${1:-}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counters
TESTS_PASSED=0
TESTS_FAILED=0
FAILED_TESTS=()

# Helper functions
pass() {
    echo -e "${GREEN}✓ PASSED${NC}: $1"
    ((TESTS_PASSED++))
}

fail() {
    echo -e "${RED}✗ FAILED${NC}: $1"
    ((TESTS_FAILED++))
    FAILED_TESTS+=("$1")
}

check_line_counts() {
    local file="$1"
    local eng_lines=$(wc -l < "content/english/$file" 2>/dev/null || echo "0")
    local rus_lines=$(wc -l < "content/russian/$file" 2>/dev/null || echo "0")
    
    if [ "$eng_lines" = "$rus_lines" ]; then
        return 0
    else
        echo "  Line count mismatch: English=$eng_lines, Russian=$rus_lines"
        return 1
    fi
}

check_file_exists() {
    local file="$1"
    if [ -f "content/russian/$file" ]; then
        return 0
    else
        return 1
    fi
}

check_file_not_exists() {
    local file="$1"
    if [ ! -f "content/russian/$file" ]; then
        return 0
    else
        return 1
    fi
}

check_comment_preserved() {
    local file="$1"
    local comment="$2"
    if grep -qF "$comment" "content/russian/$file" 2>/dev/null; then
        return 0
    else
        return 1
    fi
}

# Check that YAML front matter keys are preserved (not translated)
check_yaml_keys_preserved() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Extract YAML front matter keys from English file
    local eng_keys=$(sed -n '/^---$/,/^---$/p' "$eng_file" 2>/dev/null | grep -E '^[a-zA-Z_][a-zA-Z0-9_]*:' | cut -d: -f1 | sort)
    # Extract YAML front matter keys from Russian file
    local rus_keys=$(sed -n '/^---$/,/^---$/p' "$rus_file" 2>/dev/null | grep -E '^[a-zA-Z_][a-zA-Z0-9_]*:' | cut -d: -f1 | sort)
    
    if [ "$eng_keys" = "$rus_keys" ]; then
        return 0
    else
        echo "  YAML keys mismatch:"
        echo "    English: $eng_keys"
        echo "    Russian: $rus_keys"
        return 1
    fi
}

# Check that YAML front matter exists and has specific key-value pair
check_yaml_key_value() {
	local file="$1"
	local key="$2"
	local value="$3"
	local target_file="content/russian/$file"
	
	if [ ! -f "$target_file" ]; then
		return 1
	fi
	
	# Extract YAML front matter
	local yaml_section=$(sed -n '/^---$/,/^---$/p' "$target_file" 2>/dev/null)
	
	# Escape special regex characters in value
	local escaped_value=$(printf '%s\n' "$value" | sed 's/[[\.*^$()+?{|]/\\&/g')
	
	# Check for simple key-value pairs (e.g., "title: Test Document" or "author: New Author")
	# Match key: followed by optional spaces and the exact value
	if echo "$yaml_section" | grep -qE "^${key}:[[:space:]]*${escaped_value}$"; then
		return 0
	fi
	
	# Check for list items under a key (e.g., tags: - value)
	if echo "$yaml_section" | grep -A 20 "^${key}:" | grep -qE "^[[:space:]]+-[[:space:]]*${escaped_value}$"; then
		return 0
	fi
	
	# Check for standalone list items
	if echo "$yaml_section" | grep -qE "^[[:space:]]+-[[:space:]]*${escaped_value}$"; then
		return 0
	fi
	
	return 1
}

# Check that YAML key values are NOT translated (for keys that should be skipped)
# This function works for single-line values. For multi-line blocks, use grep checks directly.
check_yaml_key_value_not_translated() {
	local file="$1"
	local key="$2"
	local original_value="$3"
	local eng_file="content/english/$file"
	local target_file="content/russian/$file"
	
	if [ ! -f "$eng_file" ] || [ ! -f "$target_file" ]; then
		return 1
	fi
	
	# Extract YAML front matter from both files
	local eng_yaml=$(sed -n '/^---$/,/^---$/p' "$eng_file" 2>/dev/null)
	local tgt_yaml=$(sed -n '/^---$/,/^---$/p' "$target_file" 2>/dev/null)
	
	# Check if key exists in both files
	if ! echo "$eng_yaml" | grep -qE "^${key}:"; then
		echo "  YAML key '${key}' not found in English file"
		return 1
	fi
	
	if ! echo "$tgt_yaml" | grep -qE "^${key}:"; then
		echo "  YAML key '${key}' not found in target file"
		return 1
	fi
	
	# Get the value line from English file
	local eng_line=$(echo "$eng_yaml" | grep -E "^${key}:" | head -1)
	
	# Check if it's a multi-line block (ends with | or >)
	if echo "$eng_line" | grep -qE "[|>][[:space:]]*$"; then
		# For multi-line blocks, we'll check that the original value string appears in the target
		# This is a simpler check - the full block comparison is done via grep in the test
		if grep -qF "$original_value" "$target_file" 2>/dev/null; then
			return 0
		else
			echo "  YAML key '${key}' block value appears to be translated (original value not found)"
			return 1
		fi
	else
		# Single-line value
		local eng_value=$(echo "$eng_line" | sed "s/^${key}:[[:space:]]*//" | sed 's/[[:space:]]*$//')
		
		# Get the value line from target file
		local tgt_line=$(echo "$tgt_yaml" | grep -E "^${key}:" | head -1)
		local tgt_value=$(echo "$tgt_line" | sed "s/^${key}:[[:space:]]*//" | sed 's/[[:space:]]*$//')
		
		# Remove quotes if present
		eng_value=$(echo "$eng_value" | sed 's/^["'\'']//' | sed 's/["'\'']$//')
		tgt_value=$(echo "$tgt_value" | sed 's/^["'\'']//' | sed 's/["'\'']$//')
		
		# Values should be identical (not translated)
		if [ "$eng_value" = "$tgt_value" ] && [ -n "$eng_value" ]; then
			return 0
		else
			echo "  YAML key '${key}' value was translated:"
			echo "    English: '$eng_value'"
			echo "    Target:  '$tgt_value'"
			return 1
		fi
	fi
}

# Check that YAML front matter structure is preserved (same number of lines, same --- markers)
check_yaml_structure() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Count YAML front matter lines (between --- markers)
    local eng_yaml_lines=$(sed -n '/^---$/,/^---$/p' "$eng_file" 2>/dev/null | wc -l)
    local rus_yaml_lines=$(sed -n '/^---$/,/^---$/p' "$rus_file" 2>/dev/null | wc -l)
    
    if [ "$eng_yaml_lines" = "$rus_yaml_lines" ]; then
        return 0
    else
        echo "  YAML structure mismatch: English has $eng_yaml_lines lines, Russian has $rus_yaml_lines lines"
        return 1
    fi
}

# Check that list item counts match (lines starting with "- ")
check_list_item_count_match() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"

    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi

    local eng_count=$(grep -cE '^[[:space:]]+- ' "$eng_file" 2>/dev/null || echo "0")
    local rus_count=$(grep -cE '^[[:space:]]+- ' "$rus_file" 2>/dev/null || echo "0")

    if [ "$eng_count" = "$rus_count" ]; then
        return 0
    else
        echo "  List item count mismatch: English=$eng_count, Russian=$rus_count"
        return 1
    fi
}

# Check that line positions match for code blocks (```)
check_code_block_positions() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Get line numbers with ``` in English
    local eng_lines=$(grep -n '```' "$eng_file" 2>/dev/null | cut -d: -f1 | sort -n)
    # Get line numbers with ``` in Russian
    local rus_lines=$(grep -n '```' "$rus_file" 2>/dev/null | cut -d: -f1 | sort -n)
    
    if [ "$eng_lines" = "$rus_lines" ]; then
        return 0
    else
        echo "  Code block positions mismatch:"
        echo "    English: $eng_lines"
        echo "    Russian: $rus_lines"
        return 1
    fi
}

# Check that empty/non-empty line alignment matches (every line should have same empty/non-empty status)
check_empty_nonempty_alignment() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Read both files into arrays
    mapfile -t eng_lines < "$eng_file"
    mapfile -t rus_lines < "$rus_file"
    
    # Check line counts match
    if [ ${#eng_lines[@]} -ne ${#rus_lines[@]} ]; then
        echo "  Line count mismatch for empty/non-empty alignment check"
        return 1
    fi
    
    local mismatches=()
    for i in "${!eng_lines[@]}"; do
        local eng_is_empty=0
        local rus_is_empty=0
        
        # Check if English line is empty
        if [[ -z "${eng_lines[$i]}" ]] || [[ "${eng_lines[$i]}" =~ ^[[:space:]]*$ ]]; then
            eng_is_empty=1
        fi
        
        # Check if Russian line is empty
        if [[ -z "${rus_lines[$i]}" ]] || [[ "${rus_lines[$i]}" =~ ^[[:space:]]*$ ]]; then
            rus_is_empty=1
        fi
        
        # If empty/non-empty status doesn't match, record the mismatch
        if [ "$eng_is_empty" -ne "$rus_is_empty" ]; then
            mismatches+=("$((i + 1))")
        fi
    done
    
    if [ ${#mismatches[@]} -eq 0 ]; then
        return 0
    else
        echo "  empty/non-empty line alignment mismatch at lines: ${mismatches[*]}"
        return 1
    fi
}

check_link_urls_preserved() {
    local file="$1"
    local lang="$2"
    local eng_file="content/english/$file"
    local target_file="content/${lang}/$file"

    if [ ! -f "$eng_file" ] || [ ! -f "$target_file" ]; then
        return 1
    fi

    python3 - "$eng_file" "$target_file" << 'PY'
import re
import sys

eng_path, tgt_path = sys.argv[1], sys.argv[2]
pattern = re.compile(r"(?<!\!)\[[^\]]+\]\(([^)]+)\)")

with open(eng_path, "r", encoding="utf-8") as f:
    eng_lines = f.read().splitlines()
with open(tgt_path, "r", encoding="utf-8") as f:
    tgt_lines = f.read().splitlines()

max_len = max(len(eng_lines), len(tgt_lines))
for i in range(max_len):
    eng_line = eng_lines[i] if i < len(eng_lines) else ""
    tgt_line = tgt_lines[i] if i < len(tgt_lines) else ""
    eng_matches = list(pattern.finditer(eng_line))
    if not eng_matches:
        continue
    tgt_matches = list(pattern.finditer(tgt_line))
    eng_urls = [m.group(1) for m in eng_matches]
    tgt_urls = [m.group(1) for m in tgt_matches]
    if eng_urls != tgt_urls:
        print(f"  Link URL mismatch on line {i+1}:")
        print(f"    English: {eng_line}")
        print(f"    Target:  {tgt_line}")
        sys.exit(1)

sys.exit(0)
PY
}

# Detect untranslated chunks (English copied into target)
check_untranslated_detected() {
    local file="$1"
    local output
    output=$("$TRANSLATOR_DIR/bin/auto-translate" -c . "content/english/$file" 2>/dev/null)
    echo "$output" | grep -q "rule=untranslated"
}

check_untranslated_not_detected() {
    local file="$1"
    local output
    output=$("$TRANSLATOR_DIR/bin/auto-translate" -c . "content/english/$file" 2>/dev/null)
    ! echo "$output" | grep -q "rule=untranslated"
}

# Ensure list-prefixed fenced code blocks are treated as code blocks
check_list_fence_codeblocks() {
    local file="$1"
    php -r "require '${TRANSLATOR_DIR}/vendor/autoload.php'; \$c=file_get_contents('${TRANSLATOR_DIR}/content/english/${file}'); \$ch=new Translator\\Chunker(); \$ex=\$ch->extractCodeBlocks(\$c); exit(strpos(\$ex['content'], 'CODE_BLOCK_0')!==false ? 0 : 1);"
}

# Check that line positions match for HTML comments (<!-- -->)
check_comment_positions() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Get line numbers with <!-- in English
    local eng_lines=$(grep -n '<!--' "$eng_file" 2>/dev/null | cut -d: -f1 | sort -n)
    # Get line numbers with <!-- in Russian
    local rus_lines=$(grep -n '<!--' "$rus_file" 2>/dev/null | cut -d: -f1 | sort -n)
    
    if [ "$eng_lines" = "$rus_lines" ]; then
        return 0
    else
        echo "  HTML comment positions mismatch:"
        echo "    English: $eng_lines"
        echo "    Russian: $rus_lines"
        return 1
    fi
}

# Check that empty line positions match
check_empty_line_positions() {
    local file="$1"
    local eng_file="content/english/$file"
    local rus_file="content/russian/$file"
    
    if [ ! -f "$eng_file" ] || [ ! -f "$rus_file" ]; then
        return 1
    fi
    
    # Get line numbers that are empty (or whitespace-only) in English
    local eng_lines=""
    local line_num=0
    while IFS= read -r line || [ -n "$line" ]; do
        ((line_num++))
        if [[ -z "$line" ]] || [[ "$line" =~ ^[[:space:]]*$ ]]; then
            if [ -z "$eng_lines" ]; then
                eng_lines="$line_num"
            else
                eng_lines="$eng_lines $line_num"
            fi
        fi
    done < "$eng_file"
    
    # Get line numbers that are empty (or whitespace-only) in Russian
    local rus_lines=""
    line_num=0
    while IFS= read -r line || [ -n "$line" ]; do
        ((line_num++))
        if [[ -z "$line" ]] || [[ "$line" =~ ^[[:space:]]*$ ]]; then
            if [ -z "$rus_lines" ]; then
                rus_lines="$line_num"
            else
                rus_lines="$rus_lines $line_num"
            fi
        fi
    done < "$rus_file"
    
    if [ "$eng_lines" = "$rus_lines" ]; then
        return 0
    else
        echo "  Empty line positions mismatch:"
        echo "    English: $eng_lines"
        echo "    Russian: $rus_lines"
        return 1
    fi
}

# Comprehensive check: line counts + code blocks + comments + empty lines
check_all_structure() {
    local file="$1"
    local all_ok=true
    
    if ! check_line_counts "$file"; then
        all_ok=false
    fi
    
    if ! check_code_block_positions "$file"; then
        all_ok=false
    fi
    
    if ! check_comment_positions "$file"; then
        all_ok=false
    fi
    
    if ! check_empty_line_positions "$file"; then
        all_ok=false
    fi
    
    if [ "$all_ok" = true ]; then
        return 0
    else
        return 1
    fi
}

# Cleanup function
cleanup() {
    echo "=== Cleaning up test environment ==="
    rm -rf .translation-cache
    rm -rf content/english/*
    rm -rf content/russian/*
    rm -rf content/chinese/*
    echo "Cache and translations removed"
}

# Run translation (suppress output but capture errors)
run_translation() {
    if "$TRANSLATOR_DIR/bin/auto-translate" . > /tmp/translation-output.log 2>&1; then
        return 0
    else
        echo "  Translation failed - check /tmp/translation-output.log"
        return 1
    fi
}

echo "=========================================="
echo "Translation System - Complete Test Suite"
echo "=========================================="
echo ""

# Clean start
cleanup

# Create base test files
echo "=== Setting up test files ==="
mkdir -p content/english content/russian

cat > content/english/test1.md << 'EOF'
# Test Document One

This is the first test document.

## Section One

Some content here.

## Section Two

More content.
EOF

cat > content/english/test2.md << 'EOF'
# Test Document Two

This document has code blocks.

```python
def hello():
    print("Hello")
```

And some more text.
EOF

echo "Test files created"
echo ""

# TEST 1: New Document Appears
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "1" ]; then
    echo "=== TEST 1: New Document Appears ==="
    if run_translation && check_file_exists "test1.md" && check_all_structure "test1.md"; then
        pass "TEST 1: New document translated"
    else
        fail "TEST 1: New document translation"
    fi
    echo ""
fi

# TEST 2: One Line Changes
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "2" ]; then
    echo "=== TEST 2: One Line Changes ==="
    sed -i '' 's/Some content here/Modified content here/' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 2: One line change"
    else
        fail "TEST 2: One line change"
    fi
    echo ""
fi

# TEST 3: Two Lines Change
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "3" ]; then
    echo "=== TEST 3: Two Lines Change ==="
    sed -i '' '3s/.*/Modified line 3/' content/english/test1.md
    sed -i '' '5s/.*/Modified line 5/' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 3: Two lines change"
    else
        fail "TEST 3: Two lines change"
    fi
    echo ""
fi

# TEST 4: Empty Line Added
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "4" ]; then
    echo "=== TEST 4: Empty Line Added ==="
    sed -i '' '8a\
' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 4: Empty line added"
    else
        fail "TEST 4: Empty line added"
    fi
    echo ""
fi

# TEST 5: Two Empty Lines Added
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "5" ]; then
    echo "=== TEST 5: Two Empty Lines Added ==="
    sed -i '' '10a\
\
' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 5: Two empty lines added"
    else
        fail "TEST 5: Two empty lines added"
    fi
    echo ""
fi

# TEST 6: Empty Line Removed
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "6" ]; then
    echo "=== TEST 6: Empty Line Removed ==="
    sed -i '' '8d' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 6: Empty line removed"
    else
        fail "TEST 6: Empty line removed"
    fi
    echo ""
fi

# TEST 7: Document Deleted
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "7" ]; then
    echo "=== TEST 7: Document Deleted ==="
    rm -f content/english/test2.md
    if run_translation && check_file_not_exists "test2.md"; then
        pass "TEST 7: Document deletion"
    else
        fail "TEST 7: Document deletion"
    fi
    echo ""
fi

# TEST 8: Translated File Disappears
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "8" ]; then
    echo "=== TEST 8: Translated File Disappears ==="
    rm -f content/russian/test1.md
    if run_translation && check_file_exists "test1.md" && check_all_structure "test1.md"; then
        pass "TEST 8: Translation file recreation"
    else
        fail "TEST 8: Translation file recreation"
    fi
    echo ""
fi

# TEST 9: Add Empty Lines to End
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "9" ]; then
    echo "=== TEST 9: Add Empty Lines to End ==="
    echo "" >> content/english/test1.md
    echo "" >> content/english/test1.md
    echo "" >> content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 9: Empty lines at end"
    else
        fail "TEST 9: Empty lines at end"
    fi
    echo ""
fi

# TEST 10: Remove Empty Line from End
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "10" ]; then
    echo "=== TEST 10: Remove Empty Line from End ==="
    sed -i '' '$d' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 10: Remove empty line from end"
    else
        fail "TEST 10: Remove empty line from end"
    fi
    echo ""
fi

# TEST 11: Add HTML Comments
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "11" ]; then
    echo "=== TEST 11: Add HTML Comments ==="
    sed -i '' '5a\
<!-- This is a test comment -->
' content/english/test1.md
    sed -i '' '10a\
<!-- Another comment with < > & -->
' content/english/test1.md
    if run_translation && check_all_structure "test1.md" && \
       check_comment_preserved "test1.md" "<!-- This is a test comment -->" && \
       check_comment_preserved "test1.md" "<!-- Another comment with < > & -->"; then
        pass "TEST 11: HTML comments preserved"
    else
        fail "TEST 11: HTML comments preserved"
    fi
    echo ""
fi

# TEST 12: Add Line with Only Spaces
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "12" ]; then
    echo "=== TEST 12: Add Line with Only Spaces ==="
    printf "    \n" >> content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 12: Whitespace-only line"
    else
        fail "TEST 12: Whitespace-only line"
    fi
    echo ""
fi

# TEST 13: Remove Whitespace-Only Line
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "13" ]; then
    echo "=== TEST 13: Remove Whitespace-Only Line ==="
    sed -i '' '$d' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 13: Remove whitespace-only line"
    else
        fail "TEST 13: Remove whitespace-only line"
    fi
    echo ""
fi

# TEST 14: Add Empty Line to End
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "14" ]; then
    echo "=== TEST 14: Add Empty Line to End ==="
    echo "" >> content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 14: Empty line at end"
    else
        fail "TEST 14: Empty line at end"
    fi
    echo ""
fi

# TEST 15: Remove Empty Line from End
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "15" ]; then
    echo "=== TEST 15: Remove Empty Line from End ==="
    sed -i '' '$d' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 15: Remove empty line from end"
    else
        fail "TEST 15: Remove empty line from end"
    fi
    echo ""
fi

# TEST 16: Add Empty Line in Middle
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "16" ]; then
    echo "=== TEST 16: Add Empty Line in Middle ==="
    sed -i '' '8a\
' content/english/test1.md
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 16: Empty line in middle"
    else
        fail "TEST 16: Empty line in middle"
    fi
    echo ""
fi

# TEST 17: Cache Reuse
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "17" ]; then
    echo "=== TEST 17: Cache Reuse ==="
    # Run translation again without changes
    if run_translation && check_all_structure "test1.md"; then
        pass "TEST 17: Cache reuse"
    else
        fail "TEST 17: Cache reuse"
    fi
    echo ""
fi

# TEST 18: Code Block Preservation
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "18" ]; then
    echo "=== TEST 18: Code Block Preservation ==="
    cat > content/english/test-code.md << 'EOF'
# Code Test

Here's some code:

```python
def test():
    return True
```

More text.
EOF
    if run_translation && check_file_exists "test-code.md" && \
       grep -q '```python' content/russian/test-code.md && \
       grep -q 'def test():' content/russian/test-code.md && \
       check_all_structure "test-code.md"; then
        pass "TEST 18: Code block preservation"
    else
        fail "TEST 18: Code block preservation"
    fi
    echo ""
fi

# TEST 19: Add YAML Front Matter
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "19" ]; then
    echo "=== TEST 19: Add YAML Front Matter ==="
    cat > content/english/test-yaml.md << 'EOF'
---
title: Test Document
date: 2024-01-01
author: Test Author
tags:
  - test
  - yaml
---

# Test Document with YAML

This document has YAML front matter that should be preserved.

The content should be translated, but the YAML keys should remain in English.
EOF
    if run_translation && check_file_exists "test-yaml.md" && \
       check_yaml_structure "test-yaml.md" && \
       check_yaml_keys_preserved "test-yaml.md" && \
       # Check that YAML keys exist (values may be translated)
       grep -qE "^title:" content/russian/test-yaml.md && \
       grep -qE "^date:" content/russian/test-yaml.md && \
       grep -qE "^author:" content/russian/test-yaml.md && \
       grep -qE "^tags:" content/russian/test-yaml.md && \
       check_all_structure "test-yaml.md"; then
        pass "TEST 19: YAML front matter added and preserved"
    else
        fail "TEST 19: YAML front matter added and preserved"
    fi
    echo ""
fi

# TEST 20: Modify YAML Front Matter
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "20" ]; then
    echo "=== TEST 20: Modify YAML Front Matter ==="
    sed -i '' 's/date: 2024-01-01/date: 2024-12-31/' content/english/test-yaml.md
    sed -i '' 's/author: Test Author/author: New Author/' content/english/test-yaml.md
    sed -i '' '/tags:/a\
  - updated\
' content/english/test-yaml.md
    if run_translation && check_yaml_structure "test-yaml.md" && \
       check_yaml_keys_preserved "test-yaml.md" && \
       # Check that the modified values exist in English file (source of truth)
       grep -qE "^date:[[:space:]]*2024-12-31" content/english/test-yaml.md && \
       grep -qE "^author:[[:space:]]*New Author" content/english/test-yaml.md && \
       grep -qE "^- updated$|^[[:space:]]+- updated$" content/english/test-yaml.md && \
       # Check that keys exist in Russian file (values may be translated)
       grep -qE "^date:" content/russian/test-yaml.md && \
       grep -qE "^author:" content/russian/test-yaml.md && \
       grep -qE "^tags:" content/russian/test-yaml.md && \
       check_all_structure "test-yaml.md"; then
        pass "TEST 20: YAML front matter modified and preserved"
    else
        fail "TEST 20: YAML front matter modified and preserved"
    fi
    echo ""
fi

# TEST 21: YAML Front Matter with Translated Content
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "21" ]; then
    echo "=== TEST 21: YAML Front Matter with Translated Content ==="
    # Verify that content after YAML is translated while YAML keys remain unchanged
    # Check that YAML section has English keys but content section has Russian text
    yaml_section=$(sed -n '/^---$/,/^---$/p' content/russian/test-yaml.md 2>/dev/null)

    # YAML keys should be in English (check for "title:", "date:", "author:", "tags:")
    if echo "$yaml_section" | grep -qE "^(title|date|author|tags):" && \
       check_yaml_keys_preserved "test-yaml.md" && \
       check_all_structure "test-yaml.md"; then
        pass "TEST 21: Content translated while YAML keys preserved"
    else
        fail "TEST 21: Content translated while YAML keys preserved"
    fi
    echo ""
fi

# TEST 22: Real-world Complex File (file_for_test.md)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "22" ]; then
    echo "=== TEST 22: Real-world Complex File (file_for_test.md) ==="
    # Test with the actual problematic file that was causing translation issues
    if [ -f "$TEST_DIR/file_for_test.md" ]; then
        # Copy the test file to content/english
        cp "$TEST_DIR/file_for_test.md" content/english/file_for_test.md
        
        # Run translation
        if run_translation && check_file_exists "file_for_test.md" && \
           check_all_structure "file_for_test.md" && \
           check_yaml_structure "file_for_test.md" && \
           check_yaml_keys_preserved "file_for_test.md"; then
            pass "TEST 22: Complex real-world file translated successfully"
        else
            fail "TEST 22: Complex real-world file translation"
            echo "  Note: This file has 194 lines with code blocks, YAML front matter, and complex formatting"
        fi
    else
        echo "  Skipping TEST 22: file_for_test.md not found in test directory"
        fail "TEST 22: Test file not available"
    fi
    echo ""
fi

# TEST 23: Real-world Complex File (file_for_test2.md)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "23" ]; then
    echo "=== TEST 23: Real-world Complex File (file_for_test2.md) ==="
    # Test with another complex file that was causing translation issues
    if [ -f "$TEST_DIR/file_for_test2.md" ]; then
        # Copy the test file to content/english
        cp "$TEST_DIR/file_for_test2.md" content/english/file_for_test2.md
        
        # Run translation
        if run_translation && check_file_exists "file_for_test2.md" && check_all_structure "file_for_test2.md" && check_yaml_structure "file_for_test2.md" && check_yaml_keys_preserved "file_for_test2.md"; then
            pass "TEST 23: Complex real-world file (file_for_test2.md) translated successfully"
        else
            fail "TEST 23: Complex real-world file (file_for_test2.md) translation"
            echo "  Note: This file has 997 lines with many code blocks, HTML comments, and complex formatting"
        fi
    else
        echo "  Skipping TEST 23: file_for_test2.md not found in test directory"
        fail "TEST 23: Test file not available"
    fi
    echo ""
fi

# TEST 24: Link URLs must stay unchanged
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "24" ]; then
    echo "=== TEST 24: Link URLs (anchor_link_test.md) ==="
    if [ -f "$TEST_DIR/anchor_link_test.md" ]; then
        cp "$TEST_DIR/anchor_link_test.md" content/english/anchor_link_test.md
        if run_translation && check_file_exists "anchor_link_test.md" && \
           check_link_urls_preserved "anchor_link_test.md" "russian" && \
           check_link_urls_preserved "anchor_link_test.md" "chinese"; then
            pass "TEST 24: Link URLs preserved"
        else
            fail "TEST 24: Link URLs preserved"
        fi
    else
        echo "  Skipping TEST 24: anchor_link_test.md not found in test directory"
    fi
fi

# TEST 25: Untranslated chunk detection
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "25" ]; then
    echo "=== TEST 25: Untranslated chunk detection (untranslated_test.md) ==="
    cat > content/english/untranslated_test.md << 'EOF'
Manticore Search is built using cmake and the minimum gcc version required for compiling is 4.7.2.
This sentence should never remain in English after translation.
EOF
    cp content/english/untranslated_test.md content/russian/untranslated_test.md
    cp content/english/untranslated_test.md content/chinese/untranslated_test.md

    if check_untranslated_detected "untranslated_test.md"; then
        pass "TEST 25: Untranslated chunk detected"
    else
        fail "TEST 25: Untranslated chunk detected"
    fi
fi

# TEST 26: List-prefixed fenced code blocks
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "26" ]; then
    echo "=== TEST 26: List-prefixed fenced code blocks (list_fence_test.md) ==="
    cat > content/english/list_fence_test.md << 'EOF'
Here is a list:

* ```bash
  echo "hello"
  ```

Done.
EOF
    if check_list_fence_codeblocks "list_fence_test.md"; then
        pass "TEST 26: List-prefixed fenced blocks are detected"
    else
        fail "TEST 26: List-prefixed fenced blocks are detected"
    fi
fi

# TEST 27: Real-world Complex File (file_for_test4.md)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "27" ]; then
    echo "=== TEST 27: Real-world Complex File (file_for_test4.md) ==="
    # Test with another complex file that was causing translation issues
    if [ -f "$TEST_DIR/file_for_test4.md" ]; then
        # Copy the test file to content/english
        cp "$TEST_DIR/file_for_test4.md" content/english/file_for_test4.md
        
        # Run translation
        if run_translation && check_file_exists "file_for_test4.md" && \
           check_all_structure "file_for_test4.md" && \
           check_yaml_structure "file_for_test4.md" && \
           check_yaml_keys_preserved "file_for_test4.md"; then
            pass "TEST 27: Complex real-world file (file_for_test4.md) translated successfully"
        else
            fail "TEST 27: Complex real-world file (file_for_test4.md) translation"
            echo "  Note: This file contains code blocks with commands that should not be flagged as untranslated"
        fi
    else
        echo "  Skipping TEST 27: file_for_test4.md not found in test directory"
        fail "TEST 27: Test file not available"
    fi
    echo ""
fi

# TEST 28: Real-world Complex File (file_for_test5.md)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "28" ]; then
    echo "=== TEST 28: Real-world Complex File (file_for_test5.md) ==="
    # Test with file containing HTML comments within lines
    if [ -f "$TEST_DIR/file_for_test5.md" ]; then
        # Copy the test file to content/english
        cp "$TEST_DIR/file_for_test5.md" content/english/file_for_test5.md
        
        # Run translation
        if run_translation && check_file_exists "file_for_test5.md" && \
           check_all_structure "file_for_test5.md" && \
           check_yaml_structure "file_for_test5.md" && \
           check_yaml_keys_preserved "file_for_test5.md"; then
            # Check that the line with HTML comment was translated
            if grep -q "<!--{target=\"_blank\"}-->" content/russian/file_for_test5.md && \
               ! grep -q "Over 20 \[full-text operators\]" content/russian/file_for_test5.md; then
                pass "TEST 28: Complex real-world file (file_for_test5.md) translated successfully with HTML comments in lines"
            else
                fail "TEST 28: HTML comment line not properly translated"
                echo "  Note: Lines containing HTML comments should be translated while preserving the comment"
            fi
        else
            fail "TEST 28: Complex real-world file (file_for_test5.md) translation"
            echo "  Note: This file contains lines with HTML comments that should be translated"
        fi
    else
        echo "  Skipping TEST 28: file_for_test5.md not found in test directory"
        fail "TEST 28: Test file not available"
    fi
    echo ""
fi

# TEST 29: Empty/Non-Empty Line Alignment with File Replacement
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "29" ]; then
    echo "=== TEST 29: Empty/Non-Empty Line Alignment with File Replacement ==="
    # Use test files from test directory
    if [ ! -f "$TEST_DIR/test-alignment-before.md" ] || [ ! -f "$TEST_DIR/test-alignment-after.md" ]; then
        echo "  Skipping TEST 29: test-alignment-before.md or test-alignment-after.md not found in test directory"
        fail "TEST 29: Test files not available"
    else
        # Copy before.md to content/english
        cp "$TEST_DIR/test-alignment-before.md" content/english/test-alignment.md
        
        # Run initial translation
        if run_translation && check_file_exists "test-alignment.md" && \
           check_all_structure "test-alignment.md" && \
           check_code_block_positions "test-alignment.md" && \
           check_comment_positions "test-alignment.md" && \
           check_empty_line_positions "test-alignment.md" && \
           check_empty_nonempty_alignment "test-alignment.md"; then
            # Replace with after.md
            cp "$TEST_DIR/test-alignment-after.md" content/english/test-alignment.md
            
            # Run translation again
            if run_translation && check_all_structure "test-alignment.md" && \
               check_code_block_positions "test-alignment.md" && \
               check_comment_positions "test-alignment.md" && \
               check_empty_line_positions "test-alignment.md" && \
               check_empty_nonempty_alignment "test-alignment.md"; then
                pass "TEST 29: Empty/non-empty line alignment maintained after file replacement"
            else
                fail "TEST 29: Empty/non-empty line alignment failed after file replacement"
                echo "  Note: File contains HTML comments, code blocks, and markdown titles"
            fi
        else
            fail "TEST 29: Initial translation failed"
            echo "  Note: File contains HTML comments, code blocks, and markdown titles"
        fi
    fi
    echo ""
fi

# TEST 30: YAML values-only translation (yaml_values_test.md)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "30" ]; then
    echo "=== TEST 30: YAML values-only translation (yaml_values_test.md) ==="
    if [ -f "$TEST_DIR/yaml_values_test.md" ]; then
        cp "$TEST_DIR/yaml_values_test.md" content/english/yaml_values_test.md
        if run_translation \
           && check_file_exists "yaml_values_test.md" \
           && check_yaml_keys_preserved "yaml_values_test.md" \
           && check_yaml_structure "yaml_values_test.md" \
           && check_list_item_count_match "yaml_values_test.md" \
           && grep -qF "https://e-server.com.ua" "content/russian/yaml_values_test.md" \
           && grep -qF "https://github.com/manticoresoftware/manticoresearch/discussions/3633" "content/russian/yaml_values_test.md" \
           && grep -qF "badge: \"<span class=\\\"feature-icon yes\\\">✓</span>\"" "content/russian/yaml_values_test.md" \
           && grep -qE '^  - quote: ".*:.*"$' "content/russian/yaml_values_test.md"; then
            pass "TEST 30: YAML values-only translation"
        else
            fail "TEST 30: YAML values-only translation"
        fi
    else
        echo "  Skipping TEST 30: yaml_values_test.md not found in test directory"
        fail "TEST 30: Test file not available"
    fi
    echo ""
fi

# TEST 31: YAML keys to skip (yaml_keys_to_skip)
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "31" ]; then
    echo "=== TEST 31: YAML keys to skip (yaml_keys_to_skip) ==="
    cat > content/english/test-yaml-keys-skip.md << 'EOF'
---
title: "Test Document with Skipped YAML Keys"
layout: "testimonials"
code: |
  function example() {
    return "This code should not be translated";
  }
no_cta: "This should not be translated"
dev: "development mode"
url: "https://example.com/page"
description: "This description should be translated"
tags:
  - "translatable tag"
  - "another tag"
---
# Main Content

This is the main content that should be translated.
EOF

    if ! run_translation; then
        fail "TEST 31: Translation failed"
    elif ! check_file_exists "test-yaml-keys-skip.md"; then
        fail "TEST 31: Translated file not found"
    elif ! check_yaml_structure "test-yaml-keys-skip.md"; then
        fail "TEST 31: YAML structure not preserved"
    elif ! check_yaml_keys_preserved "test-yaml-keys-skip.md"; then
        fail "TEST 31: YAML keys not preserved"
    elif ! check_yaml_key_value_not_translated "test-yaml-keys-skip.md" "layout" "testimonials"; then
        fail "TEST 31: layout key value was translated"
    elif ! check_yaml_key_value_not_translated "test-yaml-keys-skip.md" "no_cta" "This should not be translated"; then
        fail "TEST 31: no_cta key value was translated"
    elif ! check_yaml_key_value_not_translated "test-yaml-keys-skip.md" "dev" "development mode"; then
        fail "TEST 31: dev key value was translated"
    elif ! check_yaml_key_value_not_translated "test-yaml-keys-skip.md" "url" "https://example.com/page"; then
        fail "TEST 31: url key value was translated"
    elif ! grep -qF 'code: |' "content/russian/test-yaml-keys-skip.md"; then
        fail "TEST 31: code key block marker not found"
    elif ! grep -qF 'function example()' "content/russian/test-yaml-keys-skip.md"; then
        fail "TEST 31: code block content was translated"
    elif ! grep -qF 'return "This code should not be translated";' "content/russian/test-yaml-keys-skip.md"; then
        fail "TEST 31: code block content was translated"
    elif grep -qF 'description: "This description should be translated"' "content/russian/test-yaml-keys-skip.md"; then
        fail "TEST 31: description key value was not translated (should be translated)"
    elif ! check_all_structure "test-yaml-keys-skip.md"; then
        fail "TEST 31: File structure validation failed"
    else
        pass "TEST 31: YAML keys to skip are not translated"
    fi
    echo ""
fi

# TEST 32: Non-translatable chunk should not trigger untranslated detection
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "32" ]; then
    echo "=== TEST 32: Non-translatable chunk does not trigger untranslated ==="
    cat > content/english/nontranslatable_chunk_test.md << 'EOF'
<!-- intro -->
##### Java:
<!-- request Java -->

```java
System.out.println("hello");
```

<!-- response Java -->

##### Rust:
<!-- request Rust -->

```rust
println!("hello");
```

<!-- response Rust -->
EOF
    cp content/english/nontranslatable_chunk_test.md content/russian/nontranslatable_chunk_test.md
    cp content/english/nontranslatable_chunk_test.md content/chinese/nontranslatable_chunk_test.md

    if check_untranslated_not_detected "nontranslatable_chunk_test.md"; then
        pass "TEST 32: Non-translatable chunk does not trigger untranslated"
    else
        fail "TEST 32: Non-translatable chunk does not trigger untranslated"
    fi
    echo ""
fi

# TEST 33: Cache backfill from existing translations when cache is missing
if [ -z "$SPECIFIC_TEST" ] || [ "$SPECIFIC_TEST" = "33" ]; then
    echo "=== TEST 33: Cache backfill from existing translations ==="
    cat > content/english/cache-backfill.md << 'EOF'
# Cache Backfill Test

Paragraph one is intentionally long to increase chunk size and force chunk splitting behavior in the source file. It should be translated, but the structure must remain identical.

Paragraph two is also long and uses different wording to ensure the translated file can be shorter while preserving line counts and blank lines.

```bash
echo "hello"
```

Paragraph three is another long sentence meant to create more bytes in the source content so chunk grouping can differ between source and target files.

Paragraph four closes the document with more long text to keep the content over the chunk limit used in this test.
EOF

    cat > content/russian/cache-backfill.md << 'EOF'
# Тест восстановления кэша

Перевод первого абзаца должен отличаться и быть короче, но структура строк и пустых строк должна совпадать.

Перевод второго абзаца также короче и с другими словами, чтобы проверить восстановление кэша из готового файла.

```bash
echo "hello"
```

Третий абзац тоже отличается и нужен только для того, чтобы совпадали позиции строк и блоков кода.

Четвертый абзац завершает файл и сохраняет структуру, необходимую для проверки.
EOF

    cat > content/chinese/cache-backfill.md << 'EOF'
# 缓存回填测试

第一段翻译应当更短，但必须保持行结构和空行位置一致。

第二段翻译也更短，用不同措辞验证从现有文件回填缓存。

```bash
echo "hello"
```

第三段同样是占位翻译，用于保持代码块位置与行号一致。

第四段结束文件，保持结构一致以便测试通过。
EOF

    rm -rf .translation-cache
    output=$(TRANSLATION_CHUNK_SIZE=200 "$TRANSLATOR_DIR/bin/auto-translate" -c . "content/english/cache-backfill.md" 2>/dev/null)
    if [ -z "$output" ] && [ -f ".translation-cache/cache-backfill.md.json" ]; then
        pass "TEST 33: Cache backfill from existing translations"
    else
        echo "  Check output: ${output:-<empty>}"
        fail "TEST 33: Cache backfill from existing translations"
    fi
    echo ""
fi

# Final Summary
echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo "Total tests: $((TESTS_PASSED + TESTS_FAILED))"
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
if [ $TESTS_FAILED -gt 0 ]; then
    echo -e "${RED}Failed: $TESTS_FAILED${NC}"
    echo ""
    echo "Failed tests:"
    for test in "${FAILED_TESTS[@]}"; do
        echo "  - $test"
    done
    exit 1
else
    echo -e "${RED}Failed: 0${NC}"
    echo ""
    echo -e "${GREEN}All tests passed!${NC}"
    exit 0
fi
