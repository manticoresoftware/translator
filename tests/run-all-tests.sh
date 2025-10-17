#!/usr/bin/env bash
# Run all patch tests

set -e
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

echo "=========================================="
echo "Running All Patch Tests"
echo "=========================================="
echo ""

FAILED=0
PASSED=0

run_test() {
    local test_file="$1"
    local test_name=$(basename "$test_file" .sh)
    
    echo "Running: $test_name"
    if bash "$test_file"; then
        ((PASSED++))
    else
        ((FAILED++))
    fi
    echo ""
}

# Run all tests
run_test "$SCRIPT_DIR/test-patch-simple-edit.sh"
run_test "$SCRIPT_DIR/test-patch-add-lines.sh"
run_test "$SCRIPT_DIR/test-patch-delete-lines.sh"
run_test "$SCRIPT_DIR/test-patch-mixed-operations.sh"
run_test "$SCRIPT_DIR/test-patch-multiple-edits.sh"
run_test "$SCRIPT_DIR/test-patch-edge-cases.sh"

echo "=========================================="
echo "Test Results"
echo "=========================================="
echo "Passed: $PASSED"
echo "Failed: $FAILED"
echo ""

if [ $FAILED -eq 0 ]; then
    echo "✅ ALL TESTS PASSED"
    exit 0
else
    echo "❌ SOME TESTS FAILED"
    exit 1
fi
