<?php

namespace Tests\Unit\Services;

use App\Models\EstimateEvidenceRun;
use App\Models\EvidenceArtifact;
use App\Models\EvidenceAsset;
use App\Models\MaterialPriceHistory;
use App\Http\Controllers\Api\RevisionRunController;
use App\Models\Project;
use App\Models\ProjectRevision;
use App\Services\EstimateEvidencePdfBuilder;
use App\Services\PdfEvidenceImageMaterializer;
use App\Services\Storage\ObjectStorage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfEvidenceImageMaterializerTest extends TestCase
{
    public function test_revision_price_snapshot_uses_history_and_artifact_image_locators(): void
    {
        $controller = (new \ReflectionClass(RevisionRunController::class))->newInstanceWithoutConstructor();
        $resolver = new \ReflectionMethod(RevisionRunController::class, 'priceJustificationImageLocator');
        $resolver->setAccessible(true);

        $history = new MaterialPriceHistory();
        $history->setRawAttributes([
            'screenshot_path' => null,
            'snapshot_path' => 'smeta/history/snapshot.png',
            'storage_disk' => null,
        ]);
        $artifact = new EvidenceArtifact();
        $artifact->setRawAttributes([
            'screenshot_path' => 'smeta/artifacts/screenshot.png',
            'storage_disk' => 's1',
        ]);
        $asset = new EvidenceAsset();
        $asset->setRawAttributes([
            'asset_type' => 'screenshot',
            'mime_type' => 'image/png',
            'file_path' => 'smeta/assets/screenshot.png',
            'storage_disk' => 's1',
        ]);
        $artifact->setRelation('assets', collect([$asset]));

        $historyLocator = $resolver->invoke($controller, $history, $artifact);
        $this->assertSame('smeta/history/snapshot.png', $historyLocator['screenshot_path']);
        $this->assertSame('smeta/history/snapshot.png', $historyLocator['snapshot_path']);
        $this->assertSame('s1', $historyLocator['storage_disk']);

        $artifactOnly = new EvidenceArtifact();
        $artifactOnly->setRawAttributes([
            'screenshot_path' => null,
            'storage_disk' => null,
        ]);
        $artifactOnly->setRelation('assets', collect([$asset]));
        $assetLocator = $resolver->invoke($controller, null, $artifactOnly);
        $this->assertSame('smeta/assets/screenshot.png', $assetLocator['screenshot_path']);
        $this->assertSame('s1', $assetLocator['storage_disk']);
    }

    public function test_price_justification_pdf_embeds_multiple_s1_images_and_removes_temporary_files(): void
    {
        Storage::fake('s1');
        Storage::fake('local');
        Storage::fake('public');

        $s1One = 'smeta/screenshots/one.png';
        $s1Two = 'smeta/screenshots/two.png';
        $s1HistorySnapshot = 'smeta/screenshots/history-snapshot.png';
        $s1Facade = 'smeta/evidence/facade.png';
        $publicPath = 'legacy/screenshots/public.png';
        $localPath = 'legacy/screenshots/local.png';

        Storage::disk('s1')->put($s1One, $this->pngPixel(230, 20, 30));
        Storage::disk('s1')->put($s1Two, $this->pngPixel(20, 210, 40));
        Storage::disk('s1')->put($s1HistorySnapshot, $this->pngPixel(40, 180, 190));
        Storage::disk('s1')->put($s1Facade, $this->pngPixel(30, 50, 220));
        Storage::disk('public')->put($publicPath, $this->pngPixel(220, 180, 20));
        Storage::disk('local')->put($localPath, $this->pngPixel(180, 20, 200));
        $this->assertSame('local', app(ObjectStorage::class)->resolveDisk('local', 'missing/legacy.png'));

        $rows = [
            [
                'name' => 'S1 material one',
                'cost_driver_type' => 'plate',
                'price_per_unit' => 100,
                'screenshot_path' => $s1One,
                'storage_disk' => null,
            ],
            [
                'name' => 'S1 material two',
                'cost_driver_type' => 'edge',
                'price_per_unit' => 200,
                'screenshot_path' => $s1Two,
                'storage_disk' => 'public',
            ],
            [
                'name' => 'Legacy public material',
                'cost_driver_type' => 'fitting',
                'price_per_unit' => 300,
                'screenshot_path' => $publicPath,
                'storage_disk' => null,
            ],
            [
                'name' => 'Material with legacy snapshot path',
                'cost_driver_type' => 'edge',
                'price_per_unit' => 350,
                'screenshot_path' => null,
                'snapshot_path' => $s1HistorySnapshot,
                'storage_disk' => 's1',
            ],
            [
                'name' => 'Legacy local material',
                'cost_driver_type' => 'plate',
                'price_per_unit' => 400,
                'screenshot_path' => $localPath,
                'storage_disk' => null,
            ],
            [
                'name' => 'Facade snapshot',
                'cost_driver_type' => 'facade',
                'reference_type' => 'snapshot_summary',
                'source_level_snapshot' => [
                    'sources' => [[
                        'evidence_assets' => [[
                            'asset_ref' => ['id' => 41],
                            'asset_type' => 'screenshot',
                            'mime_type' => 'image/png',
                            'file_path' => $s1Facade,
                            'storage_disk' => null,
                        ]],
                    ]],
                ],
                'facade_snapshot_presentation' => [
                    'sources' => [[
                        'evidence_assets' => [[
                            'asset_id' => 41,
                            'display_label' => 'Facade screenshot',
                            'original_name' => 'facade.png',
                            'mime_type' => 'image/png',
                        ]],
                    ]],
                ],
            ],
        ];

        $project = new Project();
        $project->number = 37;
        $project->name = 'PDF storage test';
        $revision = new ProjectRevision();
        $revision->number = 8;

        $temporaryPaths = [];
        $pdf = app(PdfEvidenceImageMaterializer::class)->withPriceJustificationImages(
            $rows,
            function (array $materializedRows) use ($project, $revision, &$temporaryPaths): string {
                foreach ($materializedRows as $row) {
                    if (!empty($row['pdf_local_path'])) {
                        $temporaryPaths[] = $row['pdf_local_path'];
                        $this->assertFileExists($row['pdf_local_path']);
                    }
                    foreach ((array) data_get($row, 'facade_snapshot_presentation.sources', []) as $source) {
                        foreach ((array) ($source['evidence_assets'] ?? []) as $asset) {
                            if (!empty($asset['pdf_local_path'])) {
                                $temporaryPaths[] = $asset['pdf_local_path'];
                                $this->assertFileExists($asset['pdf_local_path']);
                            }
                        }
                    }
                }

                return $this->renderPdf('reports.price_justification', [
                    'project' => $project,
                    'revision' => $revision,
                    'rows' => $materializedRows,
                    'evidenceSummary' => ['total_items' => count($materializedRows), 'with_evidence' => count($materializedRows)],
                    'reportSettings' => [],
                ]);
            },
        );

        $this->assertGreaterThanOrEqual(6, substr_count($pdf, '/Subtype /Image'));
        $this->assertCount(6, array_unique($temporaryPaths));
        foreach ($temporaryPaths as $temporaryPath) {
            $this->assertFileDoesNotExist($temporaryPath);
        }
    }

    public function test_estimate_evidence_pdf_builder_and_renderer_resolve_s1_evidence_assets(): void
    {
        Storage::fake('s1');
        $path = 'smeta/evidence-records/screenshot.png';
        Storage::disk('s1')->put($path, $this->pngPixel(70, 130, 200));

        $run = new EstimateEvidenceRun();
        $run->snapshot_json = [
            'evidence_items' => [[
                'evidence_record_id' => 7,
                'cost_component' => 'plate',
                'label' => 'Материал со скриншотом',
                'effective_value' => 123,
            ]],
            'evidence_records' => [[
                'id' => 7,
                'source_type' => 'web',
                'observed_price' => 123,
                'source_url' => 'https://example.test/source',
                'assets' => [[
                    'asset_type' => 'screenshot',
                    'mime_type' => 'image/png',
                    'file_path' => $path,
                ]],
            ]],
        ];

        $viewData = app(EstimateEvidencePdfBuilder::class)->build($run, new Project());
        $entry = $viewData['sections'][0]['entries'][0];
        $this->assertSame(ObjectStorage::DISK, $entry['image_storage_disk']);
        $this->assertTrue($entry['image_exists']);

        $temporaryPath = null;
        $pdf = app(PdfEvidenceImageMaterializer::class)->withEvidenceRunImages(
            $viewData,
            function (array $materializedViewData) use (&$temporaryPath): string {
                $entry = $materializedViewData['sections'][0]['entries'][0];
                $temporaryPath = $entry['image_local_path'];
                $this->assertFileExists($temporaryPath);

                return $this->renderPdf('reports.evidence_run', $materializedViewData);
            },
        );

        $this->assertGreaterThan(0, substr_count($pdf, '/Subtype /Image'));
        $this->assertNotNull($temporaryPath);
        $this->assertFileDoesNotExist($temporaryPath);
    }

    /** @param array<string, mixed> $data */
    private function renderPdf(string $view, array $data): string
    {
        return Pdf::loadView($view, $data)
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }

    private function pngPixel(int $red, int $green, int $blue): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            . $type
            . $data
            . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNC5', 1, 1, 8, 2, 0, 0, 0))
            . $chunk('IDAT', gzcompress("\x00" . chr($red) . chr($green) . chr($blue)))
            . $chunk('IEND', '');
    }
}
