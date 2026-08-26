<?php

namespace Tests\Feature\PriceIndices\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait BuildsConsumerPriceIndexWorkbook
{
    /** @var list<string> */
    private array $consumerPriceIndexFixturePaths = [];

    /**
     * @param  null|callable(Spreadsheet): void  $mutate
     */
    protected function writeConsumerPriceIndexWorkbook(?callable $mutate = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cpi-gate-a-');
        if ($path === false) {
            throw new \RuntimeException('Unable to allocate a CPI test workbook path.');
        }

        $book = new Spreadsheet;
        $contents = $book->getActiveSheet();
        $contents->setTitle('Содержание');
        $contents->setCellValue('A1', 'Содержание:');
        $contents->setCellValue('A3', '1.  Индексы потребительских цен на товары и услуги по Российской Федерации в 1991-1992гг.');
        $contents->setCellValue('A16', 'Обновлено: ');
        $contents->setCellValue('A17', 'Тестовое обновление');

        $categories = [
            '01' => ['1.1', 'товары и услуги', true],
            '02' => ['1.2', 'продовольственные товары', false],
            '03' => ['1.3', 'непродовольственные товары', false],
            '04' => ['1.4', 'услуги', false],
        ];

        $position = 0;
        foreach ($categories as $sheetName => [$contentsNumber, $title, $hasDefinitionNote]) {
            $row = $position + 4;
            $contents->setCellValue(
                "A{$row}",
                "{$contentsNumber} Индексы потребительских цен на {$title} по Российской Федерации в 1991-1992 гг.",
            );

            $sheet = $book->createSheet();
            $sheet->setTitle($sheetName);
            $this->populateConsumerPriceIndexSheet(
                $sheet,
                $title,
                $hasDefinitionNote,
                $position + 1,
            );
            $position++;
        }

        if ($mutate !== null) {
            $mutate($book);
        }

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->consumerPriceIndexFixturePaths[] = $path;

        return $path;
    }

    protected function cleanupConsumerPriceIndexWorkbooks(): void
    {
        foreach ($this->consumerPriceIndexFixturePaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->consumerPriceIndexFixturePaths = [];
    }

    private function populateConsumerPriceIndexSheet(
        Worksheet $sheet,
        string $title,
        bool $hasDefinitionNote,
        int $seriesOffset,
    ): void {
        $definitionMarker = $hasDefinitionNote ? '1)' : '';
        $sheet->setCellValue(
            'A1',
            "Индексы потребительских цен на {$title}{$definitionMarker} по Российской Федерации в 1991-1992*)гг.",
        );
        $sheet->setCellValue('A2', 'К содержанию');
        $sheet->setCellValue('AI3', 'на конец периода, в %');
        $sheet->setCellValue('B4', 1991);
        $sheet->setCellValue('C4', 1992);
        $sheet->setCellValue('A5', 'к концу предыдущего месяца');

        $months = [
            'январь',
            'февраль',
            'март',
            'апрель',
            'май',
            'июнь',
            'июль',
            'август',
            'сентябрь',
            'октябрь',
            'ноябрь',
            'декабрь',
        ];
        foreach ($months as $offset => $month) {
            $row = $offset + 6;
            $sheet->setCellValue("A{$row}", $month);
            $sheet->setCellValue("B{$row}", 100 + $seriesOffset + (($offset + 1) / 100));
            if ($offset < 7) {
                $sheet->setCellValue("C{$row}", 101 + $seriesOffset + (($offset + 1) / 100));
            }
        }

        $sheet->setCellValue('A18', 'к декабрю предыдущего года');
        $sheet->setCellValue('A19', 'декабрь');
        $sheet->setCellValue('B19', 'unsupported previous-December value');
        $sheet->setCellValue('C19', '=100+1');
        $sheet->setCellValue(
            'A20',
            'Обращаем Ваше внимание, что в январе 1998 г. была проведена деноминация, в результате которой произошло уменьшение масштаба цен в 1000 раз.',
        );
        $sheet->setCellValue(
            'A22',
            '*)Без учета статистической информации по Донецкой Народной Республике, Луганской Народной Республике, Запорожской и Херсонской областям.',
        );
    }
}
