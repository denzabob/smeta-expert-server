<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    /** Allowed attachment MIME types. */
    public const ALLOWED_MIME_TYPES = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];

    /** Maximum attachment size in kilobytes (8 MB). */
    private const MAX_SIZE_KB = 8192;

    public function authorize(): bool
    {
        return true; // authorization is handled by Policy in the controller
    }

    public function rules(): array
    {
        $mimes = implode(',', self::ALLOWED_MIME_TYPES);

        return [
            // body is required unless an attachment is provided, and vice versa.
            'body'       => 'required_without:attachment|nullable|string|min:1|max:10000',
            'attachment' => [
                'required_without:body',
                'nullable',
                'file',
                'mimetypes:' . $mimes,
                'max:' . self::MAX_SIZE_KB,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required_without'       => 'Введите сообщение или прикрепите изображение.',
            'attachment.required_without'  => 'Прикрепите файл или введите сообщение.',
            'attachment.mimetypes'         => 'Допустимые форматы: PNG, JPEG, GIF, WebP.',
            'attachment.max'               => 'Размер файла не должен превышать 8 МБ.',
        ];
    }
}
