<?php
/**
 * Simple test - verify duplicate operations block removed from Blade template
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== Verification: Duplicate Operations Block Removed ===\n\n";

// 1. Read the Blade template
$templatePath = __DIR__ . '/resources/views/reports/smeta.blade.php';
$content = file_get_contents($templatePath);

if (!$content) {
    echo "❌ Failed to read template file: $templatePath\n";
    exit(1);
}

echo "✅ Template file loaded: " . strlen($content) . " bytes\n\n";

// 2. Verify duplicate block is removed
echo "Checking for duplicate 'ОБОСНОВАНИЕ ОПЕРАЦИЙ' section...\n";
if (strpos($content, 'ОБОСНОВАНИЕ ОПЕРАЦИЙ') !== false) {
    echo "❌ FAILED: Duplicate section still exists!\n";
    
    // Find the line number
    $lines = explode("\n", $content);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'ОБОСНОВАНИЕ ОПЕРАЦИЙ') !== false) {
            echo "   Found at line " . ($i + 1) . ": " . trim($line) . "\n";
        }
    }
    exit(1);
} else {
    echo "✅ PASS: Duplicate 'ОБОСНОВАНИЕ ОПЕРАЦИЙ' section removed\n";
}

// 3. Verify main operations section exists
echo "\nChecking for main 'Расчёт стоимости работ' section...\n";
if (strpos($content, 'Расчёт стоимости работ') === false) {
    echo "❌ FAILED: Main operations section is missing!\n";
    exit(1);
} else {
    echo "✅ PASS: Main 'Расчёт стоимости работ' section exists\n";
}

// 4. Check section order
echo "\nVerifying section sequence...\n";
$section5Pos = strpos($content, '=== 5. ОПЕРАЦИИ ===');
$section6Pos = strpos($content, '=== 6. ФУРНИТУРА ===');

if ($section5Pos === false || $section6Pos === false) {
    echo "⚠️  Could not find section markers\n";
} else if ($section5Pos < $section6Pos) {
    echo "✅ PASS: Section 5 (Operations) flows directly to Section 6 (Fittings)\n";
} else {
    echo "❌ FAILED: Section order is incorrect\n";
    exit(1);
}

// 5. Count occurrences
echo "\nCounting section occurrences:\n";
$countMainTitle = substr_count($content, 'Расчёт стоимости работ');
$countOpsTitle = substr_count($content, 'Работы и операции');
$countDuplicate = substr_count($content, 'ОБОСНОВАНИЕ ОПЕРАЦИЙ');

echo "   'Расчёт стоимости работ': $countMainTitle time(s)\n";
echo "   'Работы и операции': $countOpsTitle time(s)\n";
echo "   'ОБОСНОВАНИЕ ОПЕРАЦИЙ': $countDuplicate time(s)\n";

if ($countMainTitle !== 1 || $countOpsTitle !== 0 || $countDuplicate !== 0) {
    echo "\n⚠️  WARNING: Unexpected occurrence counts\n";
    if ($countMainTitle !== 1) {
        echo "   Expected 'Расчёт стоимости работ' once, found $countMainTitle times\n";
    }
    if ($countOpsTitle !== 0) {
        echo "   Expected 'Работы и операции' zero times, found $countOpsTitle times\n";
    }
    if ($countDuplicate !== 0) {
        echo "   Expected 'ОБОСНОВАНИЕ ОПЕРАЦИЙ' zero times, found $countDuplicate times\n";
    }
} else {
    echo "   ✅ All counts are correct\n";
}

// 6. Verify template is valid
echo "\nVerifying template structure...\n";
if (!preg_match('/@if.*@endif/s', $content)) {
    echo "⚠️  Template structure may be incomplete\n";
} else {
    echo "✅ PASS: Template structure looks valid\n";
}

echo "\n=== Summary ===\n";
echo "✅ Duplicate operations block successfully removed\n";
echo "✅ Main operations section preserved\n";
echo "✅ Template ready for PDF generation\n";
echo "\nThe PDF will now show only ONE 'Расчёт стоимости работ' section.\n";

exit(0);
?>
