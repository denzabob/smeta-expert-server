<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expert\ExpertMessage;
use App\Models\Expert\ExpertMessageFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminExpertFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $filters = $request->validate([
            'rating' => ['nullable', Rule::in(['positive', 'negative', 'all'])],
            'provider' => ['nullable', 'string', 'max:64'],
            'model' => ['nullable', 'string', 'max:255'],
            'reason_code' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = ExpertMessageFeedback::query();
        foreach (['provider', 'model', 'reason_code'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $ratingCounts = (clone $query)->selectRaw('rating, COUNT(*) as aggregate')->groupBy('rating')->pluck('aggregate', 'rating');
        $positive = (int) ($ratingCounts['positive'] ?? 0);
        $negative = (int) ($ratingCounts['negative'] ?? 0);
        if (($filters['rating'] ?? 'negative') !== 'all') {
            $query->where('rating', $filters['rating'] ?? 'negative');
        }
        $paginator = $query->with(['user:id,name,email', 'message.conversation.project:id,public_id,name'])
            ->latest()->paginate($filters['per_page'] ?? 25)->through(static fn (ExpertMessageFeedback $item): array => [
                'id' => $item->public_id,
                'created_at' => $item->created_at?->toIso8601String(),
                'user' => ['name' => $item->user?->name, 'email' => $item->user?->email],
                'project' => ['id' => $item->message?->conversation?->project?->public_id, 'name' => $item->message?->conversation?->project?->name],
                'provider' => $item->provider,
                'model' => $item->model,
                'rating' => $item->rating,
                'reason_code' => $item->reason_code,
                'comment' => $item->comment,
            ]);

        return response()->json([
            ...$paginator->toArray(),
            'counts' => ['all' => $positive + $negative, 'positive' => $positive, 'negative' => $negative],
        ]);
    }

    public function show(Request $request, ExpertMessageFeedback $feedback): JsonResponse
    {
        $this->authorizeAdmin($request);
        $feedback->load(['message.conversation.project', 'user']);
        $assistant = $feedback->message;
        $replyTo = is_array($assistant?->metadata) ? ($assistant->metadata['in_reply_to'] ?? null) : null;
        $userMessage = is_string($replyTo) ? ExpertMessage::query()
            ->where('expert_conversation_id', $assistant->expert_conversation_id)
            ->where('role', 'user')->where('public_id', $replyTo)
            ->with('attachments')->first() : null;

        return response()->json([
            'request' => $userMessage?->content,
            'answer' => $assistant?->content,
            'materials' => $userMessage?->attachments->pluck('original_name_snapshot')->all() ?? [],
            'provider' => $feedback->provider,
            'model' => $feedback->model,
            'run_id' => $feedback->run_id,
            'requested_mode' => $feedback->requested_mode,
            'resolved_mode' => $feedback->resolved_mode,
            'latency_ms' => is_array($assistant?->metadata) ? ($assistant->metadata['latency_ms'] ?? null) : null,
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user() && (int) $request->user()->id === 1, 403);
    }
}
