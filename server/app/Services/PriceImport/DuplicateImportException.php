<?php

namespace App\Services\PriceImport;

use App\Models\PriceImportSession;

/**
 * Exception thrown when duplicate file import is detected.
 */
class DuplicateImportException extends \Exception
{
    protected PriceImportSession $existingSession;

    public function __construct(string $message, PriceImportSession $existingSession)
    {
        parent::__construct($message);
        $this->existingSession = $existingSession;
    }

    public function getExistingSession(): PriceImportSession
    {
        return $this->existingSession;
    }

    public function toArray(): array
    {
        return [
            'error' => 'duplicate_import',
            'message' => $this->getMessage(),
            'existing_session' => [
                'id' => $this->existingSession->id,
                'created_at' => $this->existingSession->created_at,
                'status' => $this->existingSession->status,
                'original_filename' => $this->existingSession->original_filename,
            ],
        ];
    }
}
