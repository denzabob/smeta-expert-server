#!/bin/bash
# Quick verification script for SmetaCalculator integration

echo "═══════════════════════════════════════════════════════════════"
echo "SMETA CALCULATOR BACKEND INTEGRATION VERIFICATION"
echo "═══════════════════════════════════════════════════════════════"
echo ""

# Check files exist
echo "📁 Checking created files..."
echo ""

FILES=(
  "server/app/Services/Smeta/SmetaCalculator.php"
  "server/app/Dto/PlateAggregateDto.php"
  "server/app/Dto/EdgeAggregateDto.php"
)

for file in "${FILES[@]}"; do
  if [ -f "$file" ]; then
    size=$(wc -c < "$file")
    lines=$(wc -l < "$file")
    echo "  ✓ $file"
    echo "    └─ $lines lines, $size bytes"
  else
    echo "  ✗ $file (NOT FOUND)"
  fi
done

echo ""
echo "📝 Documentation files..."
echo ""

DOCS=(
  "BACKEND_CALCULATOR_COMPLETE.md"
  "CALCULATION_FORMULAS.md"
  "CALCULATOR_INTEGRATION_SUMMARY.md"
)

for doc in "${DOCS[@]}"; do
  if [ -f "$doc" ]; then
    lines=$(wc -l < "$doc")
    echo "  ✓ $doc ($lines lines)"
  else
    echo "  ✗ $doc (NOT FOUND)"
  fi
done

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "Test Commands:"
echo "═══════════════════════════════════════════════════════════════"
echo ""
echo "1. Generate token:"
echo "   docker exec smeta_app php /var/www/html/scripts/get_token.php 2"
echo ""
echo "2. Run integration test:"
echo "   docker exec smeta_app php /var/www/html/test_calculator_integration.php"
echo ""
echo "3. Test API endpoint:"
echo "   curl -H 'Authorization: Bearer {TOKEN}' \\  
        http://localhost:8000/api/smeta/report/5"
echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "✅ Verification Complete"
echo "═══════════════════════════════════════════════════════════════"
