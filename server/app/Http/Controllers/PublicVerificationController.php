<?php

namespace App\Http\Controllers;

use App\Models\RevisionPublication;
use App\Models\RevisionPublicationView;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicVerificationController extends Controller
{
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

        // Collect aggregated facade positions with quote evidence
        $facadeQuoteEvidence = $project->positions()
            ->where('kind', 'facade')
            ->where('price_method', '!=', 'single')
            ->with(['facadeMaterial', 'priceQuotes.priceListVersion.priceList.supplier', 'priceQuotes.supplier', 'priceQuotes.materialPrice'])
            ->get()
            ->map(function ($pos) {
                return [
                    'id' => $pos->id,
                    'name' => $pos->facadeMaterial?->name ?? ($pos->decor_label ?? 'Фасад'),
                    'detail_type' => $pos->custom_name ?? 'Фасад',
                    'width' => $pos->width,
                    'length' => $pos->length,
                    'quantity' => $pos->quantity,
                    'price_method' => $pos->price_method,
                    'price_per_m2' => (float) $pos->price_per_m2,
                    'price_sources_count' => $pos->price_sources_count,
                    'price_min' => $pos->price_min ? (float) $pos->price_min : null,
                    'price_max' => $pos->price_max ? (float) $pos->price_max : null,
                    'quotes' => $pos->priceQuotes->map(function ($q) {
                        $v = $q->priceListVersion;
                        $supplier = $q->supplier ?? $v?->priceList?->supplier;
                        $matPrice = $q->materialPrice;
                        return [
                            'price_per_m2' => (float) $q->price_per_m2_snapshot,
                            'price_list_name' => $v?->priceList?->name ?? '—',
                            'version_number' => $v?->version_number,
                            'price_list_version_id' => $v?->id,
                            'source_type' => $v?->source_type,
                            'source_url' => $v?->source_url,
                            'original_filename' => $v?->original_filename,
                            'sha256' => $v?->sha256,
                            'effective_date' => $v?->effective_date?->format('d.m.Y'),
                            'supplier_name' => $supplier?->name ?? '—',
                            'supplier_article' => $matPrice?->article ?? null,
                            'supplier_category' => $matPrice?->category ?? null,
                            'mismatch_flags' => $q->mismatch_flags,
                        ];
                    })->toArray(),
                ];
            })
            ->toArray();

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
