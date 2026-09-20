<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Models\Expert\ExpertMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class MessageFeedbackController extends Controller
{
    private const REASONS = [
        'incorrect_or_incomplete', 'not_requested', 'material_analysis_error',
        'too_slow', 'style_or_formatting', 'other',
    ];

    public function upsert(Request $request, ExpertMessage $message): JsonResponse
    {
        $this->assertEligible($message);
        $data = $request->validate([
            'rating' => ['required', Rule::in(['positive', 'negative'])],
            'reason_code' => ['nullable', Rule::in(self::REASONS)],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $feedback = DB::transaction(function () use ($message, $request, $data, $metadata) {
            $message->newQuery()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            return $message->feedback()->updateOrCreate(
                ['user_id' => $request->user()->id],
                [
                    'rating' => $data['rating'],
                    'reason_code' => $data['rating'] === 'negative' ? ($data['reason_code'] ?? null) : null,
                    'comment' => $data['rating'] === 'negative' ? ($data['comment'] ?? null) : null,
                    'provider' => $this->snapshot($metadata, 'provider'),
                    'model' => $this->snapshot($metadata, 'model'),
                    'requested_mode' => $this->snapshot($metadata, 'requested_mode'),
                    'resolved_mode' => $this->snapshot($metadata, 'resolved_mode'),
                    'run_id' => Str::isUuid($this->snapshot($metadata, 'run_id') ?? '') ? $metadata['run_id'] : null,
                ],
            );
        });

        return response()->json([
            'rating' => $feedback->rating,
            'reason_code' => $feedback->reason_code,
            'comment' => $feedback->comment,
        ]);
    }

    public function destroy(Request $request, ExpertMessage $message): JsonResponse
    {
        $this->assertEligible($message);
        $message->feedback()->where('user_id', $request->user()->id)->delete();

        return response()->json(null, 204);
    }

    private function assertEligible(ExpertMessage $message): void
    {
        $this->authorize('update', $message->conversation->project);
        $status = is_array($message->metadata) ? ($message->metadata['generation_status'] ?? null) : null;
        abort_unless($message->role === 'assistant' && trim($message->content) !== '' && ! in_array($status, ['stopped', 'interrupted'], true), 422);
    }

    /** @param array<string, mixed> $metadata */
    private function snapshot(array $metadata, string $key): ?string
    {
        return is_string($metadata[$key] ?? null) && $metadata[$key] !== '' ? $metadata[$key] : null;
    }
}
