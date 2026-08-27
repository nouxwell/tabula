<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export\Writer;

use Balin\Tabula\Export\Writer\CsvOptions;
use Balin\Tabula\Export\Writer\DefaultWriterFactory;
use Balin\Tabula\Export\Writer\XlsxOptions;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Tabula;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ayar nesnelerinin GERÇEKTEN dosyaya yansıdığını kanıtlar.
 *
 * Bu testlerin tamamı VO'nun alanlarını geri okuyarak değil, gerçek bir dışa aktarma yapıp
 * ÜRETİLEN DOSYAYA bakarak yazıldı. Sebebi şu: `CsvOptions`/`XlsxOptions` refactoru tam
 * olarak "ayar var ama hiçbir yere ulaşmıyor" hatasını kapatmak için yapıldı. `$options->
 * boldHeader` alanının `true` döndüğünü doğrulayan bir test, o alanı `setBold()`a bağlamayı
 * unutmuş bir yazıcıda da yeşil kalır — yani hatayı hiç görmez. Dosyanın baytları ve
 * PhpSpreadsheet'in geri okuduğu sayfa yalan söyleyemez.
 */
#[CoversClass(CsvOptions::class)]
#[CoversClass(XlsxOptions::class)]
final class WriterOptionsTest extends TestCase
{
    /**
     * Ters bölü ile tırnağın YAN YANA gelmesi, PHP'nin standart dışı kaçışını tetikleyen
     * tek dizilimdir; ayar açıkken ve kapalıyken dosyaya farklı baytlar düşer.
     */
    private const string TRICKY = 'A\\"B';

    private TempDirectory $dir;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        $this->dir->remove();
    }

    // ---------------------------------------------------------------- CSV: ayraç ve BOM

    #[Test]
    public function rfc4180OptionsProduceACommaSeparatedFileWithNoBom(): void
    {
        $path = $this->writeCsv(CsvOptions::rfc4180(), 'besleme.csv');
        $bytes = (string) file_get_contents($path);

        // Makine tarafı BOM'u veri sanar: ilk kolonun adı "\xEF\xBB\xBFKod" olur ve
        // sütun eşleştirmesi daha ilk satırda kayar.
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $bytes);
        self::assertStringStartsWith("Kod,Ünvan\r\n", $bytes);
        self::assertStringNotContainsString(';', $bytes);
    }

    #[Test]
    public function theExcelPresetProducesSemicolonsABomAndUnbrokenTurkishCharacters(): void
    {
        $path = $this->writeCsv(CsvOptions::excel(), 'musteriler.csv');
        $bytes = (string) file_get_contents($path);

        // BOM olmadan Excel dosyayı cp1254 sanar ve ş/ğ/ı/İ/ö/ç/ü daha başlıkta bozulur.
        self::assertStringStartsWith("\xEF\xBB\xBFKod;Ünvan\r\n", $bytes);

        // Türkçe harfler UTF-8 olarak, bayt bayt korunmalı.
        self::assertStringContainsString('Çiğdem Şahin Ltd. Şti.', $bytes);
        self::assertStringContainsString('Öz Güneş A.Ş.', $bytes);
    }

    /**
     * `excel()` "bir seçenek" değil, VARSAYILANIN kendisidir.
     *
     * Adlandırılmış kurucu ile hiç ayar vermemek arasında bir bayt bile fark çıkarsa
     * varsayılan sessizce kaymış demektir; belge de, çağrı yerleri de yanlış olur.
     */
    #[Test]
    public function theExcelPresetIsByteForByteWhatYouGetWithNoOptionsAtAll(): void
    {
        $named = $this->writeCsv(CsvOptions::excel(), 'adli.csv');
        $implicit = $this->writeCsv(new CsvOptions(), 'ortulu.csv');

        self::assertSame(file_get_contents($named), file_get_contents($implicit));
    }

    // ---------------------------------------------------------------- CSV: satır sonu

    #[Test]
    public function theLineEndingIsWhicheverTheOptionsAskFor(): void
    {
        $lf = (string) file_get_contents(
            $this->writeCsv(new CsvOptions(lineEnding: "\n"), 'unix.csv'),
        );

        // Tek bir taşıma dönüşü bile kalmamalı: dosya Unix tarafına gidiyorsa "\r"
        // son kolonun DEĞERİNE yapışır ve karşılaştırmalar sessizce tutmaz.
        self::assertStringNotContainsString("\r", $lf);
        self::assertStringContainsString("Kod;Ünvan\n", $lf);

        $crlf = (string) file_get_contents(
            $this->writeCsv(new CsvOptions(), 'windows.csv'),
        );

        // Varsayılan CRLF: RFC 4180'in şart koştuğu ve Windows Excel'in beklediği satır sonu.
        self::assertStringContainsString("Kod;Ünvan\r\n", $crlf);
    }

    // ---------------------------------------------------------------- CSV: sarmalayıcı

    #[Test]
    public function theEnclosureIsWhicheverTheOptionsAskFor(): void
    {
        // Değer ayraç taşıyor, yani alan MECBUREN sarılır — sarmalayıcı da baytlarda görünür.
        $path = $this->writeOneValue(
            new CsvOptions(enclosure: "'", writeBom: false),
            'sarmalayici.csv',
            'Ege; Marmara',
        );

        self::assertStringContainsString("'Ege; Marmara'", (string) file_get_contents($path));
    }

    // ---------------------------------------------------------------- CSV: kaçış

    /**
     * Bu testin sessiz ikinci işi: PHP 8.4'ten beri `fputcsv`'yi `$escape` argümanı olmadan
     * çağırmak "deprecated" uyarısı üretir. Süit `failOnDeprecation="true"` ile koştuğu için
     * yazıcı o argümanı geçirmeyi bırakırsa buradaki (ve aşağıdaki) test kendiliğinden
     * kırmızıya döner; ayrı bir uyarı yakalayıcıya gerek yok.
     */
    #[Test]
    public function turningTheEscapeOffMakesTheFieldSurviveAStrictRfc4180Reader(): void
    {
        $path = $this->writeOneValue(CsvOptions::rfc4180(), 'kacissiz.csv', self::TRICKY);
        $bytes = (string) file_get_contents($path);

        // Tırnak İKİYE KATLANIR — RFC 4180'in tanıdığı tek kaçış budur.
        self::assertStringContainsString('"A\\""B"', $bytes);
        // Ters bölülü PHP kaçışından eser kalmamalı.
        self::assertStringNotContainsString('"A\\"B"', $bytes);

        // Asıl kanıt: karşı taraftaki ayrıştırıcı değeri BİREBİR geri okur.
        self::assertSame([self::TRICKY], self::readStrictly($path)[1]);
    }

    /**
     * Karşı kanıt: yukarıdaki testi geçiren şey `escape: ''` ayarı, tesadüf değil.
     *
     * PHP'nin kaçışı açıkken dosya `"A\"B"` olur; bu, alıntılanmış bir alanın ortasında
     * katlanmamış bir tırnaktır ve RFC 4180 ayrıştırıcısı için bozuk veridir. Kimse
     * patlamaz, değer sessizce başka bir şeye dönüşür — en pahalı hata türü.
     */
    #[Test]
    public function thePhpStyleEscapeSilentlyCorruptsTheFieldForAStrictReader(): void
    {
        $path = $this->writeOneValue(
            new CsvOptions(delimiter: ',', escape: '\\', writeBom: false),
            'kacisli.csv',
            self::TRICKY,
        );

        self::assertStringContainsString('"A\\"B"', (string) file_get_contents($path));
        self::assertNotSame([self::TRICKY], self::readStrictly($path)[1]);
    }

    // ---------------------------------------------------------------- XLSX: süsleme anahtarları

    #[Test]
    public function plainXlsxOptionsLeaveOutTheFreezePaneTheAutoFilterAndTheBoldHeader(): void
    {
        $sheet = $this->writeXlsx(XlsxOptions::plain(), 'sade.xlsx')->getActiveSheet();

        self::assertNull($sheet->getFreezePane(), 'plain() dondurulmuş satır bırakmamalı.');
        self::assertSame('', $sheet->getAutoFilter()->getRange(), 'plain() süzme aralığı kurmamalı.');
        self::assertNotTrue($sheet->getStyle('A1')->getFont()->getBold(), 'plain() başlığı kalınlaştırmamalı.');
    }

    /**
     * Aynı üç şeyin varsayılanda AÇIK olduğu kanıtı.
     *
     * Bu ikili olmadan yukarıdaki test, üç özelliği hiç uygulamayan (yani ayarı da hiç
     * okumayan) bir yazıcıda da yeşil kalırdı — ölü düğmenin tam tanımı.
     */
    #[Test]
    public function theDefaultXlsxOptionsGiveAllThreeBack(): void
    {
        $sheet = $this->writeXlsx(new XlsxOptions(), 'suslu.xlsx')->getActiveSheet();

        self::assertSame('A2', $sheet->getFreezePane(), 'Başlık satırı varsayılanda sabit kalmalı.');
        // Aralık başlık + İKİ veri satırını kapsamalı; sabit bir aralık kullanan eski motor
        // uzun listelerde son satırları süzmenin dışında bırakıyordu.
        self::assertSame('A1:B3', $sheet->getAutoFilter()->getRange());
        self::assertTrue($sheet->getStyle('A1')->getFont()->getBold());
    }

    // ---------------------------------------------------------------- XLSX: üretici ve renkler

    #[Test]
    public function theCreatorAndTheHeaderColoursLandInTheWorkbook(): void
    {
        $options = new XlsxOptions(
            creator: 'Ionsis ERP',
            headerFill: 'FF102030',
            requiredHeaderFill: 'FF9900AA',
            headerBorderColor: 'FF00FF00',
        );

        $book = $this->writeXlsx($options, 'renkli.xlsx');
        $sheet = $book->getActiveSheet();

        self::assertSame('Ionsis ERP', $book->getProperties()->getCreator());

        // B = 'name', zorunlu DEĞİL → genel başlık dolgusu.
        self::assertSame('FF102030', $sheet->getStyle('B1')->getFill()->getStartColor()->getARGB());
        // A = 'code', zorunlu → kendi dolgusu genel dolguyu ezer.
        self::assertSame('FF9900AA', $sheet->getStyle('A1')->getFill()->getStartColor()->getARGB());

        self::assertSame('FF00FF00', $sheet->getStyle('A1')->getBorders()->getBottom()->getColor()->getARGB());
    }

    // ---------------------------------------------------------------- yardımcılar

    /**
     * Yazıcı ayarları YALNIZCA fabrikadan geçirilir.
     *
     * `->writer(new CsvWriter($options))` demek testi kolaylaştırırdı ama gerçek çağrı
     * yolunu atlardı: uygulamada ayar, yapılandırmadan fabrikaya, oradan yazıcıya iner.
     */
    private function tabula(?CsvOptions $csv = null, ?XlsxOptions $xlsx = null): Tabula
    {
        return new Tabula(
            new ArrayTranslator(['tr' => ['col.code' => 'Kod', 'col.name' => 'Ünvan']]),
            writers: new DefaultWriterFactory($csv ?? new CsvOptions(), $xlsx ?? new XlsxOptions()),
        );
    }

    /** İlk kolon ZORUNLU: zorunlu başlık dolgusunun ayrı bir ayar olduğunu görebilelim. */
    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
        );
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        return [
            ['code' => '0042', 'name' => 'Çiğdem Şahin Ltd. Şti.'],
            ['code' => '0043', 'name' => 'Öz Güneş A.Ş.'],
        ];
    }

    private function writeCsv(CsvOptions $options, string $name): string
    {
        $path = $this->dir->file($name);

        $this->tabula(csv: $options)->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        return $path;
    }

    private function writeXlsx(XlsxOptions $options, string $name): Spreadsheet
    {
        $path = $this->dir->file($name);

        $this->tabula(xlsx: $options)->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            ->to(Format::Xlsx)
            ->write($path);

        return IOFactory::load($path);
    }

    /** Tek kolonlu, tek satırlık dosya: incelenen ayar başka hiçbir şeye karışmasın. */
    private function writeOneValue(CsvOptions $options, string $name, string $value): string
    {
        $path = $this->dir->file($name);

        $this->tabula(csv: $options)->export(
            Schema::make('n')->fields(Field::string('name')->label('col.name')),
        )
            ->from(ArraySource::of([['name' => $value]]))
            ->locale('tr')
            ->to(Format::Csv)
            ->write($path);

        return $path;
    }

    /**
     * Dosyayı RFC 4180 kurallarıyla (kaçış KAPALI) okur — yani karşı taraftaki makinenin
     * gördüğü gibi. Kaçışı açık bırakarak okumak PHP'nin kendi hatasını kendi kendine
     * telafi etmesi olurdu ve test hiçbir şey kanıtlamazdı.
     *
     * @return list<list<string|null>>
     */
    private static function readStrictly(string $path): array
    {
        $bytes = (string) file_get_contents($path);
        $rows = [];

        foreach (explode("\r\n", rtrim($bytes, "\r\n")) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        return $rows;
    }
}
