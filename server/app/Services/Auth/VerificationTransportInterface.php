<?php

namespace App\Services\Auth;

interface VerificationTransportInterface
{
    /**
     * Send verification code to the given phone number.
     *
    * @return array{success: bool, provider_message_id: ?string, error: ?string, meta?: array<string, mixed>|null}
     */
    public function sendCode(string $phone, string $code): array;

    /**
     * Check if this transport is available/enabled.
     */
    public function isAvailable(): bool;

    /**
     * Get transport channel name (e.g. 'telegram_gateway', 'sms_ru_callcheck').
     */
    public function channelName(): string;
}
