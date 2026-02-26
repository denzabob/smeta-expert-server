<?php

namespace App\Services\Import;

use App\Models\ImportColumnMapping;
use App\Models\ImportSession;
use Illuminate\Validation\ValidationException;

/**
 * Validator for import column mappings.
 */
class ImportMappingValidator
{
    /**
     * Validate and save column mappings for an import session.
     *
     * @param ImportSession $session The import session
     * @param array $mappings Array of mapping definitions [{column_index: int, field: string|null}, ...]
     * @param array $options Additional options to save
     * @param int $columnCount Total number of columns in the file
     * @return ImportSession
     * @throws ValidationException
     */
    public function validateAndSave(
        ImportSession $session,
        array $mappings,
        array $options = [],
        int $columnCount = 0
    ): ImportSession {
        // Validate the mappings
        $this->validate($mappings, $columnCount);

        // Update session options if provided
        if (!empty($options)) {
            $currentOptions = $session->options ?? [];
            $session->options = array_merge($currentOptions, $options);
        }

        // Update header_row_index and sheet_index if provided
        if (isset($options['header_row_index'])) {
            $session->header_row_index = $options['header_row_index'];
        }
        if (isset($options['sheet_index'])) {
            $session->sheet_index = $options['sheet_index'];
        }

        // Delete existing mappings
        $session->columnMappings()->delete();

        // Create new mappings
        foreach ($mappings as $mapping) {
            if (!isset($mapping['column_index'])) {
                continue;
            }

            $field = $mapping['field'] ?? null;
            
            // Skip if no field or explicit ignore
            if ($field === null || $field === '' || $field === 'ignore') {
                // Only create mapping for explicit ignore if you want to track it
                if ($field === 'ignore') {
                    ImportColumnMapping::create([
                        'import_session_id' => $session->id,
                        'column_index' => $mapping['column_index'],
                        'field' => ImportColumnMapping::FIELD_IGNORE,
                    ]);
                }
                continue;
            }

            ImportColumnMapping::create([
                'import_session_id' => $session->id,
                'column_index' => $mapping['column_index'],
                'field' => $field,
            ]);
        }

        // Update status to mapped
        $session->status = ImportSession::STATUS_MAPPED;
        $session->save();

        // Reload mappings
        $session->load('columnMappings');

        return $session;
    }

    /**
     * Validate mapping array without saving.
     *
     * @param array $mappings The mappings to validate
     * @param int $columnCount Total number of columns (for range validation)
     * @throws ValidationException
     */
    public function validate(array $mappings, int $columnCount = 0): void
    {
        $errors = [];
        $fieldAssignments = [];

        foreach ($mappings as $index => $mapping) {
            // Validate column_index exists
            if (!isset($mapping['column_index']) || !is_int($mapping['column_index'])) {
                $errors["mappings.{$index}.column_index"] = ['Column index is required and must be an integer.'];
                continue;
            }

            $columnIndex = $mapping['column_index'];
            $field = $mapping['field'] ?? null;

            // Validate column_index is in range (if column count provided)
            if ($columnCount > 0 && ($columnIndex < 0 || $columnIndex >= $columnCount)) {
                $errors["mappings.{$index}.column_index"] = [
                    "Column index {$columnIndex} is out of range (0-" . ($columnCount - 1) . ")."
                ];
            }

            // Skip validation for ignore/null fields
            if ($field === null || $field === '' || $field === 'ignore') {
                continue;
            }

            // Validate field is allowed
            if (!in_array($field, ImportColumnMapping::ALL_FIELDS)) {
                $errors["mappings.{$index}.field"] = [
                    "Invalid field '{$field}'. Allowed: " . implode(', ', ImportColumnMapping::ALL_FIELDS)
                ];
                continue;
            }

            // Check for duplicate field assignments (for unique fields)
            if (in_array($field, ImportColumnMapping::UNIQUE_FIELDS)) {
                if (isset($fieldAssignments[$field])) {
                    $errors["mappings.{$index}.field"] = [
                        "Field '{$field}' is already assigned to column {$fieldAssignments[$field]}. Each field can only be assigned once."
                    ];
                } else {
                    $fieldAssignments[$field] = $columnIndex;
                }
            }
        }

        // Check required fields
        foreach (ImportColumnMapping::REQUIRED_FIELDS as $requiredField) {
            if (!isset($fieldAssignments[$requiredField])) {
                $errors['mapping'] = $errors['mapping'] ?? [];
                $errors['mapping'][] = "Required field '{$requiredField}' is not assigned to any column.";
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Get the current mapping summary for a session.
     *
     * @param ImportSession $session The import session
     * @return array Summary of current mappings
     */
    public function getSummary(ImportSession $session): array
    {
        $mappings = $session->columnMappings;
        
        $summary = [
            'has_width' => false,
            'has_length' => false,
            'has_qty' => false,
            'is_valid' => false,
            'mappings' => [],
        ];

        foreach ($mappings as $mapping) {
            if ($mapping->field === ImportColumnMapping::FIELD_WIDTH) {
                $summary['has_width'] = true;
            }
            if ($mapping->field === ImportColumnMapping::FIELD_LENGTH) {
                $summary['has_length'] = true;
            }
            if ($mapping->field === ImportColumnMapping::FIELD_QTY) {
                $summary['has_qty'] = true;
            }

            $summary['mappings'][] = [
                'column_index' => $mapping->column_index,
                'field' => $mapping->field,
            ];
        }

        $summary['is_valid'] = $summary['has_width'] && $summary['has_length'];

        return $summary;
    }
}
