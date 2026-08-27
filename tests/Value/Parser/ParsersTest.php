<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Value\Parser;

use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Format;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Settings\NumberSettings;
use Balin\Tabula\Settings\SymbolPosition;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Tests\Fixture\Status;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\FormatterRegistry;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\ParserRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ayrıştırıcı testleri.
 *
 * Bu dosyanın TEK bir teması var: AYRIŞTIRICILAR, BİÇİMLENDİRİCİLERİN HOŞGÖRÜLÜ OLDUĞU
 * YERDE KATIDIR. `NumberFormatter` okunamayan bir hücreyi boş basıp geçer, çünkü 40 bin
 * satırlık bir rapor tek bozuk hücre yüzünden ölmemeli. `NumberParser` aynı hücrede
 * istisna fırlatır, çünkü orada değer VERİTABANINA yazılacak ve "boş geç" demek yanlış
 * veri yazmak demektir.
 *
 * Bu asimetri kütüphanenin en kolay bozulan sözleşmesidir: iki taraf da aynı
 * `NumberFormatter::parse()` çağrısını paylaştığı için, birinin hoşgörüsünü diğerine
 * taşımak tek satırlık bir değişikliktir. Aşağıdaki testler farkı AÇIKÇA yan yana koyar.
 */
final class ParsersTest extends TestCase
{
    // ---------------------------------------------------------------- yardımcılar

    private function translator(string $yes = 'Evet', string $no = 'Hayır'): Translator
    {
        return new ArrayTranslator([
            'tr' => [
                'tabula.bool.yes' => $yes,
                'tabula.bool.no' => $no,
                'status.open' => 'Açık',
                'status.closed' => 'Kapalı',
                'opt.b2b' => 'Kurumsal',
                'opt.b2c' => 'Bireysel',
            ],
        ]);
    }

    private function settings(?NumberSettings $numbers = null): TabulaSettings
    {
        return new TabulaSettings(
            numbers: $numbers ?? new NumberSettings(
                currencySymbols: ['TRY' => '₺'],
                symbolPosition: SymbolPosition::After,
            ),
        );
    }

    private function context(?TabulaSettings $settings = null, ?Translator $translator = null): ParseContext
    {
        return new ParseContext(
            locale: 'tr',
            translator: $translator ?? $this->translator(),
            settings: $settings ?? $this->settings(),
        );
    }

    /** Biçimlendirici tarafının aynı ayarlarla kurulmuş bağlamı — asimetriyi ölçmek için. */
    private function formatContext(?TabulaSettings $settings = null): FormatContext
    {
        return new FormatContext(
            locale: 'tr',
            translator: $this->translator(),
            settings: $settings ?? $this->settings(),
            format: Format::Xlsx,
        );
    }

    private function parse(Field $field, mixed $raw, ?ParseContext $context = null): mixed
    {
        $context ??= $this->context();

        return ParserRegistry::default()->for($field)->parse($raw, $field, $context);
    }

    /** Tarih testlerinde tipi daraltan sarmalayıcı; `mixed` üzerinde `format()` çağrılamaz. */
    private function parseDate(Field $field, mixed $raw, ?ParseContext $context = null): DateTimeImmutable
    {
        $value = $this->parse($field, $raw, $context);

        if (!$value instanceof DateTimeImmutable) {
            self::fail(sprintf('Ayrıştırıcı DateTimeImmutable döndürmeliydi, gelen: %s', get_debug_type($value)));
        }

        return $value;
    }

    private function parseString(Field $field, mixed $raw): string
    {
        $value = $this->parse($field, $raw);

        if (!is_string($value)) {
            self::fail(sprintf('Ayrıştırıcı dize döndürmeliydi, gelen: %s', get_debug_type($value)));
        }

        return $value;
    }

    // ---------------------------------------------------------------- sayılar

    /** @return iterable<string, array{string, float}> */
    public static function signedNotations(): iterable
    {
        yield 'yerelleştirilmiş' => ['1.234,56', 1234.56];
        yield 'kanonik veritabanı dizesi' => ['1234.5600', 1234.56];
        yield 'baştaki eksi' => ['-1.234,56', -1234.56];
        yield 'muhasebe parantezi' => ['(1.234,56)', -1234.56];
        yield 'sondaki eksi (SAP/ERP)' => ['1.234,56-', -1234.56];
    }

    #[Test]
    #[DataProvider('signedNotations')]
    public function everyNotationKeepsItsSign(string $raw, float $expected): void
    {
        self::assertEqualsWithDelta(
            $expected,
            $this->parse(Field::decimal('amount'), $raw),
            0.0001,
            sprintf('"%s" yanlış okundu; muhasebede işaret kaybı sessiz bir veri bozulmasıdır.', $raw),
        );
    }

    /**
     * ★ Bu dosyanın var oluş sebebi: AYNI girdi, iki yönde iki farklı davranış.
     *
     * Biçimlendirici "N/A"yı boş hücreye çevirip raporu ayakta tutar; ayrıştırıcı aynı
     * değerde durur. İkisi de doğrudur, çünkü sonuçları farklı yere gider.
     */
    #[Test]
    public function junkIsAnEmptyCellWhenExportingButAnErrorWhenImporting(): void
    {
        $field = Field::decimal('amount');

        $cell = FormatterRegistry::default()
            ->for(FieldType::Decimal)
            ->format('N/A', $field, null, $this->formatContext());

        self::assertTrue(
            $cell->isEmpty(),
            'Dışa aktarmada okunamayan hücre boş basılır: tek bozuk satır 40 bin satırlık raporu düşürmemeli.',
        );

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"N/A" sayı olarak okunamadı.');

        $this->parse($field, 'N/A');
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonIntegerValues(): iterable
    {
        yield 'yerelleştirilmiş ondalık' => ['12,5'];
        yield 'kanonik ondalık' => ['12.5'];
        yield 'gerçek float' => [12.5];
    }

    #[Test]
    #[DataProvider('nonIntegerValues')]
    public function anIntegerFieldRefusesADecimalInsteadOfFlooringIt(mixed $raw): void
    {
        // Stok sayımı kolonuna yazılmış 12,5 bir yazım hatasıdır; 12'ye yuvarlamak yarım
        // kutuyu kayıttan silmek olur. Kullanıcı hatayı GÖRMELİ.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('tam sayı değil');

        $this->parse(Field::integer('count'), $raw);
    }

    #[Test]
    public function numericTypesKeepTheirTypeContractAcrossRows(): void
    {
        // Integer daima int, Decimal/Quantity daima float: aynı kolonun bir satırda int,
        // diğerinde float dönmesi entity setter'ında sessiz tip sürprizi üretir.
        self::assertSame(12, $this->parse(Field::integer('count'), '12'));
        self::assertSame(12, $this->parse(Field::integer('count'), 12.0));
        // Aynı ayırıcı birden çok kez geçiyorsa gruplayıcıdır — belirsizlik yok.
        self::assertSame(1234567, $this->parse(Field::integer('count'), '1.234.567'));

        self::assertIsFloat($this->parse(Field::decimal('ratio'), '12'));
        self::assertIsFloat($this->parse(Field::quantity('qty'), 3));
        self::assertIsFloat($this->parse(Field::money('balance'), 3));
    }

    // ---------------------------------------------------------------- para

    #[Test]
    public function moneyStripsTheCurrencySymbol(): void
    {
        $field = Field::money('balance');

        self::assertEqualsWithDelta(1234.56, $this->parse($field, '1.234,56 ₺'), 0.0001);
        self::assertEqualsWithDelta(1234.56, $this->parse($field, '₺ 1.234,56'), 0.0001);
        self::assertEqualsWithDelta(-1234.56, $this->parse($field, '(1.234,56 ₺)'), 0.0001);
    }

    /**
     * Simgeyi elle sökmenin gerekçesi.
     *
     * Nokta taşıyan bir simge (`S/.`) temizlik sonrası "1.234,56." bırakır; en sağdaki
     * nokta artık ondalık sayılmaz ve tutar YÜZ KAT büyük okunur.
     */
    #[Test]
    public function aSymbolContainingADotDoesNotMultiplyTheAmountByAHundred(): void
    {
        $settings = $this->settings(new NumberSettings(currencySymbols: ['PEN' => 'S/.']));
        $field = Field::money('balance')->currency('PEN');

        self::assertEqualsWithDelta(
            1234.56,
            $this->parse($field, '1.234,56 S/.', $this->context($settings)),
            0.0001,
            'Simge sökülmeseydi sondaki nokta ondalık sayılmaz, tutar 123456 olarak okunurdu.',
        );
    }

    // ---------------------------------------------------------------- boole

    /** @return iterable<string, array{mixed, bool}> */
    public static function booleanNotations(): iterable
    {
        yield 'şablonun yazdığı evet' => ['Evet', true];
        yield 'şablonun yazdığı hayır' => ['Hayır', false];
        yield 'küçük harf' => ['evet', true];
        yield 'noktasız hayir' => ['hayir', false];
        yield 'bir' => ['1', true];
        yield 'sıfır' => ['0', false];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
        yield 'tinyint(1)' => [1, true];
        yield 'gerçek boole' => [false, false];
        yield 'excel sayısal hücresi' => [0.0, false];
        yield 'kırpılmış' => ['  Evet  ', true];
    }

    #[Test]
    #[DataProvider('booleanNotations')]
    public function boolAcceptsEveryUsualNotation(mixed $raw, bool $expected): void
    {
        self::assertSame($expected, $this->parse(Field::bool('isActive'), $raw));
    }

    /**
     * Evet/Hayır ikilisi KATALOGDAN okunur, gömülü listeden değil.
     *
     * Şablonun açılır listesine yazılan metin de aynı iki anahtardan gelir
     * (`TabulaSettings::$boolTrueKey/$boolFalseKey`), dolayısıyla gidiş-dönüş kapanır.
     * Eski ERP'de hücre metnini bir çeviri ailesi, doğrulama listesini başka bir aile
     * üretiyordu; kendi yazdığı değer kendi listesinde bulunmuyordu.
     */
    #[Test]
    public function boolReadsTheYesNoPairFromTheCatalogue(): void
    {
        $context = $this->context(translator: $this->translator('Aktif', 'Pasif'));

        self::assertTrue($this->parse(Field::bool('isActive'), 'Aktif', $context));
        self::assertFalse($this->parse(Field::bool('isActive'), 'Pasif', $context));
    }

    #[Test]
    public function boolGarbageThrowsAndTheMessageNamesTheAcceptedForms(): void
    {
        $field = Field::bool('isActive');
        $context = $this->context(translator: $this->translator('Aktif', 'Pasif'));

        // Yine asimetri: biçimlendirici tanımadığı değeri boş hücre yapıp geçer.
        $cell = FormatterRegistry::default()
            ->for(FieldType::Bool)
            ->format('belki', $field, null, $this->formatContext());

        self::assertTrue($cell->isEmpty());

        try {
            $this->parse($field, 'belki', $context);

            self::fail('Tanınmayan boole değeri istisna fırlatmalıydı; uydurma bir "Hayır" veritabanına yazılamaz.');
        } catch (ParseException $exception) {
            $message = $exception->getMessage();

            // Kullanıcı hatayı ancak NE YAZABİLECEĞİNİ görürse düzeltebilir.
            foreach (['Aktif', 'Pasif', 'Evet', 'Hayır', '1', '0', 'true', 'false'] as $accepted) {
                self::assertStringContainsString(
                    $accepted,
                    $message,
                    sprintf('Hata mesajı kabul edilen "%s" biçimini saymalı.', $accepted),
                );
            }
        }
    }

    // ---------------------------------------------------------------- tarihler

    #[Test]
    public function aReadyMadeObjectPassesThroughAndADateFieldZeroesTheTime(): void
    {
        $date = $this->parseDate(Field::date('createdAt'), new DateTimeImmutable('2024-01-05 13:45:00'));

        // Saat artığı kalmaz: aynı gün her satırda aynı değeri alsın, BETWEEN gün sonunu kaçırmasın.
        self::assertSame('2024-01-05 00:00:00', $date->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function aDateTimeFieldKeepsTheTimeOfDay(): void
    {
        $date = $this->parseDate(Field::dateTime('createdAt'), '05.01.2024 09:30');

        self::assertSame('2024-01-05 09:30:00', $date->format('Y-m-d H:i:s'));
    }

    /**
     * ★ Seri numarası dönüşümü İKİ YÖNDE de elle yazılmıştır (ne `DateFormatter` ne
     * `DateParser` PhpSpreadsheet'e bağlıdır). İki formülün birbirinin tersi olduğunu
     * varsaymak yetmez; burada ölçülür.
     */
    #[Test]
    public function theExcelSerialIsTheExactInverseOfTheFormattersForwardConversion(): void
    {
        $field = Field::date('createdAt');

        $cell = FormatterRegistry::default()
            ->for(FieldType::Date)
            ->format(new DateTimeImmutable('2024-01-05 00:00:00'), $field, null, $this->formatContext());

        self::assertSame(45296.0, $cell->value, 'Biçimlendirici 2024-01-05 icin 45296 seri numarasını yazmalı.');

        $back = $this->parseDate($field, $cell->value);

        self::assertSame('2024-01-05', $back->format('Y-m-d'), 'Ayrıştırıcı aynı seri numarasından aynı günü geri vermeli.');
    }

    #[Test]
    public function bothThePatternAndIsoTextAreAccepted(): void
    {
        $field = Field::date('createdAt');

        // Kullanıcının şablonda GÖRDÜĞÜ biçim…
        self::assertSame('2024-01-05', $this->parseDate($field, '05.01.2024')->format('Y-m-d'));
        // …ve başka bir sistemin ürettiği kanonik gösterim.
        self::assertSame('2024-01-05', $this->parseDate($field, '2024-01-05')->format('Y-m-d'));
    }

    /** @return iterable<string, array{string}> */
    public static function unparsableDates(): iterable
    {
        yield 'olmayan gün ve ay' => ['32.13.2024'];
        yield 'MySQL sıfır tarihi' => ['0000-00-00'];
        yield 'MySQL sıfır tarih/saati' => ['0000-00-00 00:00:00'];
        yield 'serbest metin' => ['yarın'];
    }

    #[Test]
    #[DataProvider('unparsableDates')]
    public function impossibleDatesAreRejectedAndTheMessageNamesTheExpectedPattern(string $raw): void
    {
        // "32.13.2024" `createFromFormat`ta sessizce bir sonraki aya taşardı; sıfır tarihi
        // ise PHP tarafından MÖ 1'e ayrıştırılırdı. İkisi de veritabanına gitmemeli.
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('beklenen biçim: d.m.Y');

        $this->parse(Field::date('createdAt'), $raw);
    }

    // ---------------------------------------------------------------- enum / seçenek

    #[Test]
    public function anEnumMapsTheTranslatedLabelBackToTheCase(): void
    {
        $field = Field::enum('status', Status::class);

        // Kullanıcının hücrede gördüğü metin budur; gidiş-dönüşün kapandığı yer burasıdır.
        self::assertSame(Status::Open, $this->parse($field, 'Açık'));
        self::assertSame(Status::Closed, $this->parse($field, ' Kapalı '));
        self::assertSame(Status::Open, $this->parse($field, 'açık'));
    }

    #[Test]
    public function anEnumAlsoAcceptsTheBackingValueAndTheCaseName(): void
    {
        $field = Field::enum('status', Status::class);

        // Dosyayı bir geliştirici ya da başka bir sistem doldurmuş olabilir.
        self::assertSame(Status::Open, $this->parse($field, 'open'));
        self::assertSame(Status::Closed, $this->parse($field, 'Closed'));
        self::assertSame(Status::Open, $this->parse($field, Status::Open));
    }

    #[Test]
    public function anUnknownLabelThrowsAndListsTheOptions(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('"Beklemede" listede yok. Seçenekler: Açık, Kapalı');

        $this->parse(Field::enum('status', Status::class), 'Beklemede');
    }

    #[Test]
    public function anOptionSetMapsLabelTranslationKeyAndRawKeyBackToTheKey(): void
    {
        $field = Field::options('type', ['b2b' => 'opt.b2b', 'b2c' => 'opt.b2c']);

        self::assertSame('b2b', $this->parse($field, 'Kurumsal'));
        self::assertSame('b2b', $this->parse($field, 'opt.b2b'));
        self::assertSame('b2c', $this->parse($field, 'b2c'));
    }

    // ---------------------------------------------------------------- metin

    /**
     * ★ Barkod bozulması: Excel `8691234567890123` hücresini sayı sanar ve okuyucuya
     * float olarak verir. Düz `(string)` cast'ı bunu üstel gösterimle veritabanına yazar
     * ve değer bir daha kurtarılamaz.
     */
    #[Test]
    public function anExcelFloatNeverComesBackInExponentForm(): void
    {
        $field = Field::string('barcode');

        self::assertSame('8691234567890123', $this->parseString($field, 8691234567890123.0));

        $huge = $this->parseString($field, 1.0E+25);

        self::assertMatchesRegularExpression('/^\d+$/', $huge, 'Üstel gösterim veritabanına yazılamaz.');
        self::assertStringNotContainsString('E', $huge);
    }

    #[Test]
    public function aStringFieldPreservesLeadingZerosAndNeverSwallowsFalse(): void
    {
        $field = Field::string('code');

        self::assertSame('0501', $this->parseString($field, '0501'));
        // `(string) false` boş dize üretir; metin kolonunda bu veriyi sessizce yutmaktır.
        self::assertSame('Evet', $this->parseString($field, true));
        self::assertSame('Hayır', $this->parseString($field, false));
    }

    // ---------------------------------------------------------------- boş hücre

    /** @return iterable<string, array{Field}> */
    public static function everyFieldType(): iterable
    {
        yield 'metin' => [Field::string('value')];
        yield 'tam sayı' => [Field::integer('value')];
        yield 'ondalık' => [Field::decimal('value')];
        yield 'para' => [Field::money('value')];
        yield 'miktar' => [Field::quantity('value')];
        yield 'boole' => [Field::bool('value')];
        yield 'tarih' => [Field::date('value')];
        yield 'tarih/saat' => [Field::dateTime('value')];
        yield 'enum' => [Field::enum('value', Status::class)];
        yield 'seçenek' => [Field::options('value', ['b2b' => 'opt.b2b'])];
    }

    /**
     * Boşluk bir HATA DEĞİLDİR; "değer yok" demektir.
     *
     * Zorunluluk denetimi alanı bilen içe aktarma döngüsünün işidir. Ayrıştırıcı burada
     * istisna fırlatsaydı, zorunlu olmayan her boş hücre satır hatasına dönerdi.
     */
    #[Test]
    #[DataProvider('everyFieldType')]
    public function anEmptyCellIsNullForEveryParser(Field $field): void
    {
        // Kırılmaz boşluk ve BOM `trim()` ile temizlenmez; elektronik tablolardan gelen
        // hücrelerde ikisi de sıradan kalıntıdır.
        foreach ([null, '', '   ', "\u{00A0}", "\u{FEFF} ", "\u{202F}"] as $blank) {
            self::assertNull(
                $this->parse($field, $blank),
                sprintf('%s alanında %s boş sayılmalı.', $field->getType()->value, var_export($blank, true)),
            );
        }
    }
}
