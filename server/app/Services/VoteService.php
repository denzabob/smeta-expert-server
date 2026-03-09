<?php

namespace App\Services;

use App\Models\Idea;
use App\Models\IdeaVote;
use Illuminate\Support\Facades\DB;

class VoteService
{
    public function vote(Idea $idea, int $userId, string $voteType): Idea
    {
        return DB::transaction(function () use ($idea, $userId, $voteType) {
            $lockedIdea = Idea::query()
                ->whereKey($idea->id)
                ->lockForUpdate()
                ->firstOrFail();

            $vote = IdeaVote::query()
                ->where('idea_id', $lockedIdea->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($vote === null) {
                IdeaVote::create([
                    'idea_id' => $lockedIdea->id,
                    'user_id' => $userId,
                    'vote_type' => $voteType,
                    'created_at' => now(),
                ]);
            } else {
                $vote->vote_type = $voteType;
                $vote->save();
            }

            $this->refreshCounters($lockedIdea);

            return $lockedIdea->fresh();
        });
    }

    public function removeVote(Idea $idea, int $userId): Idea
    {
        return DB::transaction(function () use ($idea, $userId) {
            $lockedIdea = Idea::query()
                ->whereKey($idea->id)
                ->lockForUpdate()
                ->firstOrFail();

            IdeaVote::query()
                ->where('idea_id', $lockedIdea->id)
                ->where('user_id', $userId)
                ->delete();

            $this->refreshCounters($lockedIdea);

            return $lockedIdea->fresh();
        });
    }

    private function refreshCounters(Idea $idea): void
    {
        $votesUp = IdeaVote::query()
            ->where('idea_id', $idea->id)
            ->where('vote_type', IdeaVote::TYPE_UP)
            ->count();

        $votesDown = IdeaVote::query()
            ->where('idea_id', $idea->id)
            ->where('vote_type', IdeaVote::TYPE_DOWN)
            ->count();

        $idea->votes_up = $votesUp;
        $idea->votes_down = $votesDown;
        $idea->save();
    }
}
