<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export\Writer;

use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Writer\CsvOptions;
use Balin\Tabula\Export\Writer\CsvWriter;
use Balin\Tabula\Export\Writer\DefaultWriterFactory;
use Balin\Tabula\Export\Writer\PdfOptions;
use Balin\Tabula\Export\Writer\PdfWriter;
use Balin\Tabula\Export\Writer\Writer;
use Balin\Tabula\Export\Writer\XlsxOptions;
use Balin\Tabula\Export\Writer\XlsxWriter;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Priority;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use Balin\Tabula\Value\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Yazıcı fabrikasının sözleşmesi.
 *
 * Fabrikanın kendisi birkaç satır; asıl değerli olan İKİ SÖZÜ:
 *
 *  1. HER çağrıda TAZE yazıcı döner. Yazıcılar durum taşır (açık dosya tanıtıcısı, aktif
 *     sayfa); paylaşılan tek bir örnek, aynı istekte peş peşe iki dışa aktarma yapan bir
 *     kod yolunda birinin dosyasını diğerinin üzerine yazdırır. Bu, `assertNotSame` ile
 *     "farklı nesne" diye değil, iki yazıcıyı GERÇEKTEN aynı anda çalıştırıp dosyalarına
 *     bakarak da doğrulanır (bkz. `twoWritersFromOneFactory...`).
 *  2. `with()` değiştirmez, KOPYALAR. Uygulamaya özel bir yazıcı (ör. antetli PDF) buraya
 *     bu şekilde takılır ve paylaşılan fabrika servisi kirlenmez.
 */
#[CoversClass(DefaultWriterFactory::class)]
final class DefaultWriterFactoryTest extends TestCase
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

    // ---------------------------------------------------------------- biçim → sınıf

    #[Test]
    public function everyBuiltInFormatGetsItsOwnWriterClass(): void
    {
        $factory = new DefaultWriterFactory();

        self::assertInstanceOf(CsvWriter::class, $factory->for(Format::Csv));
        self::assertInstanceOf(XlsxWriter::class, $factory->for(Format::Xlsx));
        self::assertInstanceOf(PdfWriter::class, $factory->for(Format::Pdf));
    }

    /**
     * Fabrikaya verilen PDF ayarı gerçekten üretilen yazıcıya iniyor mu.
     *
     * `PdfWriter` ayarını dışarı AÇMAZ (yazıcının okunacak bir yüzeyi yok), bu yüzden iddia
     * çıktının kendisinden okunur: A5 dikey kâğıt PDF'in `MediaBox`ında 419×595 punto olur.
     * "Nesne kuruldu" demek burada yetmezdi — bu fazın var olma sebebi, kurulan ama
     * uygulanmayan bir sayfa ayarıydı.
     */
    #[Test]
    public function thePdfOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('a5.pdf');

        $writer = (new DefaultWriterFactory(pdf: new PdfOptions(page: Page::a5())))->for(Format::Pdf);

        $writer->open($path);
        $writer->startSheet('Müşteriler', self::columns());
        $writer->writeRow([Cell::text('0042')]);
        $writer->finishSheet();

        self::assertSame([$path], $writer->close());
        self::assertStringContainsString('MediaBox [0.000 0.000 419.528 595.276]', (string) file_get_contents($path));
    }

    // ---------------------------------------------------------------- ★ taze yazıcı

    #[Test]
    public function everyCallHandsOutAFreshWriter(): void
    {
        $factory = new DefaultWriterFactory();

        self::assertNotSame($factory->for(Format::Csv), $factory->for(Format::Csv));
        self::assertNotSame($factory->for(Format::Xlsx), $factory->for(Format::Xlsx));
        self::assertNotSame($factory->for(Format::Pdf), $factory->for(Format::Pdf));
    }

    /**
     * "Farklı nesne" iddiasının somut bedeli.
     *
     * Fabrika tek bir örneği paylaştırsaydı ikinci `open()` "zaten açık" diye reddedilir,
     * reddedilmeseydi de iki dışa aktarma aynı dosya tanıtıcısına yazardı. Aşağıdaki
     * senaryo bilerek İÇ İÇE geçirildi: gerçek hayatta bir dışa aktarma başka bir
     * dışa aktarmayı tetikleyen bir olay dinleyicisiyle kolayca bu hâle gelir.
     */
    #[Test]
    public function twoWritersFromOneFactoryDoNotWriteIntoEachOthersFile(): void
    {
        $factory = new DefaultWriterFactory();

        $first = $factory->for(Format::Csv);
        $second = $factory->for(Format::Csv);

        $firstPath = $this->dir->file('birinci.csv');
        $secondPath = $this->dir->file('ikinci.csv');

        $first->open($firstPath);
        $first->startSheet('Birinci', self::columns());

        $second->open($secondPath);
        $second->startSheet('İkinci', self::columns());

        $first->writeRow([Cell::text('bir')]);
        $second->writeRow([Cell::text('iki')]);

        self::assertSame([$firstPath], $first->close());
        self::assertSame([$secondPath], $second->close());

        $firstBytes = (string) file_get_contents($firstPath);
        $secondBytes = (string) file_get_contents($secondPath);

        self::assertStringContainsString('bir', $firstBytes);
        self::assertStringNotContainsString('iki', $firstBytes);

        self::assertStringContainsString('iki', $secondBytes);
        self::assertStringNotContainsString('bir', $secondBytes);
    }

    // ---------------------------------------------------------------- with()

    #[Test]
    public function anOverrideReplacesTheBuiltInWriter(): void
    {
        $replacement = $this->createStub(Writer::class);

        $factory = (new DefaultWriterFactory())->with(Format::Csv, static fn (): Writer => $replacement);

        self::assertSame($replacement, $factory->for(Format::Csv));
        // Yalnız verilen biçim değişmeli; diğerleri yerleşik kalmalı.
        self::assertInstanceOf(XlsxWriter::class, $factory->for(Format::Xlsx));
    }

    /**
     * Geçersiz kılma EZBERLENMEMELİ.
     *
     * Kapanışın sonucu bir kez üretilip saklanırsa fabrikanın tüm varlık sebebi —
     * her çağrıda taze yazıcı — geri alınmış olur; üstelik bu sefer kütüphane değil,
     * kütüphaneyi kuran uygulama sorumlu görünür.
     */
    #[Test]
    public function theOverrideIsAskedOnceForEveryCall(): void
    {
        $calls = 0;

        $factory = (new DefaultWriterFactory())->with(
            Format::Csv,
            static function () use (&$calls): Writer {
                ++$calls;

                return new CsvWriter();
            },
        );

        $first = $factory->for(Format::Csv);
        $second = $factory->for(Format::Csv);
        $third = $factory->for(Format::Csv);

        self::assertSame(3, $calls, 'Kapanış üç kez sorulmalıydı; ezberlenmiş olabilir.');
        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
    }

    #[Test]
    public function withReturnsANewFactoryAndLeavesTheOriginalUntouched(): void
    {
        $original = new DefaultWriterFactory();
        $replacement = $this->createStub(Writer::class);

        $overridden = $original->with(Format::Csv, static fn (): Writer => $replacement);

        self::assertNotSame($original, $overridden);

        // Kaynak fabrika kapsayıcıda PAYLAŞILAN bir servistir; `with()` onu kirletseydi
        // tek bir çağrı yerinin özel yazıcısı tüm uygulamaya bulaşırdı.
        self::assertInstanceOf(CsvWriter::class, $original->for(Format::Csv));
        self::assertSame($replacement, $overridden->for(Format::Csv));
    }

    // ---------------------------------------------------------------- ayarlar yazıcıya iniyor mu

    #[Test]
    public function theCsvOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('besleme.csv');

        $this->tabula(new DefaultWriterFactory(CsvOptions::rfc4180()))->export(self::schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Öz Güneş A.Ş.']]))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        $bytes = (string) file_get_contents($path);

        // Fabrikaya verilen ayar yazıcıya inmeseydi burada BOM'lu, noktalı virgüllü
        // (yani Türkçe Excel'e göre) bir dosya olurdu.
        self::assertStringStartsWith("Kod,Ünvan\r\n", $bytes);
    }

    #[Test]
    public function theXlsxOptionsGivenToTheFactoryReachTheWriterItProduces(): void
    {
        $path = $this->dir->file('sade.xlsx');

        $this->tabula(new DefaultWriterFactory(xlsx: XlsxOptions::plain()))->export(self::schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Öz Güneş A.Ş.']]))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        self::assertNull($sheet->getFreezePane());
        self::assertSame('', $sheet->getAutoFilter()->getRange());
    }

    // ---------------------------------------------------------------- yardımcılar

    private function tabula(DefaultWriterFactory $writers): Tabula
    {
        return new Tabula(
            new ArrayTranslator(['tr' => ['col.code' => 'Kod', 'col.name' => 'Ünvan']]),
            writers: $writers,
        );
    }

    private static function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code'),
            Field::string('name')->label('col.name'),
        );
    }

    /**
     * Yazıcıyı boru hattı olmadan elle sürmek için tek kolonluk çözülmüş başlık.
     *
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            new Column(
                key: 'code',
                label: 'Kod',
                type: FieldType::String,
                align: Align::Left,
                width: null,
                required: false,
                priority: Priority::Normal,
            ),
        ];
    }
}
