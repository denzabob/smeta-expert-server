@php echo '<?xml version="1.0" encoding="UTF-8"?>'; @endphp
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($entries as $entry)
    <url>
        <loc>{{ $urls->detail($entry->slug) }}</loc>
        <lastmod>{{ $entry->generated_at->toAtomString() }}</lastmod>
    </url>
@endforeach
</urlset>
