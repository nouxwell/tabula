<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Import;

use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Import\ImportedRow;
use Balin\Tabula\Import\ImportResult;
use Balin\Tabula\Import\MatchStrategy;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Tabula;
use Balin\Tabula\Template\TemplateBuilder;
use Balin\Tabula\Template\TemplateOptions;
use Balin\Tabula\Tests\Fixture\Status;
use Balin\Tabula\Tests\Fixture\TempDirectory;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ★ BU FAZIN BAŞLIK TESTİ: şablon üret → kullanıcı gibi doldur → geri içe aktar.
 *
 * "Tek şema, üç yön" iddiasının ölçüldüğü yer burasıdır. Dışa aktarma, şablon üretimi ve
 * içe aktarma aynı `Schema` nesnesinden beslenir; dolayısıyla ürettiğimiz dosya hiçbir
 * dönüşüm olmadan geri okunabilmeli VE değerler doğru TİPLE geri gelmelidir — boole
 * gerçek bool, enum enum ÖRNEĞİ, tarih `DateTimeImmutable`, miktar float.
 *
 * İkinci ve daha önemli iddia: DOSYANIN KİMLİĞİ ÇEVİRİ DEĞİLDİR. Eski ERP'de eşleştirme
 * çevrilmiş başlık metniyle yapılıyordu, yani çeviri dosyasındaki tek bir kelime
 * değişikliği kullanıcıların elindeki tüm şablonları sessizce okunamaz hâle getiriyordu.
 * Aşağıdaki testler bunu bir kere gösterir, bir kere de çürütür.
 */
final class RoundTripTest extends TestCase
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

    // ---------------------------------------------------------------- kurulum

    /**
     * Özgün katalog — şablonun üretildiği dil.
     *
     * @param array<string, string> $overrides
     */
    private function translator(array $overrides = []): Translator
    {
        return new ArrayTranslator([
            'tr' => array_merge([
                'sheet.customer' => 'Müşteriler',
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
            ], $overrides),
        ]);
    }

    /**
     * TÜM kolon etiketleri yeniden yazılmış katalog.
     *
     * Değer sözlükleri (`status.*`, `tabula.bool.*`) bilerek DOKUNULMADAN bırakıldı:
     * anahtar satırının koruduğu şey KOLON KİMLİĞİDİR. Hücrenin içindeki metin ayrı bir
     * sözleşmedir ve sınırı aşağıda ayrıca ölçülüyor.
     */
    private function retranslated(): Translator
    {
        return $this->translator([
            'sheet.customer' => 'Cari Hesaplar',
            'col.code' => 'Cari Kodu',
            'col.name' => 'Cari Ünvanı',
            'col.qty' => 'Adet',
            'col.balance' => 'Güncel Bakiye',
            'col.active' => 'Kayıt Aktif mi',
            'col.status' => 'Kayıt Durumu',
            'col.created' => 'Kayıt Tarihi',
        ]);
    }

    private function settings(): TabulaSettings
    {
        return new TabulaSettings(
            numbers: new NumberSettings(
                currencySymbols: ['TRY' => '₺'],
                symbolPosition: SymbolPosition::After,
            ),
        );
    }

    private function schema(): Schema
    {
        return Schema::make('customer')->title('sheet.customer')->fields(
            Field::string('code')->label('col.code')->required(),
            Field::string('name')->label('col.name'),
            Field::quantity('qty')->label('col.qty')->decimals(3),
            Field::money('balance')->label('col.balance')->currency('TRY'),
            Field::bool('isActive')->label('col.active'),
            Field::enum('status', Status::class)->label('col.status'),
            Field::date('createdAt')->label('col.created'),
        );
    }

    /**
     * Kullanıcının şablona yazdığı iki satır.
     *
     * İki satır BİLEREK farklı yollardan gelir. Excel'de doldurulan bir tarih hücresi
     * SERİ NUMARASI olarak saklanır (3. satır); dosyayı yapıştırarak ya da başka bir
     * sistemden doldurmuş kullanıcıda aynı hücre METİN olur (4. satır). İkisi de aynı
     * `DateTimeImmutable`e inmelidir.
     *
     * @return list<list<mixed>>
     */
    private function userRows(): array
    {
        return [
            ['0042', 'Çiğdem Şahin Ltd. Şti.', 12.5, '1.234,56 ₺', 'Evet', 'Açık', 45296],
            ['0043', 'Öz Güneş A.Ş.', 3, '-99,90', 'Hayır', 'Kapalı', '11.02.2024'],
        ];
    }

    // ---------------------------------------------------------------- yardımcılar

    private function template(Translator $translator, string $name, bool $includeKeyRow = true): string
    {
        $builder = new TemplateBuilder(
            $translator,
            $this->settings(),
            new TemplateOptions(includeKeyRow: $includeKeyRow),
        );

        $path = $this->dir->file($name);

        return $builder->write($this->schema(), $path, 'tr');
    }

    /**
     * Şablonu kullanıcı gibi doldurur: dosyayı AÇAR, veri satırlarını yazar, geri kaydeder.
     *
     * Dizeler AÇIK tiple yazılır — varsayılan bağlayıcı "0042"yi sayı sanıp 42'ye çevirirdi,
     * yani testin ölçmek istediği baştaki sıfır korumasını ölçmeden bozardı. (Şablonun metin
     * kolonu Excel'de zaten '@' biçimlidir; bu, o davranışın programatik karşılığıdır.)
     */
    private function fill(string $path, int $firstDataRow): void
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheet(0);

        foreach ($this->userRows() as $rowIndex => $cells) {
            foreach ($cells as $columnIndex => $value) {
                $coordinate = [$columnIndex + 1, $firstDataRow + $rowIndex];

                if (is_string($value)) {
                    $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
                } else {
                    $sheet->setCellValue($coordinate, $value);
                }
            }
        }

        (new SpreadsheetXlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
    }

    /**
     * @param list<ImportedRow> $collected
     */
    private function import(
        Translator $translator,
        string $path,
        array &$collected,
        MatchStrategy $strategy = MatchStrategy::Auto,
    ): ImportResult {
        $tabula = new Tabula($translator, $this->settings());

        return $tabula->import($this->schema())
            ->from($path)
            ->locale('tr')
            ->matchBy($strategy)
            ->each(static function (ImportedRow $row) use (&$collected): void {
                $collected[] = $row;
            })
            ->run();
    }

    private function dateOf(ImportedRow $row, string $key): DateTimeImmutable
    {
        $value = $row->get($key);

        if (!$value instanceof DateTimeImmutable) {
            self::fail(sprintf('"%s" alanı DateTimeImmutable olmalıydı, gelen: %s', $key, get_debug_type($value)));
        }

        return $value;
    }

    // ---------------------------------------------------------------- gidiş-dönüş

    #[Test]
    public function everyValueSurvivesTheRoundTripWithItsRightType(): void
    {
        $path = $this->template($this->translator(), 'sablon.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->translator(), $path, $rows);

        self::assertTrue($result->isCompletelySuccessful(), sprintf(
            'İçe aktarma hatasız bitmeliydi. Hatalar: %s',
            implode(' | ', array_map(static fn ($error): string => $error->row.': '.$error->message, $result->errors)),
        ));
        self::assertSame(2, $result->read);
        self::assertSame(2, $result->imported);
        self::assertSame(
            ['code', 'name', 'qty', 'balance', 'isActive', 'status', 'createdAt'],
            $result->columns,
            'Tanınan kolonlar dosyadaki sırayla ve kanonik anahtarlarla raporlanmalı.',
        );
        self::assertSame([], $result->ignored);

        self::assertCount(2, $rows);
        $first = $rows[0];
        $second = $rows[1];

        // Satır numarası KULLANICININ GÖRDÜĞÜ numaradır: veri 3. satırdan başlar.
        self::assertSame(3, $first->row);
        self::assertSame(4, $second->row);

        // Metin: baştaki sıfır yenmemeli (eski ERP'nin en sık bildirilen içe aktarma hatası).
        self::assertSame('0042', $first->get('code'));
        self::assertSame('Çiğdem Şahin Ltd. Şti.', $first->get('name'));

        // Miktar daima float, para daima float — kolonun tipi satırdan satıra değişmez.
        self::assertSame(12.5, $first->get('qty'));
        self::assertIsFloat($second->get('qty'));
        self::assertEqualsWithDelta(1234.56, $first->get('balance'), 0.0001);
        self::assertEqualsWithDelta(-99.9, $second->get('balance'), 0.0001);

        // Boole GERÇEK bool, "Evet" dizesi değil.
        self::assertTrue($first->get('isActive'));
        self::assertFalse($second->get('isActive'));
        self::assertIsBool($first->get('isActive'));

        // Enum ÖRNEĞİ: çağıran taraf dize eşleştirmesiyle uğraşmaz.
        self::assertSame(Status::Open, $first->get('status'));
        self::assertSame(Status::Closed, $second->get('status'));

        // Excel'in seri numarası ve kullanıcının yazdığı metin aynı güne inmeli.
        self::assertSame('2024-01-05 00:00:00', $this->dateOf($first, 'createdAt')->format('Y-m-d H:i:s'));
        self::assertSame('2024-02-11 00:00:00', $this->dateOf($second, 'createdAt')->format('Y-m-d H:i:s'));
    }

    /**
     * ★ ESKİ ERP'NİN ÖLÜMCÜL KUSURUNUN TAMİR EDİLDİĞİNİN KANITI.
     *
     * Aynı dosya, TÜM kolon etiketleri yeniden yazılmış bir katalogla ikinci kez içe
     * aktarılır ve çalışmaya devam eder — çünkü eşleştirme 1. satırdaki kanonik
     * anahtarlardan geçer, kullanıcının okuduğu başlıktan değil.
     *
     * Karşı uç aynı testte durur: `MatchStrategy::Label` açıkça "beni etiketle eşleştir"
     * demektir ve o yolda dosya artık tanınamaz. Eski ERP'nin TEK yolu buydu.
     */
    #[Test]
    public function theKeyRowSurvivesACompleteRetranslationWhereTheLabelWouldNot(): void
    {
        $path = $this->template($this->translator(), 'sablon.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->retranslated(), $path, $rows);

        self::assertTrue(
            $result->isCompletelySuccessful(),
            'Çeviri değişti diye kullanıcının elindeki şablon okunamaz hâle GELMEMELİ.',
        );
        self::assertSame(2, $result->imported);
        self::assertSame('0042', $rows[0]->get('code'));
        self::assertSame(Status::Open, $rows[0]->get('status'));

        // …ve şimdi aynı dosya, aynı katalogla, ama kimliği ETİKETTE arayarak:
        /** @var list<ImportedRow> $labelRows */
        $labelRows = [];

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('hiçbir kolon şemayla eşleşmedi');

        $this->import($this->retranslated(), $path, $labelRows, MatchStrategy::Label);
    }

    /**
     * Aynı kusur, anahtar satırı OLMAYAN bir dosyada hâlâ ölümcül.
     *
     * `TemplateOptions(includeKeyRow: false)` şablonu eski ERP'nin yerleşimine indirger:
     * dosyanın kimliği yeniden çevrilmiş başlık metnidir. Böyle bir dosya özgün katalogla
     * okunur, katalog değiştiği anda okunamaz olur. Anahtar satırının NEDEN varsayılan
     * olduğunu tek bir testte gösteren karşılaştırma budur.
     */
    #[Test]
    public function aFileWithoutTheKeyRowBreaksTheMomentTheTranslationChanges(): void
    {
        $path = $this->template($this->translator(), 'etiketli.xlsx', includeKeyRow: false);
        $this->fill($path, 2);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import($this->translator(), $path, $rows);

        self::assertTrue($result->isCompletelySuccessful(), 'Özgün katalogla etiket eşleşmesi çalışmalı.');
        self::assertSame(2, $result->imported);
        self::assertSame('0042', $rows[0]->get('code'));

        /** @var list<ImportedRow> $afterRename */
        $afterRename = [];

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Doğru şablonu indirdiğinizden emin olun.');

        $this->import($this->retranslated(), $path, $afterRename);
    }

    /**
     * SINIRIN kendisi: anahtar satırı KOLON kimliğini korur, HÜCRE İÇERİĞİNİ değil.
     *
     * Enum hücresine yazılan metin çevrilmiş etikettir ("Açık"). O etiket yeniden
     * yazılırsa daha önce doldurulmuş dosyadaki değer artık hiçbir case'e oturmaz ve satır
     * reddedilir. Bu, başlık tarafındaki kusurun hücre tarafındaki kalıntısıdır; belgelenmiş
     * olması, birinin "çeviri değişikliği artık hiçbir şeyi bozamaz" diye özetlemesini önler.
     */
    #[Test]
    public function retranslatingAValueLabelStillBreaksAnAlreadyFilledFile(): void
    {
        $path = $this->template($this->translator(), 'sablon.xlsx');
        $this->fill($path, 3);

        /** @var list<ImportedRow> $rows */
        $rows = [];
        $result = $this->import(
            $this->translator(['status.open' => 'Yeni Kayıt', 'status.closed' => 'Kapatılmış']),
            $path,
            $rows,
        );

        self::assertSame(2, $result->read);
        self::assertSame(0, $result->imported, 'Değer sözlüğü değişince eski dosyanın enum hücreleri tanınmaz.');

        $errors = $result->errorsByRow();

        self::assertArrayHasKey(3, $errors);
        self::assertSame('status', $errors[3][0]->field);
        self::assertStringContainsString('Seçenekler: Yeni Kayıt, Kapatılmış', $errors[3][0]->message);
        // Ham değer hatanın yanında taşınır: kullanıcı hangi hücreye bakacağını bilmeli.
        self::assertSame('Açık', $errors[3][0]->value);
    }
}
