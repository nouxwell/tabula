<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Bridge\Symfony;

use Balin\Tabula\Bridge\Symfony\SettingsFactory;
use Balin\Tabula\Bridge\Symfony\TabulaBundle;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Format;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\PdfDocument;
use Balin\Tabula\Tests\Fixture\StubSymfonyTranslator;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Throwable;

/**
 * Yazıcı ayarlarının `tabula.yaml`'dan YAZILAN DOSYAYA kadar gittiğini doğrular.
 *
 * Ayarın değer nesnesine ulaştığını görmek yetmez: bu refactor'ün var olma sebebi, doğrulanan
 * ve taşınan ama kimsenin OKUMADIĞI ölü bir ayardı (`max_rows_per_sheet`). Bu yüzden buradaki
 * iddialar konteynerden çıkan `Tabula` ile gerçek dosya yazıp baytlara/çalışma kitabına bakar.
 */
final class WriterConfigurationTest extends TestCase
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

    /**
     * @param array<string, mixed> $config
     */
    private function tabula(array $config = []): Tabula
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.default_locale', 'tr');
        $container->set(TranslatorInterface::class, new StubSymfonyTranslator([]));

        $bundle = new TabulaBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $extension->load([$config], $container);

        $container->getDefinition(Tabula::class)->setPublic(true);
        $container->compile();

        $tabula = $container->get(Tabula::class);
        self::assertInstanceOf(Tabula::class, $tabula);

        return $tabula;
    }

    private function schema(): Schema
    {
        return Schema::make('t')->fields(
            Field::string('code')->label('Kod')->required(),
            Field::string('name')->label('Ünvan'),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function write(array $config, Format $format, string $file): string
    {
        $path = $this->dir->file($file);

        $this->tabula($config)->export($this->schema())
            ->from(ArraySource::of([['code' => 'A1', 'name' => 'Çiğdem Şahin']]))
            ->locale('tr')
            ->to($format)
            ->write($path);

        return $path;
    }

    /**
     * PDF için ayrı bir yazma yolu: kâğıt bütçesinin görünür olması altı kolon ister.
     *
     * @param array<string, mixed> $config
     */
    private function writePdf(array $config, string $file): string
    {
        $path = $this->dir->file($file);

        $schema = Schema::make('t')->fields(
            Field::string('code')->label('Kod'),
            Field::string('name')->label('Ünvan'),
            Field::string('city')->label('Şehir'),
            Field::string('phone')->label('Telefon'),
            Field::string('email')->label('E-posta'),
            Field::string('note')->label('Not'),
        );

        $this->tabula($config)->export($schema)
            ->from(ArraySource::of([[
                'code' => 'A1',
                'name' => 'Çiğdem Şahin',
                'city' => 'İstanbul',
                'phone' => '0212 000 00 00',
                'email' => 'info@ornek.test',
                'note' => 'Şubat ayında görüşülecek',
            ]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        return $path;
    }

    // ---------------------------------------------------------------- CSV

    #[Test]
    public function csvDefaultsProduceASemicolonFileWithABom(): void
    {
        $contents = (string) file_get_contents($this->write([], Format::Csv, 'varsayilan.csv'));

        self::assertStringStartsWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Kod;Ünvan', $contents);
        self::assertStringContainsString("\r\n", $contents);
    }

    #[Test]
    public function csvConfigurationReachesTheWrittenFile(): void
    {
        // Makineye giden besleme: RFC 4180 — virgül, BOM yok, LF.
        $config = ['csv' => [
            'delimiter' => ',',
            'escape' => '',
            'write_bom' => false,
            'line_ending' => 'lf',
        ]];

        $contents = (string) file_get_contents($this->write($config, Format::Csv, 'makine.csv'));

        self::assertStringStartsNotWith("\xEF\xBB\xBF", $contents);
        self::assertStringContainsString('Kod,Ünvan', $contents);
        self::assertStringNotContainsString("\r", $contents);
    }

    #[Test]
    public function anEscapeWrittenAsNullIsReadAsDisabledRatherThanCrashing(): void
    {
        // `escape: ~` yazan kişi "kaçışı kapat" demek istiyordur; ham null bir TypeError'du.
        $contents = (string) file_get_contents(
            $this->write(['csv' => ['escape' => null]], Format::Csv, 'kacissiz.csv'),
        );

        self::assertStringContainsString('Kod;Ünvan', $contents);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCsvCharacters(): iterable
    {
        // En sık tuzak: YAML tek tırnakta '\\' İKİ karakterdir.
        yield 'iki karakterli kaçış' => [['escape' => '\\\\']];
        yield 'iki karakterli ayraç' => [['delimiter' => '||']];
        yield 'çok baytlı ayraç' => [['delimiter' => 'ş']];
    }

    /**
     * @param array<string, mixed> $csv
     */
    #[Test]
    #[DataProvider('invalidCsvCharacters')]
    public function anInvalidCsvCharacterFailsAtSetupNotHalfwayThroughTheFile(array $csv): void
    {
        // Yazma anında patlarsa hata ham bir ValueError olur (TabulaException DEĞİL) ve
        // diskte yalnız BOM içeren bir kalıntı bırakır.
        $this->expectException(WriterException::class);

        $this->write(['csv' => $csv], Format::Csv, 'olmaz.csv');
    }

    // ---------------------------------------------------------------- XLSX

    #[Test]
    public function xlsxDefaultsFreezeTheHeaderAndAddAnAutoFilter(): void
    {
        $sheet = IOFactory::load($this->write([], Format::Xlsx, 'varsayilan.xlsx'))->getActiveSheet();

        self::assertSame('A2', $sheet->getFreezePane());
        self::assertNotSame('', $sheet->getAutoFilter()->getRange());
        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    #[Test]
    public function xlsxConfigurationCanTurnTheDecorationOff(): void
    {
        $config = ['xlsx' => [
            'creator' => 'Ionsis ERP',
            'bold_header' => false,
            'freeze_header' => false,
            'auto_filter' => false,
        ]];

        $spreadsheet = IOFactory::load($this->write($config, Format::Xlsx, 'sade.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();

        self::assertNull($sheet->getFreezePane());
        self::assertSame('', (string) $sheet->getAutoFilter()->getRange());
        self::assertFalse($sheet->getStyle('A1')->getFont()->getBold());
        self::assertSame('Ionsis ERP', $spreadsheet->getProperties()->getCreator());

        // Süsler kapalıyken bile kolon hizalaması uygulanmalı: hizalama, otomatik filtre
        // koşulunun İÇİNDE kalırsa sessizce düşerdi.
        self::assertNotSame('', (string) $sheet->getStyle('A2')->getAlignment()->getHorizontal());
    }

    #[Test]
    public function customHeaderColoursReachTheWorkbookAndRequiredStillWins(): void
    {
        $path = $this->write(
            ['xlsx' => ['header_fill' => 'FFDDEEFF', 'required_header_fill' => 'FFAA0000']],
            Format::Xlsx,
            'renk.xlsx',
        );
        $sheet = IOFactory::load($path)->getActiveSheet();

        // A = `code`, ZORUNLU alan → zorunlu rengi genel başlık rengini ezer.
        self::assertSame('FFAA0000', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());
        // B = `name`, zorunlu değil → genel başlık rengi.
        self::assertSame('FFDDEEFF', $sheet->getStyle('B1')->getFill()->getStartColor()->getARGB());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidColours(): iterable
    {
        // PhpSpreadsheet ayrıştıramadığı rengi SESSİZCE yutar: '#...' başlığı bembeyaz
        // bırakır, boş dize ise simsiyah bir bant basar. İkisi de kabul edilmemeli.
        yield 'css diyezi' => ['#F2F2F2'];
        yield 'renk adı' => ['lightgray'];
        yield 'boş' => [''];
    }

    #[Test]
    #[DataProvider('invalidColours')]
    public function anInvalidColourIsRejectedInsteadOfSilentlyPaintingSomethingElse(string $colour): void
    {
        // Boş dize config ağacında (cannotBeEmpty), diğerleri değer nesnesinde reddedilir;
        // ikisi de kurulum anında ve gürültüyle patlamalı.
        $this->expectException(Throwable::class);

        $this->write(['xlsx' => ['header_fill' => $colour]], Format::Xlsx, 'kotu-renk.xlsx');
    }

    // ---------------------------------------------------------------- PDF

    /**
     * Varsayılan A4 YATAY olmalı.
     *
     * Mevcut ERP tüm listeleri A4 dikey basıyordu; on kolonlu bir fatura listesinin son
     * kolonları kâğıdın dışında kalıyor, kullanıcı eksiği ancak Excel çıktısıyla
     * karşılaştırınca fark ediyordu. Yatay kâğıt kullanılabilir eni 190 → 277 mm yapar.
     */
    #[Test]
    public function pdfDefaultsProduceAnA4LandscapePage(): void
    {
        PdfDocument::at($this->writePdf([], 'varsayilan.pdf'))->assertPaper(Page::a4()->landscape());
    }

    #[Test]
    public function pdfConfigurationReachesTheWrittenFile(): void
    {
        $config = ['pdf' => ['page_size' => 'a5', 'orientation' => 'portrait']];

        PdfDocument::at($this->writePdf($config, 'a5.pdf'))->assertPaper(Page::a5());
    }

    /**
     * Kolon bütçesi de yapılandırmadan gelmeli.
     *
     * Altı kolon, sayfa başına en fazla iki kolon → üç sayfa takımı, yani üç kâğıt.
     * `max_columns` taşınmasaydı A5'e üç kolon sığar ve iki kâğıt çıkardı.
     */
    #[Test]
    public function thePdfColumnBudgetFromConfigurationReachesTheWrittenFile(): void
    {
        $config = ['pdf' => [
            'page_size' => 'a5',
            'orientation' => 'portrait',
            'min_column_width_mm' => 40.0,
            'max_columns' => 2,
        ]];

        PdfDocument::at($this->writePdf($config, 'bolunmus.pdf'))->assertPageCount(3);
    }

    /**
     * `max_columns: ~` "tavan yok" demektir, çökme sebebi değil.
     *
     * `csv.escape` ile birebir aynı tuzak: Symfony'nin `IntegerNode`u null'ı "Expected int,
     * but got null" diye reddeder, oysa devralınan bir değeri geri almanın tek yolu `~`
     * yazmaktır. Bu yüzden düğüm `integerNode` değil, doğrulamalı bir `scalarNode`.
     */
    #[Test]
    public function aMaxColumnsWrittenAsNullIsReadAsNoCapRatherThanCrashing(): void
    {
        $config = ['pdf' => ['page_size' => 'a5', 'orientation' => 'portrait', 'max_columns' => null]];

        // A5 dikeyde 128 mm / 22 mm = 5 kolon; altı kolon iki gruba (5 + 1) düşer.
        PdfDocument::at($this->writePdf($config, 'tavansiz.pdf'))->assertPageCount(2);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidPdfConfigurations(): iterable
    {
        yield 'bilinmeyen kâğıt' => [['page_size' => 'a2'], 'tabula.pdf.page_size'];
        yield 'bilinmeyen yön' => [['orientation' => 'yatay'], 'tabula.pdf.orientation'];
        yield 'bilinmeyen taşma' => [['overflow' => 'wrap'], 'tabula.pdf.overflow'];
        // Yazı tipi boş kalırsa Dompdf Latin-1 çekirdek yazı tipine düşer ve "ş ğ ı İ"
        // harfleri SESSİZCE basılmaz.
        yield 'boş yazı tipi' => [['font_family' => ''], 'tabula.pdf.font_family'];
        yield 'null yazı tipi' => [['font_family' => null], 'tabula.pdf.font_family'];
        yield 'sıfır kolon tavanı' => [['max_columns' => 0], 'tabula.pdf.max_columns'];
        yield 'metin kolon tavanı' => [['max_columns' => 'hepsi'], 'tabula.pdf.max_columns'];
    }

    /**
     * @param array<string, mixed> $pdf
     */
    #[Test]
    #[DataProvider('invalidPdfConfigurations')]
    public function theConfigurationTreeRejectsBadPdfValues(array $pdf, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($path, '/').'/');

        $this->tabula(['pdf' => $pdf]);
    }

    /**
     * Aralık kuralı ağaçta değil, DEĞER NESNESİNDE yaşar (bkz. `pdf` düğümündeki not).
     *
     * Ama yaşadığı yerde gerçekten çalışmalı: sıfır punto Dompdf'te istisna fırlatmaz,
     * satır yüksekliğini sıfır hesaplar ve tabloyu görünmez bir şeride indirir — kullanıcıya
     * "boş PDF" olarak döner ve nedeni çıktıya bakarak anlaşılamaz.
     */
    #[Test]
    public function aNonPositiveFontSizeIsRejectedByTheValueObject(): void
    {
        $this->expectException(WriterException::class);

        $this->writePdf(['pdf' => ['font_size_pt' => 0.0]], 'gorunmez.pdf');
    }

    // ---------------------------------------------------------------- config ağacı

    #[Test]
    public function anUnknownLineEndingNameIsRejectedByTheConfigTree(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->tabula(['csv' => ['line_ending' => 'LF']]);
    }

    #[Test]
    public function theFactoryRefusesAnUnknownLineEndingNameInsteadOfFallingBackToCrlf(): void
    {
        // `SettingsFactory` public statik bir API; config ağacına yarın yeni bir ad eklenirse
        // üçlü operatör onu sessizce CRLF'e düşürürdü.
        $this->expectException(InvalidArgumentException::class);

        SettingsFactory::csv([
            'delimiter' => ';',
            'enclosure' => '"',
            'escape' => '\\',
            'write_bom' => true,
            'line_ending' => 'bilinmeyen',
        ]);
    }

    #[Test]
    public function theLineEndingNamesMapToTheRightBytes(): void
    {
        $base = ['delimiter' => ';', 'enclosure' => '"', 'escape' => '\\', 'write_bom' => true];

        self::assertSame("\n", SettingsFactory::csv([...$base, 'line_ending' => 'lf'])->lineEnding);
        self::assertSame("\r\n", SettingsFactory::csv([...$base, 'line_ending' => 'crlf'])->lineEnding);
    }
}
