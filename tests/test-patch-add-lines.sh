#!/usr/bin/env bash
# Test: Adding new lines

set -e
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR/.."

echo "=== Test: Adding New Lines ==="

# Create test files
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 1
Line 2
NEW Line 2.5
Line 3
NEW Line 3.5
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
EOF

# Generate diff and convert to YAML
echo "Generating diff..."
diff -U0 /tmp/test_original.txt /tmp/test_modified.txt > /tmp/test.diff || true
cat /tmp/test.diff

echo ""
echo "Converting to YAML..."
cat /tmp/test.diff | ./bin/diff-to-yaml > /tmp/test.yaml
cat /tmp/test.yaml

echo ""
echo "Applying patch..."
./bin/patch-with-yaml /tmp/test_translation.txt /tmp/test.yaml > /tmp/test_result.txt

echo ""
echo "=== EXPECTED RESULT ==="
cat > /tmp/test_expected.txt << 'EOF'
Línea 1
Línea 2
NEW Line 2.5
Línea 3
NEW Line 3.5
EOF
cat -n /tmp/test_expected.txt

echo ""
echo "=== ACTUAL RESULT ==="
cat -n /tmp/test_result.txt

echo ""
echo "=== COMPARISON ==="
if diff -u /tmp/test_expected.txt /tmp/test_result.txt; then
    echo "✅ TEST PASSED"
    exit 0
else
    echo "❌ TEST FAILED"
    exit 1
fi
