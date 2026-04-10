<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * @mixin \App\Models\Chat\ChatAttachment
 */
class ChatAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'original_name' => $this->original_name,
            'mime_type'     => $this->mime_type,
            'size'          => $this->size,
            'width'         => $this->width,
            'height'        => $this->height,
            // Auth-guarded download URL — never the raw storage path.
            'url'           => route('chat.attachment.download', $this->id),
        ];
    }
}
