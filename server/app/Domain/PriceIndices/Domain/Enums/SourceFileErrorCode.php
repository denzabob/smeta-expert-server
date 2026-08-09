<?php

namespace App\Domain\PriceIndices\Domain\Enums;

enum SourceFileErrorCode: string
{
    case FileMissing = 'file_missing';
    case FileEmpty = 'file_empty';
    case FileTooLarge = 'file_too_large';
    case InvalidExtension = 'invalid_extension';
    case InvalidMime = 'invalid_mime';
    case InvalidZipSignature = 'invalid_zip_signature';
    case InvalidZip = 'invalid_zip';
    case TooManyZipEntries = 'too_many_zip_entries';
    case EntryTooLarge = 'entry_too_large';
    case UncompressedSizeLimit = 'uncompressed_size_limit';
    case CompressionRatioLimit = 'compression_ratio_limit';
    case PathTraversal = 'path_traversal';
    case MissingContentTypes = 'missing_content_types';
    case MissingWorkbook = 'missing_workbook';
    case MacrosNotAllowed = 'macros_not_allowed';
    case EmbeddedExecutable = 'embedded_executable';
    case DuplicateFile = 'duplicate_file';
    case StorageFailure = 'storage_failure';
    case InvalidPeriod = 'invalid_period';
    case SourceDatasetMismatch = 'source_dataset_mismatch';
    case ImmutableDatasetCode = 'immutable_dataset_code';
    case InvalidLifecycle = 'invalid_lifecycle';
}
