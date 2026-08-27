<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Import\Reader;

use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Import\Reader\CsvReader;
use Balin\Tabula\Import\Reader\Reader;
use Balin\Tabula\Import\Reader\ReaderRegistry;
use Balin\Tabula\Import\Reader\XlsxReader;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Okuyucu testleri.
 *
 * Okuyucular içe aktarmanın en sessiz kırılma noktasıdır: yanlış okunan bir dosya hata
 * vermez, YANLIŞ VERİ üretir. Buradaki testlerin hepsi "hiçbir istisna fırlatmadan
 * bozulan" senaryolara karşılık gelir — kayan kolonlar, yutulan BOM, tek hücreye düşen
 * satırlar.
 */
final class ReadersTest extends TestCase
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

    /** @return array<int, list<mixed>> satır numarası => hücreler */
    private function collect(Reader $reader, string $path): array
    {
        $rows = [];

        foreach ($reader->rows($path) as $number => $cells) {
            $rows[$number] = $cells;
        }

        return $rows;
    }

    private function writeCsv(string $name, string $contents): string
    {
        $path = $this->dir->file($name);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Gerçek bir xlsx üretir.
     *
     * Dizeler AÇIK tiple yazılır: varsayılan bağlayıcı "0042"yi sayı sanıp 42'ye çevirir,
     * yani testin ölçmek istediği şeyi ölçmeden bozardı. `null` verilen hücre HİÇ
     * yaratılmaz — dosyada gerçekten boşluk olsun.
     *
     * @param list<list<mixed>> $rows
     */
    private function writeXlsx(string $name, array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $value) {
                if (null === $value) {
                    continue;
                }

                $coordinate = [$columnIndex + 1, $rowIndex + 1];

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }

        $path = $this->dir->file($name);
        (new SpreadsheetXlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function itReadsASemicolonFileWithOneBasedRowNumbers(): void
    {
        $path = $this->writeCsv(
            'noktali-virgul.csv',
            "code;name\n0042;Çiğdem Şahin Ltd. Şti.\n0043;Öz Güneş A.Ş.\n",
        );

        $rows = $this->collect(new CsvReader(), $path);

        // Hata mesajı "37. satır" dediğinde kullanıcı Excel'de 37. satıra bakabilmeli.
        self::assertSame([1, 2, 3], array_keys($rows), 'Satır numaraları 1 tabanlı olmalı.');
        self::assertSame(['code', 'name'], $rows[1]);
        self::assertSame(['0042', 'Çiğdem Şahin Ltd. Şti.'], $rows[2], 'Türkçe metin ve baştaki sıfır korunmalı.');
        self::assertSame(['0043', 'Öz Güneş A.Ş.'], $rows[3]);
    }

    #[Test]
    public function itSniffsACommaFile(): void
    {
        // Kendi yazıcımız ';' yazar, ama kullanıcının başka bir sistemden aldığı dosya ','
        // olabilir. Ayracı sabitlemek satırı TEK hücreye düşürür ve dosya sessizce reddedilir.
        $path = $this->writeCsv('virgul.csv', "code,name\n0042,\"Ankara, Çankaya\"\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame(['code', 'name'], $rows[1]);
        self::assertSame(['0042', 'Ankara, Çankaya'], $rows[2]);
    }

    #[Test]
    public function theDelimiterSniffIgnoresCommasInsideQuotes(): void
    {
        // Tırnaklı tek bir başlık hücresi, sayım tırnak dışında yapılmasaydı dosyayı
        // virgüllü sanmamıza yeter ve tüm sütun eşlemesi bozulurdu.
        $path = $this->writeCsv('tirnakli.csv', "code;\"Ad, Unvan\"\n0042;Test\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame(['code', 'Ad, Unvan'], $rows[1]);
        self::assertSame(['0042', 'Test'], $rows[2]);
    }

    #[Test]
    public function itStripsTheUtf8ByteOrderMark(): void
    {
        // Kendi yazıcımız BOM yazar (Excel dosyayı cp1254 sanmasın diye). Atılmazsa ilk
        // hücre görünmez üç baytla başlar, hiçbir şema anahtarına oturmaz ve dosya
        // "hiçbir kolon tanınmadı" diye reddedilir — üstelik ekranda başlık DOĞRU görünür.
        $path = $this->writeCsv('bom.csv', "\xEF\xBB\xBFcode;name\n0042;Çiğdem\n");

        $rows = $this->collect(new CsvReader(), $path);

        self::assertSame('code', $rows[1][0], 'BOM ilk hücreye yapışmamalı.');
        self::assertSame(['0042', 'Çiğdem'], $rows[2]);
    }

    /**
     * PHP 8.4'ten beri `fgetcsv()`ın kaçış argümanına güvenmek "deprecated" uyarısı üretir.
     *
     * Test paketi zaten `failOnDeprecation` ile çalışıyor, ama o ayar sessizce
     * kapatılabilir; burada uyarı AÇIKÇA yakalanır.
     */
    #[Test]
    public function readingEmitsNoDeprecation(): void
    {
        $path = $this->writeCsv('duz.csv', "code;name\n0042;Test\n");

        /** @var list<string> $deprecations */
        $deprecations = [];

        set_error_handler(
            static function (int $severity, string $message) use (&$deprecations): bool {
                $deprecations[] = $message;

                return true;
            },
            E_DEPRECATED | E_USER_DEPRECATED,
        );

        try {
            $this->collect(new CsvReader(), $path);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $deprecations);
    }

    // ---------------------------------------------------------------- XLSX

    /**
     * ★ BU DOSYADAKİ EN ÖNEMLİ TEST.
     *
     * Boş bir hücre atlanırsa ondan sonraki HER kolon bir sola kayar ve satırın tamamı
     * yanlış alanlara eşlenir: "Miktar" kolonundaki değer "Ünvan" alanına yazılır, üstelik
     * hiçbir hata üretmeden. Bu, içe aktarmanın sessizce yanlış veri yazdığı en sinsi yoldur.
     */
    #[Test]
    public function aBlankMiddleCellKeepsEveryLaterColumnInItsOwnPosition(): void
    {
        $path = $this->writeXlsx('bosluk.xlsx', [
            ['code', 'name', 'qty'],
            ['0042', null, 5],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertCount(3, $rows[2], 'Boş hücre kolon YERİNİ korumalı; satır kısalırsa eşleme kayar.');
        self::assertSame('0042', $rows[2][0]);
        self::assertNull($rows[2][1], 'Boş orta hücre null olmalı, yok sayılmamalı.');
        self::assertEqualsWithDelta(
            5,
            $rows[2][2],
            0.0001,
            'Üçüncü değer hâlâ 2 numaralı indekste olmalı — kayma tüm alan eşlemesini bozar.',
        );
    }

    #[Test]
    public function itReadsTheFirstSheetAndPreservesTurkishText(): void
    {
        $path = $this->writeXlsx('turkce.xlsx', [
            ['code', 'name'],
            ['0042', 'Çiğdem Şahin Ltd. Şti.'],
            ['0043', 'Öz Güneş A.Ş.'],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertSame([1, 2, 3], array_keys($rows));
        self::assertSame(['0042', 'Çiğdem Şahin Ltd. Şti.'], $rows[2]);
    }

    /**
     * Tarih hücresi SERİ NUMARASI olarak gelir, dize olarak değil.
     *
     * Okuyucu ham veriyi taşır; tarihe çevirme kararı alanın tipini bilen ayrıştırıcınındır.
     * Hücre burada dizeye çevrilseydi (eski ERP'nin yaptığı buydu) "45296" anlamsız bir
     * metne dönüşür ve tarih geri kazanılamazdı.
     */
    #[Test]
    public function cellsAreNotStringified(): void
    {
        $path = $this->writeXlsx('tipler.xlsx', [
            ['date', 'qty'],
            [45296, 12.5],
        ]);

        $rows = $this->collect(new XlsxReader(), $path);

        self::assertIsNumeric($rows[2][0]);
        self::assertEqualsWithDelta(45296, $rows[2][0], 0.0001);
        self::assertIsFloat($rows[2][1]);
    }

    // ---------------------------------------------------------------- ortak

    /** @return iterable<string, array{Reader, string}> */
    public static function readersWithMissingFiles(): iterable
    {
        yield 'csv' => [new CsvReader(), 'yok.csv'];
        yield 'xlsx' => [new XlsxReader(), 'yok.xlsx'];
    }

    #[Test]
    #[DataProvider('readersWithMissingFiles')]
    public function aMissingFileIsRejectedByBothReaders(Reader $reader, string $name): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('okunamıyor');

        // Generator gövdesi ancak tüketilince çalışır; sadece `rows()` çağırmak yetmez.
        foreach ($reader->rows($this->dir->file($name)) as $cells) {
            self::fail(sprintf('Var olmayan dosyadan satır geldi: %s', var_export($cells, true)));
        }
    }

    #[Test]
    public function theRegistryPicksTheReaderByExtension(): void
    {
        $registry = ReaderRegistry::default();

        // Seçim UZANTIYA göredir, içeriğe göre değil: .xlsx diye kaydedilmiş bir CSV'ye
        // "uzantısını düzeltin" demek, sessizce kabul edip sonra "kolon eşleşmedi" demekten iyidir.
        self::assertInstanceOf(CsvReader::class, $registry->for('/tmp/musteriler.csv'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/musteriler.xlsx'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/MUSTERILER.XLSX'));
        self::assertInstanceOf(XlsxReader::class, $registry->for('/tmp/eski.xls'));

        self::assertFalse($registry->supports('/tmp/musteriler.txt'));
    }

    #[Test]
    public function anUnknownExtensionIsRefusedWithTheSupportedList(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('tanınmayan bir dosya türü. Desteklenenler: .xlsx, .xls, .csv');

        ReaderRegistry::default()->for('/tmp/musteriler.txt');
    }
}
