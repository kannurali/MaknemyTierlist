#!/usr/bin/env bash
# Run the full PHP unit suite.
# PHP binary via $PHP (default: `php` on PATH; on XAMPP use PHP=/c/xampp/php/php.exe).
set -e
PHP="${PHP:-php}"
for t in tests/*_test.php tests/harness_selfcheck.php; do
  echo "== $t =="
  "$PHP" "$t"
done
echo "ALL UNIT TESTS PASSED"
