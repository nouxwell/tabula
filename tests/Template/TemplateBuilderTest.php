<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Template;

use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Template\TemplateBuilder;
use Balin\Tabula\Template\TemplateOptions;
use Balin\Tabula\Tests\Fixture\Status;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Şablon üretimi testleri — üretilen dosya PhpSpreadsheet ile GERİ OKUNARAK ölçülür.
 *
 * Şablon, gidiş-dönüşün "dönüş" ucunun sözleşmesidir ve tamamı tek bir karara dayanır:
 *
 *     1. satır → kanonik alan anahtarları (gizli)
 *     2. satır → çevrilmiş etiketler
 *     3. satır → veri
 *
 * Eski ERP'de dosyanın kimliği ÇEVRİLMİŞ BAŞLIK DİZESİYDİ; çeviri dosyasındaki tek bir
 * kelime değişikliği kullanıcıların elindeki tüm şablonları sessizce okunamaz hâle
 * getiriyordu. Aşağıdaki testler o kimliğin dosyada gerçekten durduğunu doğrular.
 */
final class TemplateBuilderTest extends TestCase
{
    private TempDirectory $dir;

    private ?Spreadsheet $loaded = null;

    protected function setUp(): void
    {
        $this->dir = TempDirectory::create();
    }

    protected function tearDown(): void
    {
        // Worksheet ile Spreadsheet birbirini tutar; sayaç tabanlı çöp toplayıcı çözemez.
        $this->loaded?->disconnectWorksheets();
        $this->loaded = null;

        $this->dir->remove();
    }

    // ---------------------------------------------------------------- yardımcılar

    private function translator(): Translator
    {
        return new ArrayTranslator([
            'tr' => [
                'sheet.customer' => 'Müşteriler',
                'col.code' => 'Kod',
                'col.name' => 'Ünvan',
                'col.active' => 'Aktif',
                'col.locked' => 'Kilitli',
                'col.status' => 'Durum',
                'col.qty' => 'Miktar',
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                'tabula.bool.yes' => 'Evet',
                'tabula.bool.no' => 'Hayır',
            ],
        ]);
    }

    /**
     * İki BOOLE kolonu bilerek yan yanadır: ikisi de aynı ["Evet","Hayır"] kümesini
     * üretir, yani tekilleştirme çalışıyorsa yardımcı sayfada tek sütun oluşmalıdır.
     */
    private function schema(): Schema
    {
        return Schema::make('customer')->title('sheet.customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
            Field::bool('isActive')->label('col.active'),
            Field::bool('isLocked')->label('col.locked'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
        );
    }

    private function build(?TemplateOptions $options = null): Spreadsheet
    {
        $builder = new TemplateBuilder(
            $this->translator(),
            new TabulaSettings(),
            $options ?? new TemplateOptions(),
        );

        $path = $this->dir->file('sablon.xlsx');
        $builder->write($this->schema(), $path, 'tr');

        return $this->loaded = IOFactory::load($path);
    }

    /** @return list<string> */
    private function rowValues(Worksheet $sheet, int $row): array
    {
        $last = Coordinate::columnIndexFromString($sheet->getHighestColumn());

        $values = [];
        for ($column = 1; $column <= $last; ++$column) {
            $values[] = $sheet->getCell([$column, $row])->getValueString();
        }

        return $values;
    }

    /**
     * Açılır listenin GERÇEK seçenekleri: doğrulama formülünün gösterdiği aralık
     * yardımcı sayfadan tek tek okunur.
     *
     * @return list<string>
     */
    private function dropdownOptions(Spreadsheet $spreadsheet, DataValidation $validation): array
    {
        self::assertSame(DataValidation::TYPE_LIST, $validation->getType());

        $formula = $validation->getFormula1();

        if (1 !== preg_match('/^_lists!\$([A-Z]+)\$(\d+):\$[A-Z]+\$(\d+)$/', $formula, $matches)) {
            self::fail(sprintf('Beklenmeyen doğrulama formülü: %s', $formula));
        }

        $lists = $this->listSheet($spreadsheet);

        $options = [];
        for ($row = (int) $matches[2]; $row <= (int) $matches[3]; ++$row) {
            $options[] = $lists->getCell($matches[1].$row)->getValueString();
        }

        return $options;
    }

    private function listSheet(Spreadsheet $spreadsheet): Worksheet
    {
        $lists = $spreadsheet->getSheetByName('_lists');

        if (null === $lists) {
            self::fail('Gizli "_lists" yardımcı sayfası üretilmemiş.');
        }

        return $lists;
    }

    // ---------------------------------------------------------------- yerleşim

    #[Test]
    public function rowOneCarriesTheCanonicalKeysAndRowTwoTheTranslatedLabels(): void
    {
        $sheet = $this->build()->getSheet(0);

        // ★ Dosyanın KİMLİĞİ budur. Çeviri değişse bile 1. satır aynı kalır.
        self::assertSame(
            ['code', 'name', 'isActive', 'isLocked', 'status', 'qty'],
            $this->rowValues($sheet, 1),
            '1. satır kanonik alan anahtarlarını taşımalı.',
        );

        self::assertSame(
            ['Kod', 'Ünvan', 'Aktif', 'Kilitli', 'Durum', 'Miktar'],
            $this->rowValues($sheet, 2),
            '2. satır kullanıcının okuduğu çeviriyi taşımalı.',
        );

        self::assertSame('Müşteriler', $sheet->getTitle());
    }

    #[Test]
    public function theKeyRowIsHiddenButStillPresentInTheFile(): void
    {
        $sheet = $this->build()->getSheet(0);

        // Satır GİZLENİR, silinmez: kullanıcı teknik anahtarları görmez, içe aktarma görür.
        self::assertFalse($sheet->getRowDimension(1)->getVisible(), 'Anahtar satırı gizli olmalı.');
        self::assertTrue($sheet->getRowDimension(2)->getVisible(), 'Etiket satırı görünür olmalı.');
        self::assertSame('code', $sheet->getCell('A1')->getValueString(), 'Gizli satır dosyada durmaya devam etmeli.');
    }

    #[Test]
    public function dataStartsAtRowThreeAndBothHeaderRowsAreFrozen(): void
    {
        $sheet = $this->build()->getSheet(0);

        // "A3'ü dondur" = anahtar VE etiket satırlarının ikisi de sabit kalsın.
        // (`XlsxWriter` tek başlık satırı için A2 dondurur; fark gizli anahtar satırından gelir.)
        self::assertSame('A3', $sheet->getFreezePane());

        // Şablonda hiç DOLU veri satırı yoktur.
        self::assertSame(['', '', '', '', '', ''], $this->rowValues($sheet, 3), 'Veri satırları boş olmalı.');

        // Kolon stilleri TAM KOLON aralığıyla ("B1:B1048576") uygulanır. Veri satırından
        // başlatan bir aralık ("B3:B1048576") içgüdüsel olarak doğru görünür ama artık tam
        // kolon sayılmaz: PhpSpreadsheet aradaki BİR MİLYON koordinat için gerçekten hücre
        // yaratır ve dosya tek satırda bellekte patlar.
        // (Yüklenen dosyada C3/D3/E3 belirir; onları okuyucu doğrulama aralıklarını
        // uygularken yaratır — yazılan dosyanın kendi boyutu A1:F2'dir.)
        self::assertLessThan(10, $sheet->getHighestRow(), 'Tam kolon stili bir milyon hücre yaratmamalı.');
    }

    #[Test]
    public function aRequiredColumnHeaderCarriesTheRequiredFill(): void
    {
        $sheet = $this->build()->getSheet(0);

        // Eski ERP şablonlarındaki işe yarayan tek görsel ipucu buydu; korundu.
        self::assertSame(
            'FFFCE4E4',
            $sheet->getStyle('A2')->getFill()->getStartColor()->getARGB(),
            'Zorunlu kolonun başlığı kırmızı dolgulu olmalı.',
        );

        self::assertSame(
            'FFF2F2F2',
            $sheet->getStyle('B2')->getFill()->getStartColor()->getARGB(),
            'Zorunlu olmayan kolon sıradan başlık dolgusunu almalı.',
        );
    }

    // ---------------------------------------------------------------- açılır listeler

    /**
     * ★ Eski ERP'nin en somut kusuru: hücreye yazdığı değer, kendi doğrulama listesinde
     * BULUNMUYORDU (metin bir çeviri ailesinden, liste başka bir aileden geliyordu).
     * Burada liste, dışa aktarmanın kullandığı biçimlendiricinin ta kendisine sorularak
     * üretilir; ayrışmaları imkânsızdır.
     */
    #[Test]
    public function aBoolColumnDropdownContainsTheTranslatedYesNoPair(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        self::assertTrue($sheet->dataValidationExists('C3'), 'Boole kolonunda açılır liste olmalı.');

        self::assertSame(
            ['Evet', 'Hayır'],
            $this->dropdownOptions($spreadsheet, $sheet->getDataValidation('C3')),
            'Listedeki metin, dışa aktarmanın hücreye yazdığı metnin AYNISI olmalı.',
        );
    }

    #[Test]
    public function anEnumColumnDropdownContainsEveryCaseLabel(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        self::assertSame(
            ['Açık', 'Kapalı'],
            $this->dropdownOptions($spreadsheet, $sheet->getDataValidation('E3')),
        );
    }

    #[Test]
    public function identicalOptionSetsShareASingleRangeOnTheHiddenListSheet(): void
    {
        $spreadsheet = $this->build();
        $sheet = $spreadsheet->getSheet(0);

        $first = $sheet->getDataValidation('C3')->getFormula1();
        $second = $sheet->getDataValidation('D3')->getFormula1();

        self::assertSame($first, $second, 'Aynı seçenek kümesini paylaşan iki kolon TEK aralığa bakmalı.');

        $lists = $this->listSheet($spreadsheet);

        // İki boole kolonu tek sütuna düşer, enum ikinci sütunu alır: toplam iki sütun.
        self::assertSame('B', $lists->getHighestColumn(), 'Tekilleştirme çalışmazsa üç ayrı sütun oluşurdu.');
        self::assertSame(Worksheet::SHEETSTATE_HIDDEN, $lists->getSheetState());
    }

    #[Test]
    public function aPlainColumnGetsNoDropdown(): void
    {
        $sheet = $this->build()->getSheet(0);

        // Boş bir açılır liste hiç liste olmamasından beterdir: Excel hücreyi kilitler.
        self::assertFalse($sheet->dataValidationExists('A3'), 'Metin kolonunda liste olmamalı.');
        self::assertFalse($sheet->dataValidationExists('F3'), 'Miktar kolonunda liste olmamalı.');
    }

    // ---------------------------------------------------------------- seçenekler

    #[Test]
    public function switchingOffTheKeyRowMovesEverythingUpOneRow(): void
    {
        // Anahtar satırını kapatmak dosyayı ETİKETLE eşleşmeye mahkûm eder — yani eski
        // ERP'nin ölümcül kusuruna geri döner. Seçenek yine de sözleşmesini korumalı.
        $sheet = $this->build(new TemplateOptions(includeKeyRow: false))->getSheet(0);

        self::assertSame(['Kod', 'Ünvan', 'Aktif', 'Kilitli', 'Durum', 'Miktar'], $this->rowValues($sheet, 1));
        self::assertSame('A2', $sheet->getFreezePane());
        self::assertTrue($sheet->dataValidationExists('C2'));
    }
}
