<?php

namespace App\Domain\PriceIndices\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadSourceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKilobytes = (int) ceil(
            ((int) config('price_indices.source_files.max_upload_bytes')) / 1024
        );
        $mimeTypes = implode(',', config('price_indices.xlsx.allowed_mime_types', []));

        return [
            'dataset_public_id' => ['required', 'uuid', 'exists:statistical_datasets,public_id'],
            'source_public_id' => ['nullable', 'uuid', 'exists:statistical_sources,public_id'],
            'reporting_year' => ['nullable', 'integer', 'min:1900', 'max:9999'],
            'reporting_month' => ['nullable', 'integer', 'between:1,12'],
            'source_url' => ['nullable', 'url:http,https', 'max:4096'],
            'file' => ['required', 'file', "max:{$maxKilobytes}", 'extensions:xlsx', "mimetypes:{$mimeTypes}"],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                $yearPresent = $this->filled('reporting_year');
                $monthPresent = $this->filled('reporting_month');

                if ($yearPresent !== $monthPresent) {
                    $validator->errors()->add(
                        'reporting_period',
                        'Reporting year and month must both be provided or both be omitted.'
                    );
                }
            },
        ];
    }
}
