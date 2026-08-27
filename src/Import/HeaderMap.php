<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\Parser\StringParser;
use Closure;

/**
 * Dosyanın başlıklarını şemanın alanlarına bağlar — ESKİ ERP'NİN KUSURUNUN TAMİR EDİLDİĞİ YER.
 *
 * ★ ŞABLON YERLEŞİMİ (bkz. `TemplateBuilder`):
 *
 *     1. satır → KANONİK ALAN ANAHTARLARI (xlsx'te gizli, csv'de görünür ama zararsız)
 *     2. satır → çevrilmiş etiketler (kullanıcının okuduğu)
 *     3. satır → veri
 *
 * Karar için dosyada HİÇBİR İŞARET ARANMAZ: 1. satırdaki boş olmayan hücrelerin HEPSİ
 * şemadaki bir anahtara oturuyorsa o satır anahtar satırıdır ve veri 3. satırdan başlar;
 * oturmuyorsa 1. satır etiket başlığıdır ve veri 2. satırdan başlar. Gizli bir sürüm
 * numarası, imza hücresi ya da dosya adı kuralı gerekmez — hiçbiri kullanıcının
 * "farklı kaydet" yapmasına dayanamazdı.
 *
 * Eski ERP'de dosyanın kimliği ÇEVRİLMİŞ BAŞLIK DİZESİYDİ ("Müşteri Kodu"). Sonuçları:
 * çeviri dosyasındaki tek bir kelime değişikliği kullanıcıların elindeki tüm şablonları
 * sessizce okunamaz hâle getiriyordu ve İngilizce başlıklı bir dosya Türkçe oturumda hiç
 * eşleşmiyordu. Burada kimlik ANAHTARDIR; etiket yalnızca eski dosyalar için bir yedek yol.
 *
 * Sınıf SAF ve bağımsız test edilebilirdir: dosya açmaz, satır okumaz, yalnız iki başlık
 * satırıyla şemayı karşılaştırır.
 */
final readonly class HeaderMap
{
    /** Anahtar satırlı yerleşimde veri 3. satırdan başlar (1: anahtar, 2: etiket). */
    private const int KEY_ROW_DATA_START = 3;

    /** Anahtar satırı yoksa 1. satır başlıktır, veri 2. satırdan başlar. */
    private const int LABEL_ROW_DATA_START = 2;

    /**
     * @param array<int, Field> $fields       kolon indeksi => alan, DOSYADAKİ sırayla
     * @param int               $firstDataRow ilk veri satırının kullanıcı-görünür numarası
     * @param bool              $usedKeyRow   eşleşme kanonik anahtarlarla mı yapıldı
     * @param list<string>      $ignored      şemada karşılığı olmayan (ya da tekrarlanan) başlıklar
     */
    private function __construct(
        public array $fields,
        public int $firstDataRow,
        public bool $usedKeyRow,
        public array $ignored,
    ) {
    }

    /**
     * Dosyanın ilk iki satırından eşlemeyi çıkarır.
     *
     * İKİ satır istenir çünkü anahtar satırlı yerleşimde 2. satır, eşleşmeyen kolonların
     * İNSAN ADIDIR: kullanıcı şablona kendi "Notlar" sütununu eklediğinde o kolonun 1.
     * satırı boştur ve "yok sayılan başlık: ''" diyen bir rapor kimseye hangi kolondan
     * bahsettiğini söylemez. Eşleştirme kararına 2. satır KARIŞMAZ, yalnız raporlar.
     *
     * @param list<mixed> $firstRow  dosyanın 1. satırı, hücreler ham hâliyle
     * @param list<mixed> $secondRow dosyanın 2. satırı; yoksa boş dizi
     *
     * @throws ImportException anahtar satırı zorunluyken yoksa ya da hiçbir kolon eşleşmezse
     */
    public static function resolve(
        array $firstRow,
        array $secondRow,
        Schema $schema,
        MatchStrategy $strategy,
        ParseContext $context,
    ): self {
        // `Label` anahtar satırını HİÇ ARAMAZ: eski, elle hazırlanmış dosyalarla geriye
        // dönük uyum için vardır ve orada 1. satır her zaman etikettir.
        $keys = MatchStrategy::Label === $strategy ? null : self::detectKeyRow($firstRow, $schema);

        // Makineden makineye akışlarda etikete düşmek SESSİZ bir kayıptır: çeviri
        // değiştiğinde besleme çalışmaya devam ediyormuş gibi görünüp yanlış kolonu okur.
        // `Key` istendiyse ya anahtar satırı vardır ya da dosya reddedilir.
        if (MatchStrategy::Key === $strategy && null === $keys) {
            throw ImportException::keyRowMissing();
        }

        return null === $keys
            ? self::byLabel($firstRow, $schema, $context)
            : self::byKey($keys, $firstRow, $secondRow, $schema, $context);
    }

    /**
     * Dosyada tanınan alan anahtarları, DOSYADAKİ sırayla.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        return array_values(array_map(
            static fn (Field $field): string => $field->getKey(),
            $this->fields,
        ));
    }

    // ---------------------------------------------------------------- anahtar satırı

    /**
     * 1. satır anahtar satırı mı? Öyleyse kolon indeksi => anahtar eşlemesi.
     *
     * @param list<mixed> $row
     *
     * @return array<int, string>|null null = anahtar satırı değil
     */
    private static function detectKeyRow(array $row, Schema $schema): ?array
    {
        $keys = [];

        foreach ($row as $index => $cell) {
            // Boş hücre kararı BOZMAZ: kullanıcının şablona eklediği fazladan sütunun
            // gizli anahtar satırında karşılığı yoktur ve bu, dosyayı şablon olmaktan
            // çıkarmamalı. O kolon sonra `ignored`e düşer.
            if (StringParser::isBlank($cell)) {
                continue;
            }

            $text = StringParser::describe($cell);

            // ★ Anahtar eşleşmesi BÜYÜK/küçük harfe DUYARLIDIR. Anahtar kanonik bir
            // tanımlayıcıdır, insanın okuduğu bir metin değil; şema `code` ve `Code`
            // alanlarını ayrı ayrı taşıyabilir ve harf katlamak ikisini karıştırırdı.
            // Etiket tarafındaki hoşgörü (aşağıda) tam tersi gerekçeyle vardır.
            if (!$schema->has($text)) {
                return null;
            }

            $keys[$index] = $text;
        }

        // Tamamen boş bir 1. satır "boş olmayan her hücre eşleşti" koşulunu boş yere
        // sağlar. Onu anahtar satırı saymak, dosyanın ilk iki satırını başlık sanıp
        // GERÇEK VERİYİ yutmak olurdu.
        return [] === $keys ? null : $keys;
    }

    /**
     * @param array<int, string> $keys     kolon indeksi => kanonik anahtar
     * @param list<mixed>        $keyRow   1. satırın tamamı — dosyanın genişliği buradan
     * @param list<mixed>        $labelRow 2. satır — yok sayılan kolonların insan adı
     */
    private static function byKey(array $keys, array $keyRow, array $labelRow, Schema $schema, ParseContext $context): self
    {
        $fields = [];
        $ignored = [];
        $taken = [];

        $width = max(\count($keyRow), \count($labelRow));

        for ($index = 0; $index < $width; ++$index) {
            $key = $keys[$index] ?? null;

            if (null === $key) {
                // Anahtar satırında karşılığı olmayan kolon: kullanıcının elle eklediği
                // sütun. Kimliği 2. satırdan, yani onun GÖRDÜĞÜ etiketten okunur.
                $label = self::textAt($labelRow, $index);

                if ('' !== $label) {
                    $ignored[] = $label;
                }

                continue;
            }

            // Aynı alanı gösteren ikinci başlık: İLKİ kazanır. İkincisine bağlamak,
            // kullanıcının ilk kolona yazdığı veriyi sessizce çöpe atmak olurdu.
            if (isset($taken[$key])) {
                $ignored[] = $key;

                continue;
            }

            $taken[$key] = true;
            $fields[$index] = $schema->field($key);
        }

        // `$fields` boş olamaz: `detectKeyRow()` en az bir eşleşen anahtar bulmadan
        // anahtar satırı demiyor ve ilk anahtar her zaman alınıyor.
        return new self($fields, self::dataStartAfterKeyRow($fields, $keyRow, $labelRow, $context), true, $ignored);
    }

    /**
     * Anahtar satırından SONRA veri kaçıncı satırdan başlar?
     *
     * ★ BU KONTROL BİR SESSİZ VERİ KAYBINI KAPATIR. Kural koşulsuz uygulansaydı
     * ("anahtar satırı varsa veri 3. satırdan başlar") şu dosya ilk kaydını yerdi:
     *
     *     code;name        ← `export()`in yazdığı TEK başlık satırı
     *     A-1;Bir          ← VERİ — "etiket satırı" sanılıp atlanırdı
     *     A-2;İki
     *
     * Çünkü alanın etiketi anahtarının AYNISI olabilir: `->label()` hiç verilmemişse
     * `Column::fromField()` başlığa anahtarı yazar (çevirmen de yoksa aynen kalır). O
     * durumda 1. satır hem anahtar satırı hem etiket satırı gibi okunur — ve dışa
     * aktarılan bir dosyayı geri okumak her seferinde ilk satırı yutardı. Kütüphanenin
     * var oluş sebebi tam olarak bu sınıf hatayı ortadan kaldırmak.
     *
     * Karar 2. SATIRA sorulur: etiket anahtarın aynısıysa gerçek şablonun 1. ve 2.
     * satırı BİREBİR aynıdır (`TemplateBuilder` ikisini de yazar). Aynı değilse 2. satır
     * veridir.
     *
     * Belirsizlik yoksa (etiketler anahtarlardan farklıysa — yani neredeyse her gerçek
     * şemada) bu metot ilk `if`te çıkar ve hiçbir şey değişmez.
     *
     * @param array<int, Field> $fields
     * @param list<mixed>       $keyRow
     * @param list<mixed>       $labelRow
     */
    private static function dataStartAfterKeyRow(array $fields, array $keyRow, array $labelRow, ParseContext $context): int
    {
        foreach ($fields as $index => $field) {
            // Tek bir kolonun bile etiketi anahtarından farklıysa 1. satır etiket satırı
            // OLARAK OKUNAMAZ; belirsizlik yok, yerleşim şablonun kendisi.
            if (self::fold(self::labelOf($field, $context)) !== self::foldedAt($keyRow, $index)) {
                return self::KEY_ROW_DATA_START;
            }
        }

        foreach (array_keys($fields) as $index) {
            if (self::foldedAt($labelRow, $index) !== self::foldedAt($keyRow, $index)) {
                return self::LABEL_ROW_DATA_START;
            }
        }

        return self::KEY_ROW_DATA_START;
    }

    // ---------------------------------------------------------------- etiket satırı

    /**
     * 1. satırı ÇEVRİLMİŞ ETİKETLERLE eşleştirir — eski dosyalar için yedek yol.
     *
     * Etiketler dosyadan değil ŞEMADAN üretilir ve `Column::fromField()` ile birebir aynı
     * kuralla çözülür (kapanış → çağır, dize → çevir, hiç yoksa anahtarın kendisi). İki
     * yerde iki farklı etiket üretmek, şablonun başlığıyla içe aktarmanın beklediği
     * başlığın ayrışması demekti — eski ERP'de dışa aktarma bir çeviri ailesinden,
     * içe aktarma başka bir aileden okuyordu.
     *
     * @param list<mixed> $headerRow
     */
    private static function byLabel(array $headerRow, Schema $schema, ParseContext $context): self
    {
        /** @var array<string, Field> $byLabel */
        $byLabel = [];
        /** @var list<string> $expected */
        $expected = [];

        foreach ($schema->getFields() as $field) {
            $label = self::labelOf($field, $context);
            $expected[] = $label;

            // İlk alan kazanır: iki alanın çevirisi aynıysa (ör. ikisi de "Tutar")
            // ikincisi hiçbir zaman eşleşemez. Sessizce ikinciye bağlamak, kullanıcının
            // birinci kolona yazdığını başka bir alana yazmak olurdu.
            $byLabel[self::fold($label)] ??= $field;
        }

        $fields = [];
        $ignored = [];
        $found = [];
        $taken = [];

        foreach ($headerRow as $index => $cell) {
            if (StringParser::isBlank($cell)) {
                continue;
            }

            $text = StringParser::describe($cell);
            $found[] = $text;

            $field = $byLabel[self::fold($text)] ?? null;

            if (null === $field || isset($taken[$field->getKey()])) {
                $ignored[] = $text;

                continue;
            }

            $taken[$field->getKey()] = true;
            $fields[$index] = $field;
        }

        // Hiçbir kolon tutmadıysa dosya bu şema için kullanılamaz. Boş bir sonuç
        // döndürmek ("0 satır aktarıldı") kullanıcıya dosyanın kabul edildiğini
        // düşündürürdü; mesaj hem bulunanları hem beklenenleri sayar.
        if ([] === $fields) {
            throw ImportException::noMatchingColumns($found, $expected);
        }

        return new self($fields, self::LABEL_ROW_DATA_START, false, $ignored);
    }

    /** `Column::fromField()` ile AYNI etiket çözümü — ikisi ayrışamaz. */
    private static function labelOf(Field $field, ParseContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // Etiket verilmemişse anahtarın kendisi başlık olur — kolon asla başlıksız kalmaz.
        return $context->trans($label ?? $field->getKey());
    }

    /**
     * Etiket karşılaştırma biçimi: görünmez boşluklardan arındırılmış, kırpılmış, harf katlanmış.
     *
     * `mb_strtolower()` YERELDEN BAĞIMSIZDIR ve bu burada bir kusur değil, tam olarak
     * aranan özelliktir: iki taraf da aynı işlevden geçtiği için "TUTAR" ile "Tutar"
     * buluşur. Yerele duyarlı bir katlama, sunucunun diline göre "I" harfini bir gün "ı"
     * bir gün "i" yapar ve aynı dosya iki farklı ortamda farklı eşleşirdi.
     */
    private static function fold(string $text): string
    {
        return mb_strtolower(StringParser::clean($text));
    }

    /**
     * Satırdaki hücrenin temizlenmiş metni; hücre yoksa ya da boşsa boş dize.
     *
     * @param list<mixed> $row
     */
    private static function textAt(array $row, int $index): string
    {
        $cell = $row[$index] ?? null;

        return StringParser::isBlank($cell) ? '' : StringParser::describe($cell);
    }

    /**
     * `textAt()`in karşılaştırmaya hazır hâli.
     *
     * @param list<mixed> $row
     */
    private static function foldedAt(array $row, int $index): string
    {
        return self::fold(self::textAt($row, $index));
    }
}
