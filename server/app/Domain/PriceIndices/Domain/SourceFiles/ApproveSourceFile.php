<?php

namespace App\Domain\PriceIndices\Domain\SourceFiles;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveSourceFile
{
    public function __construct(private readonly SourceFileLifecycle $lifecycle)
    {
    }

    public function execute(StatisticalSourceFile $sourceFile, User $actor): StatisticalSourceFile
    {
        return DB::transaction(function () use ($sourceFile, $actor): StatisticalSourceFile {
            $lockedFile = StatisticalSourceFile::query()
                ->lockForUpdate()
                ->findOrFail($sourceFile->getKey());

            $this->lifecycle->transition($lockedFile, SourceFileStatus::Approved);
            $lockedFile->reviewed_by_user_id = $actor->getKey();
            $lockedFile->reviewed_at = now();
            $lockedFile->save();

            return $lockedFile->refresh();
        });
    }
}
