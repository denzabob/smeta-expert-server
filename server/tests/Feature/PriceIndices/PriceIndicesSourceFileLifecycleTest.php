<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileTransitionNotAllowed;
use App\Domain\PriceIndices\Domain\SourceFiles\ApproveSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\RejectSourceFile;
use App\Domain\PriceIndices\Domain\SourceFiles\SourceFileLifecycle;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PriceIndicesSourceFileLifecycleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_all_allowed_transitions_are_accepted(): void
    {
        $allowed = [
            [SourceFileStatus::PendingReview, SourceFileStatus::Approved],
            [SourceFileStatus::PendingReview, SourceFileStatus::Rejected],
            [SourceFileStatus::Approved, SourceFileStatus::Active],
            [SourceFileStatus::Active, SourceFileStatus::Superseded],
        ];
        $lifecycle = app(SourceFileLifecycle::class);

        foreach ($allowed as [$from, $to]) {
            $file = new StatisticalSourceFile(['status' => $from]);

            $this->assertTrue($lifecycle->canTransition($from, $to));
            $this->assertSame($to, $lifecycle->transition($file, $to)->status);
        }
    }

    public function test_every_unlisted_transition_is_rejected(): void
    {
        $allowed = [
            'pending_review:approved',
            'pending_review:rejected',
            'approved:active',
            'active:superseded',
        ];
        $lifecycle = app(SourceFileLifecycle::class);

        foreach (SourceFileStatus::cases() as $from) {
            foreach (SourceFileStatus::cases() as $to) {
                if (in_array("{$from->value}:{$to->value}", $allowed, true)) {
                    continue;
                }

                $file = new StatisticalSourceFile(['status' => $from]);

                try {
                    $lifecycle->transition($file, $to);
                    $this->fail("Transition {$from->value} -> {$to->value} was accepted.");
                } catch (SourceFileTransitionNotAllowed) {
                    $this->assertSame($from, $file->status);
                }
            }
        }
    }

    public function test_approve_records_reviewer_and_time(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->create();

        $approved = app(ApproveSourceFile::class)->execute($file, $actor);

        $this->assertSame(SourceFileStatus::Approved, $approved->status);
        $this->assertSame($actor->id, $approved->reviewed_by_user_id);
        $this->assertNotNull($approved->reviewed_at);
    }

    public function test_approve_rejects_invalid_status(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->approved()->create();

        $this->expectException(SourceFileTransitionNotAllowed::class);

        app(ApproveSourceFile::class)->execute($file, $actor);
    }

    public function test_reject_requires_reason(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->create();

        $this->expectException(PriceIndicesInvariantViolation::class);

        app(RejectSourceFile::class)->execute($file, $actor, '   ');
    }

    public function test_reject_records_reason_reviewer_and_time(): void
    {
        $actor = User::factory()->create();
        $file = StatisticalSourceFile::factory()->create();

        $rejected = app(RejectSourceFile::class)->execute($file, $actor, 'Wrong period');

        $this->assertSame(SourceFileStatus::Rejected, $rejected->status);
        $this->assertSame('Wrong period', $rejected->rejection_reason);
        $this->assertSame($actor->id, $rejected->reviewed_by_user_id);
        $this->assertNotNull($rejected->reviewed_at);
    }
}
