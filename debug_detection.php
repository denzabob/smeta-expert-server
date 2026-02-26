<?php
$html = '<html><head><title>Product</title></head><body><h1>Test Product</h1><p>This is a normal page with content that should be parsed. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.</p></body></html>';

function detectBlockedAccess(string $html): array {
    $htmlLength = strlen($html);
    echo "HTML Length: $htmlLength\n";
    
    // Сначала проверяем типовые защиты независимо от размера
    if (preg_match('/cloudflare|bot\s*check|challenge/i', $html)) {
        echo "Matched: cloudflare/bot/challenge\n";
        return ['blocked' => true, 'reason' => 'cloudflare_protection'];
    }
    
    if (preg_match('/location\.reload|document\.cookie.*expires/i', $html)) {
        echo "Matched: location.reload\n";
        return ['blocked' => true, 'reason' => 'redirect_protection'];
    }
    
    if (preg_match('/access\s*denied|forbidden.*403|403.*forbidden|blocked.*access/i', $html)) {
        echo "Matched: access denied/forbidden\n";
        return ['blocked' => true, 'reason' => 'forbidden'];
    }
    
    echo "Passed text checks\n";
    
    // Если HTML очень короткий
    if ($htmlLength < 256) {
        echo "HTML too short: $htmlLength < 256\n";
        return ['blocked' => true, 'reason' => 'empty_response'];
    }
    
    echo "Passed size check\n";
    
    // Проверяем наличие реального контента в body
    if (preg_match('/<body[^>]*>(.+?)<\/body>/is', $html, $matches)) {
        echo "Found body\n";
        $bodyContent = $matches[1];
        $cleanContent = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $bodyContent);
        $cleanContent = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $cleanContent);
        $cleanContent = preg_replace('/<[^>]+>/', '', $cleanContent);
        $cleanContent = trim(preg_replace('/\s+/', ' ', $cleanContent));
        
        echo "Clean content length: " . strlen($cleanContent) . "\n";
        echo "Content: $cleanContent\n";
        
        if (strlen($cleanContent) < 30) {
            echo "Content too short\n";
            return ['blocked' => true, 'reason' => 'no_body_content'];
        }
    } else {
        echo "No body found\n";
        return ['blocked' => true, 'reason' => 'invalid_html'];
    }
    
    return ['blocked' => false];
}

echo "=== Testing ===\n";
$result = detectBlockedAccess($html);
echo "Result: " . ($result['blocked'] ? 'BLOCKED' : 'OK') . "\n";
if ($result['blocked']) {
    echo "Reason: " . $result['reason'] . "\n";
}
?>
