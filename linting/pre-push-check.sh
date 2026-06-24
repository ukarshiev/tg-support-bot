#!/bin/bash

# -----------------------------
# PHPStan check
# -----------------------------
echo -e "🔍 [PHPStan] Checking entire project..."
vendor/bin/phpstan analyse --error-format=table --no-progress --memory-limit=1G
if [ $? -ne 0 ]; then
    echo -e "❌ Push blocked due to PHPStan errors."
    exit 1
else
    echo -e "✅ [PHPStan] Check passed."
fi

# -----------------------------
# Laravel / Artisan tests
# -----------------------------
echo -e "🧪 Running Laravel tests (phpunit)..."
php -d memory_limit=1G vendor/bin/phpunit
if [ $? -ne 0 ]; then
    echo -e "❌ Push blocked due to failing tests."
    exit 1
else
    echo -e "✅ All tests passed."
fi

echo -e "✅ All checks passed. Push allowed."
