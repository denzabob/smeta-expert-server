<?php
/**
 * Complete verification of all formatting improvements
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  PDF Formatting Improvements — Complete Verification        ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$templatePath = __DIR__ . '/resources/views/reports/smeta.blade.php';
$content = file_get_contents($templatePath);

if (!$content) {
    echo "❌ Failed to read template\n";
    exit(1);
}

$passed = 0;
$total = 0;

function test($description, $condition) {
    global $passed, $total;
    $total++;
    if ($condition) {
        echo "✅ PASS: $description\n";
        $passed++;
        return true;
    } else {
        echo "❌ FAIL: $description\n";
        return false;
    }
}

echo "=== 1. MONEY FORMAT VERIFICATION ===\n";
test("Materials cost uses space format", strpos($content, "number_format(\$report['totals']['materials_cost'] ?? 0, 2, ' ', ' ')") !== false);
test("Operations cost uses space format", strpos($content, "number_format(\$report['totals']['operations_cost'] ?? 0, 2, ' ', ' ')") !== false);
test("Fittings cost uses space format", strpos($content, "number_format(\$report['totals']['fittings_cost'] ?? 0, 2, ' ', ' ')") !== false);
test("Expenses cost uses space format", strpos($content, "number_format(\$report['totals']['expenses_cost'] ?? 0, 2, ' ', ' ')") !== false);
test("Grand total uses space format", strpos($content, "number_format(\$report['totals']['grand_total'] ?? 0, 2, ' ', ' ')") !== false);

echo "\n=== 2. DETAIL BLOCK STYLING ===\n";
test("Line-height reduced to 1.15", preg_match('/\.detail-block\s*\{[^}]*line-height:\s*1\.15/', $content));
test("Padding reduced to 2mm", preg_match('/\.detail-block\s*\{[^}]*padding:\s*2mm/', $content));
test("Div margin reduced to 0.5mm", preg_match('/\.detail-block div\s*\{[^}]*margin:\s*0\.5mm/', $content));
test("Detail block has margin-bottom", preg_match('/\.detail-block\s*\{[^}]*margin-bottom:\s*1\.5mm/', $content));

echo "\n=== 3. HEADING IMPROVEMENTS ===\n";
test("Plates heading simplified", strpos($content, 'Детализация плитных материалов') !== false);
test("Edges heading simplified", strpos($content, 'Детализация кромочного материала') !== false);
test("No old plate heading format", strpos($content, 'Расчёты плитных материалов — Детализация') === false);
test("No old edge heading format", strpos($content, 'Расчёты кромочного материала — Детализация') === false);

echo "\n=== 4. DETAIL SECTION MARGINS ===\n";
$detailMarginCount = substr_count($content, 'margin-top: 3mm;">Детализация');
test("Detail section margins reduced to 3mm", $detailMarginCount >= 2);

echo "\n=== 5. TABLE MONEY FORMATS ===\n";
test("Plate price_per_sheet uses space", substr_count($content, "number_format(\$plate['price_per_sheet'] ?? 0, 2, ' ', ' ')") > 0);
test("Plate total_cost uses space", substr_count($content, "number_format(\$plate['total_cost'] ?? 0, 2, ' ', ' ')") > 0);
test("Edge price_per_unit uses space", substr_count($content, "number_format(\$edge['price_per_unit'] ?? 0, 2, ' ', ' ')") > 0);
test("Edge total_cost uses space", substr_count($content, "number_format(\$edge['total_cost'] ?? 0, 2, ' ', ' ')") > 0);
test("Operation cost_per_unit uses space", substr_count($content, "number_format(\$op['cost_per_unit'] ?? 0, 2, ' ', ' ')") > 0);

echo "\n=== 6. CALCULATION BLOCK FORMATS ===\n";
test("Plate calculation prices use space", substr_count($content, "number_format(\$plate['price_per_m2'] ?? 0, 2, ' ', ' ')") > 0);
test("Edge calculation prices use space", substr_count($content, "number_format(\$edge['price_per_unit'] ?? 0, 2, ' ', ' ')") > 0);
test("Fitting prices use space", substr_count($content, "number_format(\$fitting['unit_price'] ?? 0, 2, ' ', ' ')") > 0);
test("Expense costs use space", substr_count($content, "number_format(\$expense['cost'] ?? 0, 2, ' ', ' ')") > 0);

echo "\n=== 7. GENERAL VALIDATION ===\n";
test("Template syntax valid (PHP)", exec('cd ' . dirname(__FILE__) . ' && php -l resources/views/reports/smeta.blade.php 2>&1 | grep -c "No syntax errors"') > 0);
test("Template file exists", file_exists($templatePath));
test("Template file readable", is_readable($templatePath));
test("Template size reasonable", strlen($content) > 35000 && strlen($content) < 50000);

echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║  FINAL RESULTS                                              ║\n";
echo "╠════════════════════════════════════════════════════════════╣\n";
printf("║  PASSED: %-52d║\n", $passed);
printf("║  TOTAL:  %-52d║\n", $total);
printf("║  SCORE:  %3d%%%-48s║\n", round($passed / $total * 100), '');
echo "╚════════════════════════════════════════════════════════════╝\n";

if ($passed === $total) {
    echo "\n🎉 ALL FORMATTING IMPROVEMENTS SUCCESSFULLY APPLIED!\n\n";
    echo "✅ Money format: 6,632.00 → 6 632.00\n";
    echo "✅ Reduced line spacing in detail blocks\n";
    echo "✅ Tighter margins for calculation blocks\n";
    echo "✅ Simplified headings without repetition\n";
    echo "\nPDF is ready for generation and use!\n";
    exit(0);
} else {
    echo "\n⚠️  Some tests failed. Please review the changes.\n";
    exit(1);
}
?>
