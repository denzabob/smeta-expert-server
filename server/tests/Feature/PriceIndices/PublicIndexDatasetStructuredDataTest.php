<?php

namespace Tests\Feature\PriceIndices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PriceIndices\Support\BuildsPublicSeoFixture;
use Tests\Feature\PriceIndices\Support\ParsesPublicStructuredData;
use Tests\TestCase;

class PublicIndexDatasetStructuredDataTest extends TestCase
{
    use BuildsPublicSeoFixture;
    use DatabaseTransactions;
    use ParsesPublicStructuredData;

    public function test_detail_schema_describes_exact_dataset_variable_period_provider_and_territory(): void
    {
        $fixture = $this->publicSeoFixture();
        $fixture['dataset']->update(['provider_name' => 'Росстат']);
        $fixture['territory']->update(['name' => 'Российская Федерация']);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get('https://indices.test/'.$fixture['page']->slug);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $schema = $this->structuredData($response);
        $webPage = $this->graphEntity($schema, 'WebPage');
        $dataset = $this->graphEntity($schema, 'Dataset');
        $variable = $this->graphEntity($schema, 'StatisticalVariable');

        $this->assertSame('https://indices.test/31-02-10-140', $webPage['url']);
        $this->assertSame(['@id' => 'https://indices.test/31-02-10-140#dataset'], $webPage['mainEntity']);
        $this->assertSame('31.02.10.140', $dataset['identifier']);
        $this->assertSame('2025-01/2025-12', $dataset['temporalCoverage']);
        $this->assertSame('Росстат', $dataset['provider']['name']);
        $this->assertSame('Российская Федерация', $dataset['spatialCoverage']['name']);
        $this->assertSame('RU', $dataset['spatialCoverage']['identifier']);
        $this->assertSame($fixture['page']->generated_at->toAtomString(), $dataset['dateModified']);
        $this->assertSame('Индекс цен производителей к предыдущему месяцу', $dataset['measurementTechnique']);
        $this->assertSame('Индекс цен производителей', $variable['name']);
        $this->assertSame('процент', $variable['unitText']);
        $this->assertSame(['@id' => $variable['@id']], $dataset['variableMeasured']);
        $this->assertLessThanOrEqual(7, $queries);

        $encoded = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString($fixture['sourceFile']->stored_path, $encoded);
        $this->assertStringNotContainsString('app.test', $encoded);
        $this->assertArrayNotHasKey('id', $dataset);
        $this->assertArrayNotHasKey('series_id', $dataset);
        $this->assertArrayNotHasKey('import_id', $dataset);
    }

    public function test_rosstat_ag_identifier_is_preserved_in_dataset_schema(): void
    {
        $fixture = $this->publicSeoFixture('05.10.10.101.АГ', 'Уголь местной классификации');
        $dataset = $this->graphEntity(
            $this->structuredData($this->get('https://indices.test/05-10-10-101-ag')),
            'Dataset',
        );

        $this->assertSame('05.10.10.101.АГ', $dataset['identifier']);
        $this->assertSame('https://indices.test/05-10-10-101-ag', $dataset['url']);
        $this->assertSame('https://indices.test/05-10-10-101-ag#dataset', $dataset['@id']);
        $this->assertSame($fixture['page']->generated_at->toAtomString(), $dataset['dateModified']);
    }
}
