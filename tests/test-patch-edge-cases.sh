#!/usr/bin/env bash
# Test: Edge cases (first line, last line, empty file)

set -e
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR/.."

echo "=== Test: Edge Cases ==="

# Test 1: Edit first line
echo "--- Test 1: Edit First Line ---"
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 1 EDITED
Line 2
Line 3
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
EOF

diff -U0 /tmp/test_original.txt /tmp/test_modified.txt | ./bin/diff-to-yaml > /tmp/test.yaml || true
./bin/patch-with-yaml /tmp/test_translation.txt /tmp/test.yaml > /tmp/test_result.txt

cat > /tmp/test_expected.txt << 'EOF'
Line 1 EDITED
Línea 2
Línea 3
EOF

if diff -u /tmp/test_expected.txt /tmp/test_result.txt; then
    echo "✅ Test 1 PASSED"
else
    echo "❌ Test 1 FAILED"
    exit 1
fi

# Test 2: Edit last line
echo ""
echo "--- Test 2: Edit Last Line ---"
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 1
Line 2
Line 3 EDITED
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
EOF

diff -U0 /tmp/test_original.txt /tmp/test_modified.txt | ./bin/diff-to-yaml > /tmp/test.yaml || true
./bin/patch-with-yaml /tmp/test_translation.txt /tmp/test.yaml > /tmp/test_result.txt

cat > /tmp/test_expected.txt << 'EOF'
Línea 1
Línea 2
Line 3 EDITED
EOF

if diff -u /tmp/test_expected.txt /tmp/test_result.txt; then
    echo "✅ Test 2 PASSED"
else
    echo "❌ Test 2 FAILED"
    exit 1
fi

# Test 3: Delete first line
echo ""
echo "--- Test 3: Delete First Line ---"
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 2
Line 3
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
EOF

diff -U0 /tmp/test_original.txt /tmp/test_modified.txt | ./bin/diff-to-yaml > /tmp/test.yaml || true
./bin/patch-with-yaml /tmp/test_translation.txt /tmp/test.yaml > /tmp/test_result.txt

cat > /tmp/test_expected.txt << 'EOF'
Línea 2
Línea 3
EOF

if diff -u /tmp/test_expected.txt /tmp/test_result.txt; then
    echo "✅ Test 3 PASSED"
else
    echo "❌ Test 3 FAILED"
    exit 1
fi

# Test 4: Delete last line
echo ""
echo "--- Test 4: Delete Last Line ---"
cat > /tmp/test_original.txt << 'EOF'
Line 1
Line 2
Line 3
EOF

cat > /tmp/test_modified.txt << 'EOF'
Line 1
Line 2
EOF

cat > /tmp/test_translation.txt << 'EOF'
Línea 1
Línea 2
Línea 3
EOF

diff -U0 /tmp/test_original.txt /tmp/test_modified.txt | ./bin/diff-to-yaml > /tmp/test.yaml || true
./bin/patch-with-yaml /tmp/test_translation.txt /tmp/test.yaml > /tmp/test_result.txt

cat > /tmp/test_expected.txt << 'EOF'
Línea 1
Línea 2
EOF

if diff -u /tmp/test_expected.txt /tmp/test_result.txt; then
    echo "✅ Test 4 PASSED"
else
    echo "❌ Test 4 FAILED"
    exit 1
fi

echo ""
echo "✅ ALL EDGE CASE TESTS PASSED"
