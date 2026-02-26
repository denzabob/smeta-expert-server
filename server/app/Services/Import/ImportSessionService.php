<?php

namespace App\Services\Import;

use App\Models\ImportSession;
use App\Models\Project;
use App\Models\User;
use App\Utilities\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Service for managing import sessions.
 */
class ImportSessionService
{
    /**
     * Create a new import session from an uploaded file.
     *
     * @param UploadedFile $file The uploaded file
     * @param User $user The user performing the import
     * @param Project|null $project The target project (if importing to a project)
     * @param array $options Additional options (sheet_index, header_row_index, csv options)
     * @return ImportSession
     */
    public function createFromUpload(
        UploadedFile $file,
        User $user,
        ?Project $project = null,
        array $options = []
    ): ImportSession {
        // Validate file type
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xlsx', 'xls', 'csv'])) {
            throw new RuntimeException('Unsupported file type. Allowed: xlsx, xls, csv');
        }

        // Generate a unique path for storage
        $fileName = sprintf(
            '%s_%s.%s',
            $user->id,
            uniqid(),
            $extension
        );
        $storagePath = 'imports/' . date('Y/m') . '/' . $fileName;

        // Store the file
        $disk = 'local';
        Storage::disk($disk)->put($storagePath, file_get_contents($file->getRealPath()));

        // Create the session
        $session = ImportSession::create([
            'user_id' => $user->id,
            'project_id' => $project?->id,
            'file_path' => $storagePath,
            'storage_disk' => $disk,
            'original_filename' => $file->getClientOriginalName(),
            'file_type' => $extension,
            'status' => ImportSession::STATUS_UPLOADED,
            'header_row_index' => $options['header_row_index'] ?? 0,
            'sheet_index' => $options['sheet_index'] ?? 0,
            'options' => [
                'csv_encoding' => $options['csv_encoding'] ?? 'UTF-8',
                'csv_delimiter' => $options['csv_delimiter'] ?? ',',
                'units_length' => $options['units_length'] ?? 'mm',
                'default_qty_if_empty' => $options['default_qty_if_empty'] ?? 1,
                'skip_empty_rows' => $options['skip_empty_rows'] ?? true,
            ],
        ]);

        return $session;
    }

    /**
     * Update session options.
     *
     * @param ImportSession $session The session to update
     * @param array $options Options to update
     * @return ImportSession
     */
    public function updateOptions(ImportSession $session, array $options): ImportSession
    {
        $currentOptions = $session->options ?? [];

        // Update header row and sheet index if provided
        if (isset($options['header_row_index'])) {
            $session->header_row_index = $options['header_row_index'];
        }
        if (isset($options['sheet_index'])) {
            $session->sheet_index = $options['sheet_index'];
        }

        // Merge options
        $session->options = array_merge($currentOptions, array_filter([
            'csv_encoding' => $options['csv_encoding'] ?? null,
            'csv_delimiter' => $options['csv_delimiter'] ?? null,
            'units_length' => $options['units_length'] ?? null,
            'default_qty_if_empty' => $options['default_qty_if_empty'] ?? null,
            'skip_empty_rows' => $options['skip_empty_rows'] ?? null,
        ], fn($v) => $v !== null));

        $session->save();

        return $session;
    }

    /**
     * Update session status.
     *
     * @param ImportSession $session The session to update
     * @param string $status New status
     * @param array|null $result Optional result data
     * @return ImportSession
     */
    public function updateStatus(ImportSession $session, string $status, ?array $result = null): ImportSession
    {
        $session->status = $status;
        
        if ($result !== null) {
            $session->result = $result;
        }

        $session->save();

        return $session;
    }

    /**
     * Delete an import session and its file.
     *
     * @param ImportSession $session The session to delete
     * @return void
     */
    public function delete(ImportSession $session): void
    {
        // Delete the file
        Storage::disk($session->storage_disk)->delete($session->file_path);

        // Delete the session (mappings will be cascade deleted)
        $session->delete();
    }

    /**
     * Clean up old import sessions.
     *
     * @param int $daysOld Sessions older than this will be deleted
     * @return int Number of sessions deleted
     */
    public function cleanupOldSessions(int $daysOld = 7): int
    {
        $cutoff = now()->subDays($daysOld);
        $sessions = ImportSession::where('created_at', '<', $cutoff)->get();

        $count = 0;
        foreach ($sessions as $session) {
            $this->delete($session);
            $count++;
        }

        return $count;
    }

    /**
     * Get the SpreadsheetReader for a session.
     *
     * @param ImportSession $session The session
     * @return SpreadsheetReader
     */
    public function getReader(ImportSession $session): SpreadsheetReader
    {
        return new SpreadsheetReader(
            $session->getFullFilePath(),
            $session->file_type,
            [
                'csv_encoding' => $session->getOption('csv_encoding', 'UTF-8'),
                'csv_delimiter' => $session->getOption('csv_delimiter', ','),
                'sheet_index' => $session->sheet_index,
            ]
        );
    }
}
