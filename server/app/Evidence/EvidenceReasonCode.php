<?php

namespace App\Evidence;

final class EvidenceReasonCode
{
    public const BLOCK_CLOUDFLARE = 'BLOCK_CLOUDFLARE';
    public const BLOCK_CAPTCHA = 'BLOCK_CAPTCHA';
    public const BLOCK_403 = 'BLOCK_403';
    public const BLOCK_429 = 'BLOCK_429';
    public const HTTP_404 = 'HTTP_404';
    public const HTTP_TIMEOUT = 'HTTP_TIMEOUT';
    public const BROWSER_MISSING = 'BROWSER_MISSING';
    public const BROWSER_TIMEOUT = 'BROWSER_TIMEOUT';
    public const NON_PRODUCT_PAGE = 'NON_PRODUCT_PAGE';
    public const PRICE_NOT_FOUND = 'PRICE_NOT_FOUND';
    public const PRICE_OUTLIER = 'PRICE_OUTLIER';
    public const SCREENSHOT_EMPTY = 'SCREENSHOT_EMPTY';
    public const SCREENSHOT_BLOCK_PAGE = 'SCREENSHOT_BLOCK_PAGE';
    public const SELECTOR_MISMATCH = 'SELECTOR_MISMATCH';
    public const MANUAL_REQUIRED = 'MANUAL_REQUIRED';
    public const MANUAL_VERIFIED = 'MANUAL_VERIFIED';

    public static function all(): array
    {
        return [
            self::BLOCK_CLOUDFLARE,
            self::BLOCK_CAPTCHA,
            self::BLOCK_403,
            self::BLOCK_429,
            self::HTTP_404,
            self::HTTP_TIMEOUT,
            self::BROWSER_MISSING,
            self::BROWSER_TIMEOUT,
            self::NON_PRODUCT_PAGE,
            self::PRICE_NOT_FOUND,
            self::PRICE_OUTLIER,
            self::SCREENSHOT_EMPTY,
            self::SCREENSHOT_BLOCK_PAGE,
            self::SELECTOR_MISMATCH,
            self::MANUAL_REQUIRED,
            self::MANUAL_VERIFIED,
        ];
    }
}

