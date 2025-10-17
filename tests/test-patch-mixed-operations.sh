#!/usr/bin/env bash
# Test: Mixed operations (edit, add, delete)

set -e
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR/.."

echo "=== Test: Mixed Operations ==="

# Create test files
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
Line 4
Line 5
Line 6
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 1
Line 2 EDITED
Line 3
NEW Line after 3
Line 4
Line 6
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
Línea 4
Línea 5
Línea 6
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
Line 2 EDITED
Línea 3
NEW Line after 3
Línea 4
Línea 6
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
