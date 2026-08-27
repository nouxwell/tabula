<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Sheet\ChunkedSheets;
use Balin\Tabula\Export\Sheet\GroupedSheets;
use Balin\Tabula\Export\Writer\CsvWriter;
use Balin\Tabula\Export\Writer\XlsxWriter;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Source\IteratorSource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\Status;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use DateTimeImmutable;
use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Uçtan uca boru hattı testleri.
 *
 * Bu dosyanın var olma sebebi: biçimlendiriciler, yazıcılar ve `ExportBuilder` paralel
 * yazıldı ve HİÇ testleri yoktu; bir inceleme turu yedi gerçek kusur çıkardı. Aşağıdaki
 * testlerin çoğu o kusurların birebir regresyon karşılığıdır.
 */
final class ExportPipelineTest extends TestCase
{
    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    // ---------------------------------------------------------------- yardımcılar

    private function tabula(?TabulaSettings $settings = null): Tabula
    {
        return new Tabula(
            new ArrayTranslator([
                'tr' => [
                    'col.code' => 'Kod',
                    'col.name' => 'Ünvan',
                    'col.qty' => 'Miktar',
                    'col.balance' => 'Bakiye',
                    'col.active' => 'Aktif',
                    'col.status' => 'Durum',
                    'col.created' => 'Oluşturma',
                    'status.open' => 'Açık',
                    'status.closed' => 'Kapalı',
                    'tabula.bool.yes' => 'Evet',
                    'tabula.bool.no' => 'Hayır',
                ],
            ]),
            $settings ?? new TabulaSettings(
                numbers: new NumberSettings(
                    currencySymbols: ['TRY' => '₺'],
                    symbolPosition: SymbolPosition::After,
                ),
            ),
        );
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code')->width(14)->required(),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
            Field::money('balance')->label('col.balance')
                ->currency(static fn (array $row): string => $row['currencyCode']),
            Field::bool('isActive')->label('col.active'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::date('createdAt')->label('col.created'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            [
                'code' => '0042',
                'name' => 'Çiğdem Şahin Ltd. Şti.',
                'qty' => 12.5,
                'balance' => 1234.56,
                'currencyCode' => 'TRY',
                'isActive' => true,
                'status' => Status::Open,
                'createdAt' => new DateTimeImmutable('2024-01-05 13:45:00'),
            ],
            [
                'code' => '0043',
                'name' => 'Öz Güneş A.Ş.',
                'qty' => 3,
                'balance' => -99.9,
                'currencyCode' => 'TRY',
                'isActive' => false,
                'status' => Status::Closed,
                'createdAt' => new DateTimeImmutable('2024-02-11 09:00:00'),
            ],
        ];
    }

    // ---------------------------------------------------------------- en küçük halka

    #[Test]
    public function itWritesAWorkbookFromAnArraySource(): void
    {
        $path = $this->dir->file('musteriler.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        self::assertFileExists($path);
        self::assertSame($path, $result->path());
        self::assertSame(2, $result->rows);
        self::assertSame(1, $result->sheets);
        self::assertFalse($result->isEmpty());

        $sheet = IOFactory::load($path)->getActiveSheet();

        // Başlıklar sunucuda, istenen dilde üretilir.
        self::assertSame('Kod', $sheet->getCell('A1')->getValue());
        self::assertSame('Ünvan', $sheet->getCell('B1')->getValue());
        self::assertSame('Bakiye', $sheet->getCell('D1')->getValue());

        // Baştaki sıfır korunmalı: Excel'in "sayıya benzeyeni sayıya çevir" davranışı
        // cari kodları yiyen klasik hatadır.
        self::assertSame('0042', $sheet->getCell('A2')->getValue());
        self::assertSame('Çiğdem Şahin Ltd. Şti.', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function numericCellsStayRealNumbersSoExcelCanSumThem(): void
    {
        $path = $this->dir->file('sayilar.xlsx');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertEqualsWithDelta(12.5, $sheet->getCell('C2')->getValue(), 0.0001);
        self::assertEqualsWithDelta(1234.56, $sheet->getCell('D2')->getValue(), 0.0001);
        self::assertIsFloat($sheet->getCell('D2')->getValue());

        // Simge SAYIYA değil, biçim koduna girer — hücre toplanabilir kalır.
        self::assertStringContainsString('₺', (string) $sheet->getStyle('D2')->getNumberFormat()->getFormatCode());
    }

    #[Test]
    public function boolsAndEnumsAreWrittenInTheRequestedLanguage(): void
    {
        $path = $this->dir->file('ceviri.xlsx');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertSame('Evet', $sheet->getCell('E2')->getValue());
        self::assertSame('Hayır', $sheet->getCell('E3')->getValue());

        // Enum çevirisi `label()` gelenegi üzerinden çözülür (TranslatableEnum olmadan).
        self::assertSame('Açık', $sheet->getCell('F2')->getValue());
        self::assertSame('Kapalı', $sheet->getCell('F3')->getValue());
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function csvIsWrittenWithBomAndSemicolonSoTurkishExcelReadsIt(): void
    {
        $path = $this->dir->file('musteriler.csv');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')->to(Format::Csv)->write($path);

        $contents = (string) file_get_contents($path);

        // BOM olmadan Excel dosyayı cp1254 sanır ve ş/ğ/ı/İ/ö/ç/ü bozulur.
        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Çiğdem Şahin', $contents);

        // Ayraç `;`: tr/Avrupa Excel'inde `,` ONDALIK ayraçtır ve sayıyı iki kolona böler.
        self::assertStringContainsString('Kod;Ünvan;', $contents);

        // CSV yerelleştirilmiş METNİ yazar, ham değeri değil.
        self::assertStringContainsString('Evet', $contents);
        self::assertStringContainsString('1.234,56', $contents);
    }

    // ---------------------------------------------------------------- kolon seçimi

    #[Test]
    public function onlyWritesTheRequestedColumnsInTheRequestedOrder(): void
    {
        $path = $this->dir->file('secim.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->only(['balance', 'code'])
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(['balance', 'code'], $result->columns);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('Bakiye', $sheet->getCell('A1')->getValue());
        self::assertSame('Kod', $sheet->getCell('B1')->getValue());
        self::assertNull($sheet->getCell('C1')->getValue());
    }

    // ---------------------------------------------------------------- sayfalar

    #[Test]
    public function chunkedStrategySplitsIntoRealSheetsInXlsxAndFilesInCsv(): void
    {
        $rows = array_map(
            static fn (int $i): array => ['code' => (string) $i],
            range(1, 5),
        );
        $schema = Schema::make('n')->fields(Field::string('code')->label('col.code'));

        $xlsx = $this->dir->file('parca.xlsx');
        $result = $this->tabula()->export($schema)
            ->from(ArraySource::of($rows))
            ->sheets(new ChunkedSheets(2))
            ->locale('tr')->to(Format::Xlsx)->write($xlsx);

        self::assertSame(3, $result->sheets);
        self::assertCount(3, IOFactory::load($xlsx)->getSheetNames());

        $csv = $this->dir->file('parca.csv');
        $csvResult = $this->tabula()->export($schema)
            ->from(ArraySource::of($rows))
            ->sheets(new ChunkedSheets(2))
            ->locale('tr')->to(Format::Csv)->write($csv);

        // CSV sayfa taşıyamaz: her parça ayrı DOSYA olur.
        self::assertCount(3, $csvResult->paths);
        self::assertTrue($csvResult->isMultiFile());
        foreach ($csvResult->paths as $file) {
            self::assertFileExists($file);
        }
    }

    #[Test]
    public function groupedSheetsResolveFlatProjectionKeysAndKeepFalseSeparateFromNull(): void
    {
        // Doctrine projeksiyonları düz `c.warehouse` anahtarı döndürür; strateji bunu
        // ValueResolver ile okumalı (elle yazılmış bir kopya bunu kaçırıyordu).
        $rows = [
            ['code' => 'a', 'c.warehouse' => 'Merkez'],
            ['code' => 'b', 'c.warehouse' => 'Merkez'],
            ['code' => 'c', 'c.warehouse' => 'Şube'],
        ];

        $path = $this->dir->file('grup.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of($rows))
            ->sheets(new GroupedSheets('c.warehouse'))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(2, $result->sheets);
        self::assertSame(['Merkez', 'Şube'], IOFactory::load($path)->getSheetNames());
    }

    #[Test]
    public function groupedSheetsDoNotMergeFalseIntoTheNullBucket(): void
    {
        $rows = [
            ['code' => 'a', 'flag' => false],
            ['code' => 'b', 'flag' => null],
        ];

        $path = $this->dir->file('bayrak.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of($rows))
            ->sheets(new GroupedSheets('flag'))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        // `(string) false === ''` olduğu için ikisi de yedek ada düşerse "hayır" grubu ile
        // "değeri yok" grubu aynı sayfada birleşir — sessiz veri kaybı.
        self::assertSame(2, $result->sheets);
    }

    // ---------------------------------------------------------------- kenar durumlar

    #[Test]
    public function anEmptyResultStillProducesAHeaderOnlySheet(): void
    {
        $path = $this->dir->file('bos.xlsx');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of([]))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertTrue($result->isEmpty());
        self::assertSame(1, $result->sheets);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('Kod', $sheet->getCell('A1')->getValue());
        self::assertNull($sheet->getCell('A2')->getValue());
    }

    #[Test]
    public function anEmptyResultDoesNotFeedANullRowToATypedGroupingClosure(): void
    {
        // README'deki her kapanış `fn (array $row)` biçiminde; boş sonuçta stratejiye
        // uydurma bir `null` satır geçirmek doğrudan TypeError'a dönüyordu.
        $strategy = new GroupedSheets(static fn (array $row): string => (string) $row['g']);

        $path = $this->dir->file('bos-grup.xlsx');
        $result = $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
            ->from(ArraySource::of([]))
            ->sheets($strategy)
            ->locale('tr')->to(Format::Xlsx)->write($path);

        self::assertSame(1, $result->sheets);
        self::assertFileExists($path);
    }

    #[Test]
    public function anEmptyTextPlaceholderIsActuallyWrittenToTheCell(): void
    {
        $settings = new TabulaSettings(emptyText: '-');

        $path = $this->dir->file('bosluk.xlsx');
        $this->tabula($settings)->export(
            Schema::make('n')->fields(
                Field::string('code')->label('col.code'),
                Field::quantity('qty')->label('col.qty'),
            ),
        )
            ->from(ArraySource::of([['code' => null, 'qty' => null]]))
            ->locale('tr')->to(Format::Xlsx)->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();
        self::assertSame('-', $sheet->getCell('A2')->getValue());
        self::assertSame('-', $sheet->getCell('B2')->getValue());
    }

    #[Test]
    public function aSchemaLeftWithNoColumnsForTheFormatFailsLoudly(): void
    {
        $schema = Schema::make('n')->fields(
            Field::string('code')->label('col.code')->only(Format::Pdf),
        );

        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/kolon kalmadı/');

        $this->tabula()->export($schema)
            ->from(ArraySource::of([['code' => 'x']]))
            ->locale('tr')->to(Format::Xlsx)->write($this->dir->file('kolonsuz.xlsx'));
    }

    #[Test]
    public function aSourceThatThrowsMidExportStillReleasesTheWriter(): void
    {
        $writer = new CsvWriter();
        $path = $this->dir->file('yarim.csv');

        $exploding = IteratorSource::of(static function (): Generator {
            yield ['code' => 'a'];

            throw new RuntimeException('kaynak patladı');
        });

        try {
            $this->tabula()->export(Schema::make('n')->fields(Field::string('code')->label('col.code')))
                ->from($exploding)
                ->writer($writer)
                ->locale('tr')->to(Format::Csv)->write($path);

            self::fail('Kaynağın istisnası yukarı taşınmalıydı.');
        } catch (RuntimeException $e) {
            self::assertSame('kaynak patladı', $e->getMessage());
        }

        // Yazıcı serbest bırakılmış olmalı: aynı örnek yeniden açılabiliyorsa tanıtıcı
        // kapanmış demektir. Kapanmasaydı dosya tanıtıcısı sızar, yazıcı "zaten açık" diye
        // reddeder ve kullanıcının verdiği yazıcı bir daha kullanılamaz hâle gelirdi.
        $reopenFailed = false;

        try {
            $writer->open($this->dir->file('yeniden.csv'));
        } catch (Throwable) {
            $reopenFailed = true;
        } finally {
            $writer->close();
        }

        self::assertFalse($reopenFailed, 'Yazıcı serbest bırakılmamış: ikinci open() reddedildi.');
    }

    #[Test]
    public function aDateInAStringFieldDoesNotKillTheExport(): void
    {
        // Şema hatası boş/nötr hücre olarak görünmeli, çöken bir dışa aktarma olarak değil.
        $path = $this->dir->file('tarih-metin.csv');

        $result = $this->tabula()->export(
            Schema::make('n')->fields(Field::string('whenever')->label('col.created')),
        )
            ->from(ArraySource::of([['whenever' => new DateTimeImmutable('2024-03-01 08:00:00')]]))
            ->locale('tr')->to(Format::Csv)->write($path);

        self::assertSame(1, $result->rows);
        self::assertStringContainsString('2024-03-01', (string) file_get_contents($path));
    }

    #[Test]
    public function theCellIsBuiltIndependentlyOfTheChosenWriter(): void
    {
        // `to()` ile `writer()` çelişebilir (ikisi de açık API). Hücre biçimden bağımsız
        // üretilmezse tarihler Excel'e METİN olarak düşer ve alfabetik sıralanır.
        $path = $this->dir->file('capraz.xlsx');

        $this->tabula()->export(
            Schema::make('n')->fields(Field::date('createdAt')->label('col.created')),
        )
            ->from(ArraySource::of([['createdAt' => new DateTimeImmutable('2024-01-05')]]))
            ->to(Format::Csv)          // biçim CSV der…
            ->writer(new XlsxWriter()) // …ama yazıcı Xlsx
            ->locale('tr')
            ->write($path);

        $cell = IOFactory::load($path)->getActiveSheet()->getCell('A2');

        self::assertIsFloat($cell->getValue());
        self::assertEqualsWithDelta(45296.0, $cell->getValue(), 0.5);
    }
}
