#!/usr/bin/env bash
# Run the full unit suite: PHP for the API, node for the interface dictionary.
# PHP binary via $PHP (default: `php` on PATH; on XAMPP use PHP=/c/xampp/php/php.exe).
set -e
PHP="${PHP:-php}"
for t in tests/*_test.php tests/harness_selfcheck.php; do
  echo "== $t =="
  "$PHP" "$t"
done

# js/i18n.js is plain data + pure functions, so it runs under node with no
# browser and no dependencies. Skipped (loudly) where node is unavailable.
if command -v node >/dev/null 2>&1; then
  for t in tests/*_test.mjs; do
    echo "== $t =="
    node --test "$t"
  done
else
  echo "!! node not found - skipping tests/*_test.mjs"
fi

echo "ALL UNIT TESTS PASSED"
