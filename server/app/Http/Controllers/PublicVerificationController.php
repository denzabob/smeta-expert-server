<?php

namespace App\Http\Controllers;

use App\Models\RevisionPublication;
use App\Models\RevisionPublicationView;
use App\Services\FinishedProductFacadeSnapshotPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicVerificationController extends Controller
{
    public function __construct(
        private FinishedProductFacadeSnapshotPresenter $finishedProductFacadeSnapshotPresenter,
    ) {}

    public function show(string $publicId, Request $request)
    {
        $publication = RevisionPublication::with(['revision.project'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (!$publication->is_active) {
            abort(404);
        }

        if ($publication->expires_at && $publication->expires_at->isPast()) {
            abort(404);
        }

        if ($publication->access_level !== 'public_readonly') {
            abort(404);
        }

        $revision = $publication->revision;
        $project = $revision->project;
        $snapshot = $this->decodeSnapshot($revision->getRawOriginal('snapshot_json'));
        $snapshotProject = is_array($snapshot['project'] ?? null) ? $snapshot['project'] : [];

        $totals = $snapshot['totals'] ?? [];

        $document = [
            'title'           => 'Расчёт',
            'project_number'  => $snapshotProject['number'] ?? $project->number ?? '—',
            'address'         => $snapshotProject['address'] ?? $project->address ?? '—',
            'expert_name'     => $snapshotProject['expert_name'] ?? $project->expert_name ?? '—',
            'created_at'      => $revision->created_at?->format('d.m.Y'),
            'locked_at'       => $revision->locked_at?->format('d.m.Y H:i:s') ?? $revision->created_at?->format('d.m.Y H:i:s'),
            'locked_at_tz'    => $revision->locked_at?->format('d.m.Y H:i:s (P)') ?? $revision->created_at?->format('d.m.Y H:i:s (P)'),
            'revision_number' => $revision->number,
            'grand_total'     => $totals['grand_total'] ?? null,
        ];

        $this->logView($publication, $request);

        // Collect price sources from project_price_list_versions
        $priceSources = $project->priceListVersions()
            ->with('priceList')
            ->get()
            ->map(function ($version) {
                return [
                    'price_list_name' => $version->priceList?->name ?? '—',
                    'version_number' => $version->version_number,
                    'price_list_version_id' => $version->id,
                    'source_type' => $version->source_type,
                    'sha256' => $version->sha256,
                    'effective_date' => $version->effective_date?->format('d.m.Y'),
                    'captured_at' => $version->captured_at?->format('d.m.Y H:i'),
                    'source_url' => $version->source_url,
                    'original_filename' => $version->original_filename,
                ];
            })
            ->toArray();

        $facadeQuoteEvidence = $this->buildFacadePricingEvidenceFromSnapshot($snapshot);

        // Build aggregated sources grouped by supplier
        $supplierSources = [];
        foreach ($priceSources as $src) {
            $supplierSources['__general__'][] = $src;
        }
        foreach ($facadeQuoteEvidence as $fqe) {
            foreach ($fqe['quotes'] as $q) {
                $sName = $q['supplier_name'] ?? '—';
                $supplierSources[$sName][] = [
                    'price_list_name' => $q['price_list_name'],
                    'version_number' => $q['version_number'],
                    'price_list_version_id' => $q['price_list_version_id'] ?? null,
                    'source_type' => $q['source_type'],
                    'sha256' => $q['sha256'],
                    'effective_date' => $q['effective_date'],
                    'source_url' => $q['source_url'] ?? null,
                    'original_filename' => $q['original_filename'] ?? null,
                ];
            }
        }
        // Deduplicate supplier sources by price_list_name + version_number
        foreach ($supplierSources as $key => $sources) {
            $seen = [];
            $unique = [];
            foreach ($sources as $s) {
                $dedupKey = ($s['price_list_name'] ?? '') . ':' . ($s['version_number'] ?? '');
                if (!isset($seen[$dedupKey])) {
                    $seen[$dedupKey] = true;
                    $unique[] = $s;
                }
            }
            $supplierSources[$key] = $unique;
        }

        $response = response()->view('verification.portal', [
            'publication' => $publication,
            'revision'    => $revision,
            'document'    => $document,
            'priceSources' => $priceSources,
            'facadeQuoteEvidence' => $facadeQuoteEvidence,
            'supplierSources' => $supplierSources,
            'documentToken' => $publicId,
        ]);

        return $response->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pdf(string $publicId, Request $request)
    {
        $publication = RevisionPublication::with(['revision'])
            ->where('public_id', $publicId)
            ->firstOrFail();

        if (!$publication->is_active) {
            abort(404);
        }

        if ($publication->expires_at && $publication->expires_at->isPast()) {
            abort(404);
        }

        if ($publication->access_level !== 'public_readonly') {
            abort(404);
        }

        $revision = $publication->revision;
        if ($revision->status === 'stale') {
            abort(404);
        }

        $snapshot = $this->decodeSnapshot($revision->getRawOriginal('snapshot_json'));

        // Generate QR code with public verification URL
        $qrUrl = $this->makePublicVerificationUrl($publicId);
        $qrSvg = $this->generateQrSvg($qrUrl);

        $pdf = Pdf::loadView('reports.smeta', [
            'report' => $snapshot,
            'qrSvg' => $qrSvg,
            'documentToken' => $publicId,
        ])
            ->setPaper('a4')
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('isPhpEnabled', false)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('fontDir', config('dompdf.font_dir'))
            ->setOption('fontCache', config('dompdf.font_cache_dir'));

        $rawFilename = "smeta_rev_{$revision->number}.pdf";
        $filename = preg_replace('#[\\/:*?"<>|]#', '_', $rawFilename);

        $this->logView($publication, $request);

        return $pdf->download($filename)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function decodeSnapshot($snapshotRaw): array
    {
        if (is_array($snapshotRaw)) {
            return $snapshotRaw;
        }
        if (!is_string($snapshotRaw) || $snapshotRaw === '') {
            return [];
        }
        $snapshot = json_decode($snapshotRaw, true);
        if (is_string($snapshot)) {
            $snapshotSecond = json_decode($snapshot, true);
            if (is_array($snapshotSecond)) {
                return $snapshotSecond;
            }
        }
        if (is_array($snapshot)) {
            return $snapshot;
        }
        return [];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array<string, mixed>>
     */
    private function buildFacadePricingEvidenceFromSnapshot(array $snapshot): array
    {
        $items = [];

        foreach ((array) ($snapshot['facades'] ?? []) as $facade) {
            foreach ((array) ($facade['position_details'] ?? []) as $detail) {
                $method = $detail['price_method'] ?? 'single';
                $presentation = $this->finishedProductFacadeSnapshotPresenter
                    ->presentFromReportFacadeDetail((array) $facade, (array) $detail);
                $quotes = array_values((array) ($detail['quotes'] ?? $presentation['quotes'] ?? []));
                $sourceLevelSnapshot = is_array($detail['source_level_snapshot'] ?? null)
                    ? $detail['source_level_snapshot']
                    : null;

                if ($method === 'single' && empty($quotes)) {
                    continue;
                }

                $items[] = [
                    'id' => $detail['position_id'] ?? $detail['id'] ?? null,
                    'name' => $facade['name'] ?? 'Фасад',
                    'detail_type' => $detail['detail_type'] ?? 'Фасад',
                    'width' => $detail['width'] ?? 0,
                    'length' => $detail['length'] ?? 0,
                    'quantity' => $detail['quantity'] ?? 1,
                    'price_method' => $method,
                    'price_per_m2' => isset($detail['price_per_m2']) ? (float) $detail['price_per_m2'] : 0.0,
                    'price_sources_count' => $detail['price_sources_count'] ?? count($quotes),
                    'price_min' => isset($detail['price_min']) ? (float) $detail['price_min'] : null,
                    'price_max' => isset($detail['price_max']) ? (float) $detail['price_max'] : null,
                    'pricing_basis' => $detail['pricing_basis'] ?? 'legacy_quote',
                    'pricing_snapshot_captured_at' => $detail['pricing_snapshot_captured_at'] ?? null,
                    'pricing_computed_at' => $detail['pricing_computed_at'] ?? null,
                    'source_level_snapshot' => $sourceLevelSnapshot,
                    'facade_snapshot_presentation' => $presentation,
                    'quotes' => $quotes,
                ];
            }
        }

        return $items;
    }

    private function logView(RevisionPublication $publication, Request $request): void
    {
        RevisionPublicationView::create([
            'revision_publication_id' => $publication->id,
            'ip' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
            'viewed_at' => now(),
        ]);
    }

    private function generateQrSvg(string $data): string
    {
        $options = new QROptions([
            'version'      => QRCode::VERSION_AUTO,
            'outputType'   => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel'     => QRCode::ECC_L,
            'scale'        => 5,
            'imageBase64'  => false,
        ]);

        $qrcode = new QRCode($options);
        return $qrcode->render($data);
    }

    private function makePublicVerificationUrl(string $publicId): string
    {
        return rtrim((string) config('app.public_verify_base_url'), '/') . "/v/{$publicId}";
    }
}
