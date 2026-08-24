@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    @foreach ([$urls->catalog(), $urls->producerPrices(), $urls->producerPriceProducts()] as $topLevelUrl)
        <url>
            <loc>{{ $topLevelUrl }}</loc>
            @if ($lastModifiedAt)<lastmod>{{ $lastModifiedAt->toAtomString() }}</lastmod>@endif
        </url>
    @endforeach
@foreach ($entries as $entry)
    <url>
        <loc>{{ $urls->detail($entry->slug) }}</loc>
        <lastmod>{{ $entry->generated_at->toAtomString() }}</lastmod>
    </url>
@endforeach
</urlset>
