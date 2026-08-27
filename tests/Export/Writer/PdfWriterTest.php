<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export\Writer;

use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Overflow;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Writer\PdfOptions;
use Balin\Tabula\Export\Writer\PdfWriter;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PDF yazıcısı — gerçek Dompdf ile, gerçek dosyalara.
 *
 * Sahte bir Dompdf ile çalışmak burada işe yaramazdı: doğrulamak istediğimiz şeylerin
 * çoğu (kâğıdın gerçekten değiştiği, Türkçe harflerin kutuya dönmediği, gruplamanın
 * fazladan SAYFA ürettiği) ancak üretilen belgede görünür.
 *
 * PDF'ten metin çıkarmak kapsam dışı; onun yerine belgenin kendi yapısına bakıyoruz:
 *  - `/MediaBox` → kâğıdın punto cinsinden gerçek ölçüsü,
 *  - `/BaseFont` → gömülen yazı tipi ailesi,
 *  - `/Type /Page` sayısı → sayfa sayısı.
 * Bunlar Dompdf çıktısında SIKIŞTIRILMAMIŞ olarak durur ve okunmaları için bir PDF
 * ayrıştırıcısına gerek yoktur.
 */
#[CoversClass(PdfWriter::class)]
#[CoversClass(PdfOptions::class)]
final class PdfWriterTest extends TestCase
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

    // ---------------------------------------------------------------- boru hattı

    #[Test]
    public function aMinimalExportProducesARealPdfFile(): void
    {
        $path = $this->dir->file('musteriler.pdf');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of($this->rows()))
            ->locale('tr')
            // Yazıcı elle verilmiyor: `to(Format::Pdf)` tek başına yetmeli, yoksa PDF
            // yolu fabrikaya bağlanmamış demektir ve kimse `Format::Pdf` ile PDF alamaz.
            ->to(Format::Pdf)
            ->write($path);

        self::assertFileExists($path);
        self::assertSame($path, $result->path());
        self::assertSame(2, $result->rows);
        self::assertSame(1, $result->sheets);

        // "%PDF-" imzası dosyanın ilk beş baytıdır; Dompdf sessizce boş ya da yarım bir
        // çıktı verseydi (ör. yazı tipi çözülemediğinde) dosya var ama geçersiz olurdu.
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
    }

    #[Test]
    public function anEmptyResultStillProducesAHeaderOnlyDocument(): void
    {
        // Kullanıcı boş bir dosya değil, "sonuç yok" diyen bir tablo görmeli — diğer iki
        // yazıcıyla aynı söz.
        $path = $this->dir->file('bos.pdf');

        $result = $this->tabula()->export($this->schema())
            ->from(ArraySource::of([]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        self::assertTrue($result->isEmpty());
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($path));
        self::assertSame(1, self::pageCount($path));
    }

    // ---------------------------------------------------------------- ★ Türkçe

    #[Test]
    public function turkishLettersGetAFontThatActuallyContainsThem(): void
    {
        $path = $this->dir->file('turkce.pdf');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 1]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        $bytes = (string) file_get_contents($path);

        self::assertStringStartsWith('%PDF-', $bytes);
        // Onlarca kilobaytlık bir belge: yazı tipi altkümesi gerçekten gömülmüş demektir.
        // Çekirdek (Helvetica) yazı tipiyle basılan aynı belge birkaç kilobayt kalırdı.
        self::assertGreaterThan(5_000, strlen($bytes));

        // ★ Asıl güvence bu. Dompdf'in yerleşik "çekirdek" yazı tipleri Latin-1'e bağlıdır
        // ve "ş ğ ı İ" harflerini HİÇ içermez; aile çözülemediğinde Dompdf sessizce onlara
        // düşer ve harfler kutuya döner. Belgede gömülü bir DejaVu altkümesi görmek,
        // `PdfOptions::BUNDLED_UNICODE_FONT` kararının çıktıya gerçekten işlediği anlamına gelir.
        self::assertMatchesRegularExpression('#/BaseFont\s*/[A-Z]{6}\+DejaVuSans#', $bytes);
    }

    #[Test]
    public function theTurkishTextItselfEndsUpInsideTheDocument(): void
    {
        // PDF'ten "metin çıkarmak" kapsam dışı, ama Dompdf metni içerik akışına UTF-16BE
        // olarak yazar; akışı açıp o baytları aramak tam ayrıştırma gerektirmez. Bu test
        // Dompdf'in çıktı biçimine bilinçli olarak dokunur: kırılırsa Dompdf sürümü
        // değişmiştir ve Türkçe çıktının elden geçirilmesi gerekir.
        $path = $this->dir->file('turkce-metin.pdf');

        $this->tabula()->export($this->schema())
            ->from(ArraySource::of([['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 1]]))
            ->locale('tr')
            ->to(Format::Pdf)
            ->write($path);

        $needle = (string) mb_convert_encoding('Çiğdem Şahin Öz', 'UTF-16BE', 'UTF-8');

        self::assertStringContainsString(
            $needle,
            self::readableBytes($path),
            'Türkçe hücre metni belgeye ulaşmamış (ya da kodlaması bozulmuş).',
        );
    }

    // ---------------------------------------------------------------- yaşam döngüsü

    #[Test]
    public function writingARowBeforeAnySheetIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('sirasiz.pdf'));

        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/Aktif sayfa yok/');

        $writer->writeRow([Cell::text('x')]);
    }

    #[Test]
    public function startingASheetBeforeOpeningIsRefused(): void
    {
        // Sessizce kabul edilseydi satırlar bir yere biriktirilir ve `close()` çağrısı
        // hedefsiz kalırdı: kullanıcı hiç yazılmamış bir dosyayı bekler.
        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/Yazıcı açık değil/');

        (new PdfWriter())->startSheet('Test', self::columns(1));
    }

    #[Test]
    public function openingTwiceIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('bir.pdf'));

        $this->expectException(WriterException::class);
        $this->expectExceptionMessageMatches('/zaten açık/');

        $writer->open($this->dir->file('iki.pdf'));
    }

    #[Test]
    public function finishingASheetThatWasNeverStartedIsRefused(): void
    {
        $writer = new PdfWriter();
        $writer->open($this->dir->file('kapanis.pdf'));

        $this->expectException(WriterException::class);

        $writer->finishSheet();
    }

    #[Test]
    public function closeCanBeCalledTwiceAndKeepsReportingTheSameFile(): void
    {
        // `ExportBuilder` `close()`u `finally` içinde çağırır; ikinci çağrı patlarsa asıl
        // hata gizlenir. CSV/xlsx yazıcılarıyla aynı söz.
        $path = $this->dir->file('iki-kere.pdf');

        $writer = new PdfWriter();
        $writer->open($path);
        $writer->startSheet('Test', self::columns(2));
        $writer->writeRow(self::cells(2));

        self::assertSame([$path], $writer->close());
        self::assertSame([$path], $writer->close());
    }

    #[Test]
    public function aWriterIsReusableAfterItIsClosed(): void
    {
        $writer = new PdfWriter();

        $first = $this->drive($writer, 'birinci.pdf');
        $second = $this->drive($writer, 'ikinci.pdf');

        self::assertFileExists($first);
        self::assertFileExists($second);
        // İkinci belgeye birincinin gövdesi taşınmamalı: `open()` biriken HTML'i sıfırlar.
        self::assertSame(self::pageCount($first), self::pageCount($second));
    }

    // ---------------------------------------------------------------- ★ kâğıt gerçekten değişiyor mu

    #[Test]
    public function thePaperSizeReachesTheDocumentInsteadOfStayingInTheConfiguration(): void
    {
        // Mevcut ERP'nin en sinsi hatasının regresyonu: `Dompdf::setPaper()` ile CSS'teki
        // `@page` çeliştiğinde CSS sessizce kazanıyordu ve PHP'deki ayar dekoratif kalıyordu.
        // Burada belgeye BASILMIŞ ölçüyü (`/MediaBox`, punto cinsinden) okuyup `Page`in
        // söylediğiyle karşılaştırıyoruz — arada tercüme edilmemiş tek bir ayar kalmasın.
        $a5 = Page::a5();
        $a3 = Page::a3()->landscape();

        self::assertPaper($a5, $this->write(new PdfOptions(page: $a5), 'a5.pdf'));
        self::assertPaper($a3, $this->write(new PdfOptions(page: $a3), 'a3-yatay.pdf'));
    }

    #[Test]
    public function turningThePageAloneIsEnoughToChangeTheDocument(): void
    {
        $portrait = Page::a4();
        $landscape = $portrait->landscape();

        self::assertPaper($portrait, $this->write(new PdfOptions(page: $portrait), 'dikey.pdf'));
        self::assertPaper($landscape, $this->write(new PdfOptions(page: $landscape), 'yatay.pdf'));
    }

    #[Test]
    public function withPageHandsBackAFreshWriterAndLeavesTheOriginalOnItsOwnPaper(): void
    {
        // Aynı yazıcı örneği (ör. `ExportBuilder::writer()` ile elle verilmiş) birden çok
        // dışa aktarmada paylaşılabilir; birinin A3'ü diğerinin çıktısına sızmamalı.
        $original = new PdfWriter(new PdfOptions(page: Page::a5()));

        self::assertSame($original, $original->withPage(null, null), 'İki null hiçbir şeyi değiştirmemeli.');

        $copy = $original->withPage(Page::a3()->landscape(), null);
        self::assertNotSame($original, $copy);

        self::assertPaper(Page::a3()->landscape(), $this->drive($copy, 'kopya.pdf'));
        self::assertPaper(Page::a5(), $this->drive($original, 'asil.pdf'));
    }

    // ---------------------------------------------------------------- ★ gruplama

    #[Test]
    public function splittingIntoPageSetsCostsMorePagesThanDroppingColumns(): void
    {
        // Uçtan uca ve ucuz bir kanıt: aynı veri, aynı kâğıt, yalnız taşma stratejisi farklı.
        // NextPageSet 18 kolonu iki gruba böler ve TÜM satırları iki kez basar; Drop tek
        // grupta kalır. Gruplama hiç yapılmasaydı (ör. yazıcı `split()`i çağırmayı unutsaydı)
        // iki belge de aynı sayfa sayısında çıkardı.
        $columns = 18;
        $rows = 40;

        $pageSets = $this->write(
            new PdfOptions(budget: ColumnBudget::fit()->anchor('k1')),
            'takim.pdf',
            $columns,
            $rows,
        );

        $dropped = $this->write(
            new PdfOptions(budget: ColumnBudget::fit()->anchor('k1')->overflow(Overflow::Drop)),
            'elenmis.pdf',
            $columns,
            $rows,
        );

        self::assertGreaterThan(
            self::pageCount($dropped),
            self::pageCount($pageSets),
            'Kolonlar sayfa takımlarına bölünmemiş: iki belge de aynı sayfa sayısında.',
        );

        self::assertGreaterThan((int) filesize($dropped), (int) filesize($pageSets));
    }

    #[Test]
    public function aColumnSetThatFitsIsNotSplitAtAll(): void
    {
        // Karşı kontrol: yukarıdaki sayfa farkı "PDF uzun oldu"dan değil, gerçekten
        // gruplamadan geliyor. Bütçeye sığan bir tablo tek sayfada kalmalı.
        $path = $this->write(new PdfOptions(), 'sigan.pdf', 8, 5);

        self::assertSame(1, self::pageCount($path));
    }

    // ---------------------------------------------------------------- yardımcılar

    private function tabula(): Tabula
    {
        return new Tabula(new ArrayTranslator([
            'tr' => [
                'col.code' => 'Kod',
                'col.name' => 'Ünvan',
                'col.qty' => 'Miktar',
            ],
        ]));
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->fields(
            Field::string('code')->label('col.code'),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return [
            ['code' => '0042', 'name' => 'Çiğdem Şahin Öz', 'qty' => 12.5],
            ['code' => '0043', 'name' => 'Öz Güneş A.Ş.', 'qty' => 3],
        ];
    }

    /** Verilen ayarla bir belge üretir ve yolunu döndürür. */
    private function write(PdfOptions $options, string $name, int $columnCount = 2, int $rowCount = 1): string
    {
        return $this->drive(new PdfWriter($options), $name, $columnCount, $rowCount);
    }

    /** Yazıcıyı boru hattı olmadan elle sürer. */
    private function drive(PdfWriter $writer, string $name, int $columnCount = 2, int $rowCount = 1): string
    {
        $path = $this->dir->file($name);

        $writer->open($path);
        $writer->startSheet('Test', self::columns($columnCount));

        for ($i = 0; $i < $rowCount; ++$i) {
            $writer->writeRow(self::cells($columnCount));
        }

        $writer->finishSheet();
        $writer->close();

        return $path;
    }

    /** @return list<Column> */
    private static function columns(int $count): array
    {
        $columns = [];

        for ($i = 1; $i <= $count; ++$i) {
            $columns[] = new Column(
                key: 'k'.$i,
                label: 'Kolon '.$i,
                type: FieldType::String,
                align: Align::Left,
                width: null,
                required: false,
                priority: Priority::Normal,
            );
        }

        return $columns;
    }

    /** @return list<Cell> */
    private static function cells(int $count): array
    {
        $cells = [];

        for ($i = 1; $i <= $count; ++$i) {
            $cells[] = Cell::text('değer '.$i);
        }

        return $cells;
    }

    /** Belgeye basılan kâğıt ölçüsünün `Page`in söylediğiyle aynı olduğunu doğrular. */
    private static function assertPaper(Page $page, string $path): void
    {
        [$widthPt, $heightPt] = self::mediaBox($path);

        // Yarım puntoluk tolerans: Dompdf `/MediaBox` değerlerini üç haneye yuvarlar.
        self::assertEqualsWithDelta(self::toPoints($page->widthMm()), $widthPt, 0.5, 'Belgenin eni yanlış kâğıttan geliyor.');
        self::assertEqualsWithDelta(self::toPoints($page->heightMm()), $heightPt, 0.5, 'Belgenin boyu yanlış kâğıttan geliyor.');
    }

    /**
     * Belgedeki ilk `/MediaBox` girdisini punto olarak okur.
     *
     * @return array{float, float}
     */
    private static function mediaBox(string $path): array
    {
        $bytes = (string) file_get_contents($path);

        if (1 !== preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)#', $bytes, $found)) {
            self::fail('Belgede /MediaBox yok — Dompdf çıktısının biçimi değişmiş olabilir.');
        }

        return [(float) $found[1], (float) $found[2]];
    }

    /** PDF'in birimi puntodur: 1 inç = 72 punto = 25,4 mm. */
    private static function toPoints(float $mm): float
    {
        return $mm * 72.0 / 25.4;
    }

    /**
     * Belgedeki sayfa sayısı.
     *
     * `\b` sayfa AĞACINI (`/Type /Pages`) eler; o düğüm bir kez geçer ve sayıyı bir
     * fazla göstererek karşılaştırmayı bozardı.
     */
    private static function pageCount(string $path): int
    {
        return (int) preg_match_all('#/Type\s*/Page\b#', (string) file_get_contents($path));
    }

    /**
     * Belgenin ham baytları + çözülebilen tüm sıkıştırılmış akışları.
     *
     * Akışların hepsi zlib değildir (yazı tipi altkümeleri, çapraz başvuru akışları);
     * çözülemeyen akış hata değil, ilgilenmediğimiz bir akıştır. Uyarıyı susturmak için
     * `@` yerine kendi işleyicimizi kuruyoruz: PHPUnit `failOnWarning` ile çalışıyor ve
     * bastırılmış uyarıları da rapor edebiliyor.
     */
    private static function readableBytes(string $path): string
    {
        $bytes = (string) file_get_contents($path);
        $readable = $bytes;

        preg_match_all("/stream\r?\n(.*?)endstream/s", $bytes, $streams);

        set_error_handler(static fn (): bool => true);

        try {
            foreach ($streams[1] as $stream) {
                $inflated = gzuncompress($stream);

                if (false !== $inflated) {
                    $readable .= $inflated;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $readable;
    }
}
