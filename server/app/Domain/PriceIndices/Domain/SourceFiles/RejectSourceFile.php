<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectSourceFile
{
    public function __construct(private readonly SourceFileLifecycle $lifecycle)
    {
    }

    public function execute(
        StatisticalSourceFile $sourceFile,
        User $actor,
        string $reason
    ): StatisticalSourceFile {
        $reason = trim($reason);

        if ($reason === '') {
            throw new PriceIndicesInvariantViolation('Rejection reason is required.');
        }

        return DB::transaction(function () use ($sourceFile, $actor, $reason): StatisticalSourceFile {
            $lockedFile = StatisticalSourceFile::query()
                ->lockForUpdate()
                ->findOrFail($sourceFile->getKey());

            $this->lifecycle->transition($lockedFile, SourceFileStatus::Rejected);
            $lockedFile->rejection_reason = $reason;
            $lockedFile->reviewed_by_user_id = $actor->getKey();
            $lockedFile->reviewed_at = now();
            $lockedFile->save();

            return $lockedFile->refresh();
        });
    }
}
