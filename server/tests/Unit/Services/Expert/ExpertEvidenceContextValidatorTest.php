<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Expert;

use App\Services\Expert\ExpertChatMaterialContext;
use App\Services\Expert\ExpertChatMaterialContextBundle;
use App\Services\Expert\ExpertContextPack;
use App\Services\Expert\ExpertContextResolution;
use App\Services\Expert\ExpertEvidenceContextValidator;
use App\Services\Expert\ExpertMaterialContextException;
use Tests\TestCase;

final class ExpertEvidenceContextValidatorTest extends TestCase
{
    public function test_selected_order_and_roles_are_preserved(): void
    {
        $sources = app(ExpertEvidenceContextValidator::class)->prepare($this->bundle(['b', 'a'], ['a', 'b'], ['comparison', 'primary']));

        $this->assertSame(['a', 'b'], array_column($sources, 'id'));
        $this->assertSame(['comparison', 'primary'], array_column($sources, 'role'));
    }

    public function test_unselected_or_missing_prepared_material_fails_before_request(): void
    {
        foreach ([['a', 'b'], []] as $prepared) {
            try {
                app(ExpertEvidenceContextValidator::class)->prepare($this->bundle($prepared, ['a'], ['primary']));
                $this->fail('Expected controlled evidence mismatch.');
            } catch (ExpertMaterialContextException $exception) {
                $this->assertSame('expert_evidence_context_mismatch', $exception->errorCode);
            }
        }
    }

    /** @param list<string> $prepared @param list<string> $selected @param list<string> $roles */
    private function bundle(array $prepared, array $selected, array $roles): ExpertChatMaterialContextBundle
    {
        $resolution = new ExpertContextResolution(
            array_map(static fn (string $id, string $role): array => ['material_id' => $id, 'role' => $role, 'origin' => 'current', 'reason_code' => 'test'], $selected, $roles),
            [], 'single', 'focused', false, 'test', 1.0,
        );
        $pack = new ExpertContextPack('', [], [], [], $selected, 'single', 'focused', false, resolution: $resolution);
        $context = new ExpertChatMaterialContext(
            array_map(static fn (string $id): array => ['public_id' => $id, 'name' => $id.'.txt', 'mime_type' => 'text/plain', 'text' => $id.' content'], $prepared),
            [],
        );

        return new ExpertChatMaterialContextBundle($context, new ExpertChatMaterialContext([], []), plan: $pack);
    }
}
