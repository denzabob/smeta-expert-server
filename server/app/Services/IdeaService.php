<?php

namespace App\Services;

use App\Models\Idea;
use App\Models\Tag;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class IdeaService
{
    public function createIdea(int $userId, array $payload, array $attachments = []): Idea
    {
        $storedPaths = [];

        try {
            $idea = DB::transaction(function () use ($userId, $payload, $attachments, &$storedPaths) {
                $idea = Idea::create([
                    'title' => $payload['title'],
                    'description' => $payload['description'],
                    'user_id' => $userId,
                    'status' => Idea::STATUS_NEW,
                ]);

                $this->syncTags($idea, $payload['tags'] ?? []);

                foreach ($attachments as $file) {
                    if (!$file instanceof UploadedFile) {
                        continue;
                    }

                    $path = $file->store('ideas/attachments/' . now()->format('Y/m'), 'public');
                    $storedPaths[] = $path;

                    $idea->attachments()->create([
                        'file_path' => $path,
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    ]);
                }

                return $idea;
            });

            return $this->getIdeaDetail($idea->id);
        } catch (\Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('public')->delete($path);
            }

            throw $exception;
        }
    }

    public function listIdeas(array $filters): LengthAwarePaginator
    {
        $query = Idea::query()
            ->with(['user', 'tags:id,name', 'attachments:id,idea_id,file_path,mime_type,created_at'])
            ->withCount('comments');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['tag'])) {
            $tag = trim((string) $filters['tag']);
            $query->whereHas('tags', function (Builder $builder) use ($tag) {
                $builder->where('name', $tag);
            });
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $driver = DB::connection()->getDriverName();

            $query->where(function (Builder $builder) use ($search, $driver) {
                if ($driver === 'pgsql') {
                    $builder
                        ->whereRaw('title ILIKE ?', ["%{$search}%"])
                        ->orWhereRaw('description ILIKE ?', ["%{$search}%"]);

                    return;
                }

                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $sort = strtolower((string) ($filters['sort'] ?? 'new'));
        $this->applySort($query, $sort);

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min(100, $perPage));

        return $query->paginate($perPage);
    }

    public function getIdeaDetail(int $ideaId): Idea
    {
        return Idea::query()
            ->with([
                'user',
                'tags:id,name',
                'attachments:id,idea_id,file_path,mime_type,created_at',
                'comments' => function ($query) {
                    $query
                        ->with('user')
                        ->orderBy('created_at', 'asc');
                },
            ])
            ->withCount('comments')
            ->findOrFail($ideaId);
    }

    public function deleteIdea(Idea $idea): void
    {
        DB::transaction(function () use ($idea) {
            $idea = Idea::query()->with('attachments:id,idea_id,file_path')->findOrFail($idea->id);

            foreach ($idea->attachments as $attachment) {
                Storage::disk('public')->delete($attachment->file_path);
            }

            $idea->delete();
        });
    }

    public function updateStatus(Idea $idea, string $status): Idea
    {
        $idea->status = $status;
        $idea->save();

        return $idea->fresh();
    }

    private function applySort(Builder $query, string $sort): void
    {
        if ($sort === 'top') {
            $query
                ->orderByRaw('(votes_up - votes_down) DESC')
                ->orderByDesc('created_at');

            return;
        }

        if ($sort === 'hot') {
            $driver = DB::connection()->getDriverName();

            if ($driver === 'pgsql') {
                $query
                    ->orderByRaw('((votes_up - votes_down) / GREATEST(EXTRACT(EPOCH FROM (NOW() - created_at)) / 3600, 1)) DESC')
                    ->orderByDesc('created_at');

                return;
            }

            $query
                ->orderByRaw('((votes_up - votes_down) / GREATEST(TIMESTAMPDIFF(HOUR, created_at, NOW()), 1)) DESC')
                ->orderByDesc('created_at');

            return;
        }

        $query->orderByDesc('created_at');
    }

    private function syncTags(Idea $idea, array $rawTags): void
    {
        $tags = Collection::make($rawTags)
            ->filter(fn ($tag) => is_string($tag))
            ->map(fn (string $tag) => trim($tag))
            ->filter(fn (string $tag) => $tag !== '')
            ->map(fn (string $tag) => mb_substr($tag, 0, 128))
            ->unique()
            ->values();

        if ($tags->isEmpty()) {
            $idea->tags()->sync([]);

            return;
        }

        $tagIds = $tags
            ->map(fn (string $name) => Tag::firstOrCreate(['name' => $name])->id)
            ->values()
            ->all();

        $idea->tags()->sync($tagIds);
    }
}
