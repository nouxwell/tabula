<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\ExportBuilder;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Writer\PdfOptions;
use Balin\Tabula\Export\Writer\PdfWriter;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\PdfDocument;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `->page()` ve `->columns()` ayarlarının GERÇEKTEN kâğıda indiğini doğrular.
 *
 * Bu dosyanın tamamı tek bir hataya karşıdır: **sessizce uygulanmayan sayfa ayarı.** Mevcut
 * ERP'de sayfa boyutu hem PHP'de (`Dompdf::setPaper()`) hem şablonun `@page` kuralında
 * tanımlıydı; Dompdf render sırasında CSS'i uyguladığı için `setPaper()` fiilen dekoratifti
 * ve journal çıktısı A4 sanılırken A5 basılıyordu. Kimse fark etmedi, çünkü hiçbir yerde
 * gürültü çıkmıyordu.
 *
 * Bu yüzden iddialar "nesne kuruldu" düzeyinde DEĞİL, üretilen PDF'in baytları üzerinden
 * kurulur; okuma işini `PdfDocument` yapar. Beklenen ölçü de elle yazılmaz, `Page`in
 * kendisinden türetilir — yoksa test koda değil, kendi kopyaladığı kâğıt tablosuna bakardı.
 */
final class PageSettingsTest extends TestCase
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

    // ---------------------------------------------------------------- sayfa gerçekten iniyor mu

    /**
     * Ayar hiç verilmediğinde `PdfOptions`ın varsayılanı geçerli olmalı: A4 YATAY.
     *
     * Aşağıdaki A5 testi, `->page()` hiçbir işe yaramasa bile kâğıdın "A5 olmadığını"
     * gösterirdi; bu test varsayılanın ne olduğunu ayrıca sabitler.
     */
    #[Test]
    public function withoutAnyPageSettingThePdfIsA4Landscape(): void
    {
        $path = $this->dir->file('varsayilan.pdf');

        $this->export()->to(Format::Pdf)->write($path);

        PdfDocument::at($path)->assertPaper(Page::a4()->landscape());
    }

    #[Test]
    public function theRequestedPageSizeReachesTheProducedPdf(): void
    {
        $path = $this->dir->file('a5.pdf');

        $this->export()->to(Format::Pdf)->page(Page::a5())->write($path);

        PdfDocument::at($path)->assertPaper(Page::a5());
    }

    /**
     * Kolon bütçesi de inmeli — ve indiği KÂĞIT SAYISINDAN okunur.
     *
     * A5 dikeyde kullanılabilir en 148 − 2×10 = 128 mm.
     *
     *  - 40 mm asgari kolonla bütçe 3 kolon; çapa (`code`) her grupta tekrarlandığı için
     *    gruba 2 veri kolonu düşer, 6 kolonun kalan 5'i 2+2+1 olarak ÜÇ gruba bölünür.
     *  - 20 mm asgari kolonla bütçe 6 kolon; altısı da sığar, TEK grup kalır.
     *
     * İki çıktı arasında kâğıt, şema ve satır aynı; değişen tek şey bütçe. Fark bu yüzden
     * tek başına bütçenin eseridir.
     */
    #[Test]
    public function theColumnBudgetSplitsTheDocumentIntoPageSets(): void
    {
        $narrow = $this->dir->file('bolunmus.pdf');
        $wide = $this->dir->file('tek-parca.pdf');

        $this->export()->to(Format::Pdf)
            ->page(Page::a5())
            ->columns(ColumnBudget::fit()->minWidth(40.0)->anchor('code'))
            ->write($narrow);

        $this->export()->to(Format::Pdf)
            ->page(Page::a5())
            ->columns(ColumnBudget::fit()->minWidth(20.0))
            ->write($wide);

        PdfDocument::at($narrow)->assertPageCount(3);
        PdfDocument::at($wide)->assertPageCount(1);
    }

    // ---------------------------------------------------------------- ★ sessiz yok sayma yasak

    /**
     * ★ Bu fazın özü.
     *
     * Kâğıt kavramı olmayan bir yazıcıya sayfa ayarı verilirse dışa aktarma BAŞLAMAZ.
     * Sessizce yok saymak, ortadan kaldırmaya çalıştığımız hatanın kendisi olurdu:
     * kullanıcı A3 istediğini sanıp Excel'i açar ve hiçbir şey anlamaz.
     */
    #[Test]
    #[DataProvider('formatsWithoutPaper')]
    public function aPageSettingOnAFormatWithoutPaperStopsTheExport(Format $format, string $file): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/PageAware/');

        $this->export()->to($format)->page(Page::a3())->write($this->dir->file($file));
    }

    /** Yalnız bütçe verilse de aynı kural işler; ikisi tek bir ayarın iki yarısıdır. */
    #[Test]
    #[DataProvider('formatsWithoutPaper')]
    public function aColumnBudgetOnAFormatWithoutPaperAlsoStopsTheExport(Format $format, string $file): void
    {
        $this->expectException(ExportException::class);

        $this->export()->to($format)->columns(ColumnBudget::fit())->write($this->dir->file($file));
    }

    /** @return iterable<string, array{Format, string}> */
    public static function formatsWithoutPaper(): iterable
    {
        yield 'csv' => [Format::Csv, 'olmaz.csv'];
        yield 'xlsx' => [Format::Xlsx, 'olmaz.xlsx'];
    }

    /**
     * Hata, dosyaya TEK BAYT yazılmadan çıkmalı.
     *
     * Yazma başladıktan sonra patlarsa diskte yarım bir dosya kalır ve çağıran onu
     * kullanıcıya sunabilir. Ayar hatası kurulum hatasıdır, akış hatası değil.
     */
    #[Test]
    public function theRefusalHappensBeforeAnythingIsWritten(): void
    {
        $path = $this->dir->file('dokunulmamis.csv');

        try {
            $this->export()->to(Format::Csv)->page(Page::a3())->write($path);
            self::fail('Sayfa ayarı reddedilmeliydi.');
        } catch (ExportException) {
            // beklenen
        }

        self::assertFileDoesNotExist($path);
    }

    /** Ayar hiç verilmediğinde kâğıtsız biçimler elbette çalışmaya devam eder. */
    #[Test]
    public function aFormatWithoutPaperStillWorksWhenNoPageSettingIsGiven(): void
    {
        $path = $this->dir->file('normal.csv');

        self::assertSame($path, $this->export()->to(Format::Csv)->write($path)->path());
        self::assertFileExists($path);
    }

    // ---------------------------------------------------------------- elle verilen yazıcı

    /**
     * `->writer()` ile elle verilen yazıcı da sayfa ayarını almalı.
     *
     * "Yalnız fabrikadan geleni ayarla" demek, elle verilen bir `PdfWriter`ın ayarı sessizce
     * yutması demekti — yani kaçtığımız hatanın arka kapısı.
     */
    #[Test]
    public function anExplicitlyGivenWriterAlsoReceivesThePageSetting(): void
    {
        $writer = new PdfWriter(new PdfOptions(page: Page::a4()->landscape()));

        $path = $this->dir->file('elle.pdf');
        $this->export()->to(Format::Pdf)->writer($writer)->page(Page::a5())->write($path);

        PdfDocument::at($path)->assertPaper(Page::a5());
    }

    /**
     * ...ama o örneğin KENDİSİ değişmemeli.
     *
     * `->writer()` ile verilen yazıcı bir servis olabilir ve birden çok dışa aktarmada
     * paylaşılabilir; birinin A5'i diğerinin çıktısına sızarsa hata, ilgisiz bir raporun
     * yanlış kâğıda basılması olarak ortaya çıkar (bkz. `PageAware::withPage()` neden
     * kopya döndürüyor).
     */
    #[Test]
    public function thePageSettingDoesNotStickToTheSharedWriterInstance(): void
    {
        $writer = new PdfWriter(new PdfOptions(page: Page::a4()->landscape()));

        $this->export()->to(Format::Pdf)->writer($writer)->page(Page::a5())
            ->write($this->dir->file('once-a5.pdf'));

        $later = $this->dir->file('sonra.pdf');
        $this->export()->to(Format::Pdf)->writer($writer)->write($later);

        PdfDocument::at($later)->assertPaper(Page::a4()->landscape());
    }

    // ---------------------------------------------------------------- değiştirilemezlik

    /** Diğer tüm kurulum metotları gibi `->page()` de KOPYA döndürür. */
    #[Test]
    public function settingThePageLeavesTheOriginalBuilderUntouched(): void
    {
        $base = $this->export()->to(Format::Pdf);
        $a5 = $base->page(Page::a5());

        self::assertNotSame($base, $a5);

        $basePath = $this->dir->file('taban.pdf');
        $a5Path = $this->dir->file('turev.pdf');

        $base->write($basePath);
        $a5->write($a5Path);

        PdfDocument::at($basePath)->assertPaper(Page::a4()->landscape());
        PdfDocument::at($a5Path)->assertPaper(Page::a5());
    }

    // ---------------------------------------------------------------- yardımcılar

    private function export(): ExportBuilder
    {
        return (new Tabula(new ArrayTranslator([])))
            ->export($this->schema())
            ->from(ArraySource::of([[
                'code' => '0042',
                'name' => 'Çiğdem Şahin Ltd. Şti.',
                'city' => 'İstanbul',
                'phone' => '0212 000 00 00',
                'email' => 'info@ornek.test',
                'note' => 'Şubat ayında görüşülecek',
            ]]))
            ->locale('tr');
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('Kod'),
            Field::string('name')->label('Ünvan'),
            Field::string('city')->label('Şehir'),
            Field::string('phone')->label('Telefon'),
            Field::string('email')->label('E-posta'),
            Field::string('note')->label('Not'),
        );
    }
}
