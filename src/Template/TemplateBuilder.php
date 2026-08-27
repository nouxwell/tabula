<?php

declare(strict_types=1);

namespace Balin\Tabula\Template;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Format;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\FormatterRegistry;
use Closure;
use PhpOffice\PhpSpreadsheet\Cell\AddressRange;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;
use UnitEnum;

/**
 * Boş içe aktarma şablonu üretir — gidiş-dönüşün "dönüş" ucunun sözleşmesi.
 *
 * ★ YERLEŞİM, BU SINIFIN TAMAMINI AÇIKLAYAN KARARDIR:
 *
 *     1. satır → KANONİK ALAN ANAHTARLARI (xlsx'te gizli)
 *     2. satır → çevrilmiş etiketler (kullanıcının okuduğu)
 *     3. satır → veri
 *
 * Dosyanın kimliği anahtardır, başlık METNİ değil. İçe aktarma tarafı hiçbir işarete
 * bakmadan karar verebilir: 1. satırdaki boş olmayan hücrelerin HEPSİ şemadaki bir
 * anahtara oturuyorsa o satır anahtar satırıdır; oturmuyorsa 1. satır etiket başlığıdır
 * ve veri 2. satırdan başlar. Bu yüzden bizim ürettiğimiz şablon kusursuz gidiş-dönüş
 * yapar, kullanıcının elle hazırladığı dosya da çalışmaya devam eder.
 *
 * Eski ERP'de dosyanın kimliği ÇEVRİLMİŞ BAŞLIK DİZESİYDİ: çeviri dosyasındaki tek bir
 * kelime değişikliği, kullanıcıların elindeki tüm şablonları sessizce okunamaz hâle
 * getiriyordu. Buradaki gizli anahtar satırı o kusurun tamiridir.
 */
final class TemplateBuilder
{
    /** Excel'in sayfa adı sınırı — `XlsxWriter` ile aynı. */
    private const int TITLE_MAX_LENGTH = 31;

    /**
     * Excel'in sayfa adında yasakladığı karakterler.
     *
     * `XlsxWriter::INVALID_TITLE_CHARACTERS` ile birebir aynıdır; oradaki sabit `private`
     * olduğu için kopyalandı. Ad çoğu zaman şema başlığından, yani çeviriden gelir —
     * "Satış/İade" gibi tek bir bölü işareti `setTitle()` doğrulamasını patlatır ve şablon
     * üretimi, kullanıcının hiç kontrol edemediği bir metin yüzünden ölürdü.
     *
     * @var list<string>
     */
    private const array INVALID_TITLE_CHARACTERS = ['*', ':', '/', '\\', '?', '[', ']'];

    /** Adı tamamen yasak karakterlerden ibaret olan (ya da yardımcı sayfayla çakışan) şablonlar için. */
    private const string FALLBACK_TITLE = 'Sablon';

    /**
     * Açılır liste kaynaklarının tutulduğu gizli yardımcı sayfa.
     *
     * Seçenekler doğrudan doğrulamanın içine de gömülebilirdi ("Evet,Hayır"), ama Excel
     * gömülü listeyi 255 KARAKTERLE sınırlar ve sınırı aşan dosyayı "onarılması gerekiyor"
     * diye açar. Otuz durumlu bir sipariş enum'u bu sınırı rahatça aşar. Aralık gösterimi
     * sınırsızdır, üstelik aynı listeyi paylaşan kolonlar tek bir aralığa bakar.
     */
    private const string LIST_SHEET_TITLE = '_lists';

    /**
     * Hücre değerinden hem çıktı metnini hem açılır liste seçeneklerini üreten kayıt defteri.
     *
     * ★ İkisinin AYNI çağrıdan türemesi bu sınıfın ikinci sözleşmesidir. Eski ERP hücreye
     * yazdığı metni bir çeviri ailesinden (`general.yes`), şablonun izin listesini BAŞKA
     * bir aileden (`form.true`) üretiyordu; sonuçta kendi yazdığı değer kendi izin
     * listesinde bulunmuyordu. Burada seçenek listesi, dışa aktarmanın kullandığı
     * biçimlendiricinin ta kendisine sorularak üretilir — ikisinin ayrışması imkânsızdır.
     */
    private readonly FormatterRegistry $formatters;

    public function __construct(
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly TemplateOptions $options = new TemplateOptions(),
    ) {
        $this->formatters = FormatterRegistry::default();
    }

    /**
     * Şablonu diske yazar.
     *
     * @return string yazılan dosyanın yolu (verilen yolun aynısı)
     */
    public function write(Schema $schema, string $path, string $locale): string
    {
        // `Field::only()` ile yalnız PDF'te görünen bir alan şablonda da olmamalı:
        // kullanıcıya dolduramayacağı, içe aktarmanın da beklemediği bir kolon sunmak
        // "bu alan neden çalışmıyor" sorusunu doğurur.
        $schema = $schema->forFormat(Format::Xlsx);
        $fields = array_values($schema->getFields());

        if ([] === $fields) {
            throw ExportException::noColumns($schema->getName(), Format::Xlsx);
        }

        $this->guardTarget($path);

        $context = new FormatContext(
            locale: $locale,
            translator: $this->translator,
            settings: $this->settings,
            format: Format::Xlsx,
        );

        $keyRow = $this->options->includeKeyRow ? 1 : null;
        $labelRow = null === $keyRow ? 1 : 2;
        $firstDataRow = $labelRow + 1;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator($this->options->xlsx->creator);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle($this->sheetTitle($schema, $context), false, true);

            $letters = [];
            foreach ($fields as $index => $field) {
                $letters[] = Coordinate::stringFromColumnIndex($index + 1);
            }

            $lastLetter = $letters[\count($letters) - 1];

            $this->writeHeader($sheet, $fields, $letters, $keyRow, $labelRow, $context);
            $this->applyColumnStyles($sheet, $fields, $letters);
            $lastSampleRow = $this->createSampleRows($sheet, $lastLetter, $firstDataRow);
            $this->applyDropdowns($spreadsheet, $sheet, $fields, $letters, $firstDataRow, $context);

            if ($this->options->xlsx->freezeHeader) {
                // "A3'ü dondur" = anahtar VE etiket satırlarının ikisi de sabit kalsın.
                // `XlsxWriter` tek başlık satırı için A2 dondurur; buradaki fark tam olarak
                // gizli anahtar satırının varlığından gelir.
                $sheet->freezePane('A'.$firstDataRow);
            }

            if ($this->options->xlsx->autoFilter) {
                $sheet->setAutoFilter('A'.$labelRow.':'.$lastLetter.max($labelRow, $lastSampleRow));
            }

            // Kullanıcı dosyayı VERİ sayfasında açsın; gizli "_lists" aktif sekme olamaz zaten.
            $spreadsheet->setActiveSheetIndex(0);

            $writer = new SpreadsheetXlsxWriter($spreadsheet);
            $writer->save($path);
        } catch (SpreadsheetException $exception) {
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Worksheet ile Spreadsheet birbirini tutar; sayaç tabanlı çöp toplayıcı bu
            // döngüyü çözemez. Peş peşe şablon üreten uzun ömürlü işçilerde şart.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        return $path;
    }

    // ---------------------------------------------------------------- başlık

    /**
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function writeHeader(
        Worksheet $sheet,
        array $fields,
        array $letters,
        ?int $keyRow,
        int $labelRow,
        FormatContext $context,
    ): void {
        foreach ($fields as $index => $field) {
            $letter = $letters[$index];

            if (null !== $keyRow) {
                // Anahtar AÇIK tiple yazılır. Varsayılan bağlayıcı "0501" gibi bir alan
                // anahtarını 501 sayısına çevirir ve dosya geri okunduğunda hiçbir şeye
                // eşleşmez — anahtar satırının tüm anlamı birebir eşleşmesinde.
                $sheet->setCellValueExplicit($letter.$keyRow, $field->getKey(), DataType::TYPE_STRING);
            }

            $sheet->setCellValueExplicit($letter.$labelRow, $this->labelOf($field, $context), DataType::TYPE_STRING);

            $dimension = $sheet->getColumnDimension($letter);

            if (null === $field->getWidth()) {
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth($field->getWidth());
            }
        }

        $lastLetter = $letters[\count($letters) - 1];
        $xlsx = $this->options->xlsx;

        // Başlık stili TEK aralıkta uygulanır; kolon kolon uygulamak aynı stili kolon
        // sayısı kadar kez hash'letirdi.
        $header = $sheet->getStyle('A'.$labelRow.':'.$lastLetter.$labelRow);
        $header->getFont()->setBold($xlsx->boldHeader);
        $header->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB($xlsx->headerFill);
        $header->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()
            ->setARGB($xlsx->headerBorderColor);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Zorunlu kolonlar açık kırmızı — dışa aktarmayla AYNI kural (bkz. `XlsxWriter`).
        // Eski ERP'nin şablonlarındaki işe yarayan tek görsel ipucu buydu, korundu.
        foreach ($fields as $index => $field) {
            if (!$field->isRequired()) {
                continue;
            }

            $sheet->getStyle($letters[$index].$labelRow)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB($xlsx->requiredHeaderFill);
        }

        if (null !== $keyRow && $this->options->hideKeyRow) {
            // Satır GİZLENİR, silinmez: kullanıcı teknik anahtarları görmez ama dosya
            // onları taşımaya devam eder. Kimliğin dosyada durması, gidiş-dönüşün
            // çeviriden bağımsız olmasının tek sebebidir.
            $sheet->getRowDimension($keyRow)->setVisible(false);
        }
    }

    private function labelOf(Field $field, FormatContext $context): string
    {
        $label = $field->getLabel();

        if ($label instanceof Closure) {
            return (string) $label($context->locale);
        }

        // Etiket verilmemişse anahtarın kendisi başlık olur — kolon asla başlıksız kalmaz.
        return $context->trans($label ?? $field->getKey());
    }

    // ---------------------------------------------------------------- kolon biçimleri

    /**
     * Kolon başına sayı biçimi ve hizalama.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function applyColumnStyles(Worksheet $sheet, array $fields, array $letters): void
    {
        foreach ($fields as $index => $field) {
            $letter = $letters[$index];

            // ★ Aralık ZORUNLU olarak "B1:B1048576" biçiminde, yani 1. SATIRDAN başlar.
            // PhpSpreadsheet'in "tam kolon" tanıması `^[A-Z]+1:[A-Z]+1048576$` desenine
            // bağlıdır: eşleşirse yalnız kolonun varsayılan stili güncellenir, eşleşmezse
            // aralık HÜCRE aralığı sayılır ve aradaki bir milyon koordinat için gerçekten
            // hücre nesnesi YARATILIR. "B3:B1048576" yazmak (veri satırından başlatmak)
            // içgüdüsel olarak doğru görünür ve dosyayı tek satırda bellekte patlatır.
            //
            // Başlık hücreleri bundan etkilenmez: onların kendi stil indeksi var, kolon
            // varsayılanı yalnız stilsiz hücrelere uygulanır.
            $range = $letter.'1:'.$letter.AddressRange::MAX_ROW;

            $format = $this->numberFormatFor($field);

            if (null !== $format) {
                $sheet->getStyle($range)->getNumberFormat()->setFormatCode($format);
            }

            $sheet->getStyle($range)
                ->getAlignment()
                ->setHorizontal(self::horizontalOf($field->getAlign()));
        }
    }

    /**
     * Alanın Excel sayı biçimi kodu; biçim gerekmiyorsa null (General).
     *
     * Miktar kolonuna yazan kullanıcı üç ondalık görür, para kolonuna yazan iki — yani
     * şablon, dışa aktarmanın ürettiği dosyayla aynı görünür.
     */
    private function numberFormatFor(Field $field): ?string
    {
        $type = $field->getType();
        $numbers = $this->settings->numbers;

        return match (true) {
            // Metin kolonu AÇIKÇA metin biçimlidir: "0501" cari kodu ya da "12.05" seri
            // numarası Excel'de sayıya/tarihe dönüşmesin diye. Baştaki sıfırın yenmesi
            // eski ERP'nin en sık bildirilen içe aktarma hatasıydı.
            FieldType::String === $type => NumberFormat::FORMAT_TEXT,
            // Para simgesi BİLEREK yok: simge satırın para birimine bağlıdır
            // (`Field::currency()` bir kapanış olabilir) ve boş şablonda satır yoktur.
            // Yanlış bir simge basmaktansa çıplak sayı biçimi veriyoruz.
            $type->isNumeric() => $numbers->excelFormatCode(max(0, $field->getDecimals() ?? $numbers->digitsFor($type))),
            $type->isTemporal() => $this->settings->dates->excelFormatFor($type),
            // Enum/seçenek/boole kolonları General kalır: '@' verseydik Excel sayıya
            // benzeyen seçenek etiketlerinde "metin olarak saklanan sayı" uyarısı basardı.
            default => null,
        };
    }

    /**
     * Başlığın altında önceden biçimlendirilmiş boş satırlar oluşturur.
     *
     * ★ Burası kütüphanede BİLEREK boş hücre yaratan tek yerdir (`XlsxWriter::writeRow()`
     * tam tersini yapar). Stil bir HÜCRE aralığına uygulandığında PhpSpreadsheet aradaki
     * her koordinatı yaratır; şablonda bu istenen davranıştır — kullanıcı doldurulacak
     * ızgarayı görür — ve maliyet `sampleRows` × kolon ile sınırlıdır, satır sayısıyla
     * değil.
     *
     * @return int oluşturulan son satır; hiç oluşturulmadıysa 0
     */
    private function createSampleRows(Worksheet $sheet, string $lastLetter, int $firstDataRow): int
    {
        $rows = $this->options->sampleRows;

        if ($rows < 1) {
            return 0;
        }

        $lastRow = $firstDataRow + $rows - 1;

        $sheet->getStyle('A'.$firstDataRow.':'.$lastLetter.$lastRow)
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_HAIR)
            ->getColor()
            ->setARGB($this->options->xlsx->headerBorderColor);

        return $lastRow;
    }

    // ---------------------------------------------------------------- açılır listeler

    /**
     * Boole/enum/seçenek kolonlarına açılır liste (veri doğrulama) ekler.
     *
     * Aynı seçenek kümesini paylaşan kolonlar TEK aralığa bakar: küme md5 ile
     * tekilleştirilir. On enum kolonu aynı listeyi kullanıyorsa yardımcı sayfada tek
     * sütun oluşur — hem dosya küçülür hem de listeyi elle düzeltmek isteyen kullanıcı
     * tek yerde düzeltir.
     *
     * @param list<Field>  $fields
     * @param list<string> $letters
     */
    private function applyDropdowns(
        Spreadsheet $spreadsheet,
        Worksheet $sheet,
        array $fields,
        array $letters,
        int $firstDataRow,
        FormatContext $context,
    ): void {
        /** @var array<int, list<string>> $optionsByIndex */
        $optionsByIndex = [];

        foreach ($fields as $index => $field) {
            if (!$field->getType()->isEnumerable()) {
                continue;
            }

            $options = $this->optionsFor($field, $context);

            // Seçenekleri çalışma anında (satırdan) türeyen bir alan boş dönebilir; o zaman
            // liste konmaz. Boş bir açılır liste, hiç açılır liste olmamasından beterdir:
            // Excel hücreyi kilitler ve kullanıcı hiçbir şey yazamaz.
            if ([] !== $options) {
                $optionsByIndex[$index] = $options;
            }
        }

        if ([] === $optionsByIndex) {
            return;
        }

        $lists = $spreadsheet->createSheet();
        $lists->setTitle(self::LIST_SHEET_TITLE, false, true);
        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        /** @var array<string, string> $rangeByHash md5 => "_lists!\$A\$1:\$A\$5" */
        $rangeByHash = [];
        $nextColumn = 1;

        foreach ($optionsByIndex as $index => $options) {
            $hash = md5(implode("\0", $options));

            if (!isset($rangeByHash[$hash])) {
                $letter = Coordinate::stringFromColumnIndex($nextColumn);
                ++$nextColumn;

                foreach ($options as $row => $option) {
                    // AÇIK tip: "0501" ya da "2024" gibi bir seçenek etiketi sayıya
                    // çevrilseydi listedeki değer, hücreye yazılan metinle eşleşmezdi.
                    $lists->setCellValueExplicit($letter.($row + 1), $option, DataType::TYPE_STRING);
                }

                $rangeByHash[$hash] = sprintf(
                    '%s!$%s$1:$%s$%d',
                    self::LIST_SHEET_TITLE,
                    $letter,
                    $letter,
                    \count($options),
                );
            }

            $validation = new DataValidation();
            $validation->setType(DataValidation::TYPE_LIST);
            $validation->setErrorStyle(DataValidation::STYLE_STOP);
            // Boş bırakmaya İZİN VAR: zorunluluk denetimi içe aktarmanın işidir
            // (`RowError`e dönüşür). Excel'e yaptırsaydık kullanıcı, henüz doldurmadığı
            // bir satırda gezinirken bile uyarı kutusuyla karşılaşırdı.
            $validation->setAllowBlank(true);
            // ★ `setShowDropDown(true)` = ok GÖRÜNSÜN. OOXML'de `showDropDown` özniteliği
            // TERSİNE anlamlıdır ("listeyi gizle") ve PhpSpreadsheet bu tersliği yazarken
            // kendisi uygular; `false` verirsek doğrulama çalışır ama hücrede ok çıkmaz.
            $validation->setShowDropDown(true);
            // Hata METNİ verilmez: Excel kendi yerelleştirilmiş uyarısını gösterir.
            // Buraya kendi dizemizi koysaydık, kullanıcının Excel dili ne olursa olsun
            // uygulamanın diline (ya da çevrilmemiş bir anahtara) sabitlenirdi.
            $validation->setShowErrorMessage(true);
            $validation->setFormula1($rangeByHash[$hash]);

            // Aralık kolonun sonuna kadar uzanır. Veri doğrulama sqref'i hücre YARATMAZ —
            // sadece bir aralık dizesidir — bu yüzden burada tam kolon kullanmak bedava,
            // üstelik kullanıcı binlerce satır yapıştırsa da liste geçerli kalır.
            $sheet->setDataValidation(
                $letters[$index].$firstDataRow.':'.$letters[$index].AddressRange::MAX_ROW,
                $validation,
            );
        }
    }

    /**
     * Alanın açılır listede görünecek ÇEVRİLMİŞ seçenekleri.
     *
     * Değerler tek tek, dışa aktarmanın kullandığı biçimlendiriciden geçirilir. Yani
     * listedeki metin, aynı değeri dışa aktarsak hücrede ne yazacaksa odur; ayrışmaları
     * mümkün değildir. Aynı metne düşen iki seçenek tekilleştirilir — Excel'de tekrarlı
     * satır göstermek dışında, ayrıştırma tarafında da ayırt edilemez olurlardı.
     *
     * @return list<string>
     */
    private function optionsFor(Field $field, FormatContext $context): array
    {
        $formatter = $this->formatters->for($field->getType());

        /** @var list<mixed> $values */
        $values = match ($field->getType()) {
            // Boole seçenekleri TEK aileden: `TabulaSettings::$boolTrueKey`/`$boolFalseKey`.
            // (Eski ERP'de üç paralel "evet/hayır" çeviri ailesi vardı.)
            FieldType::Bool => [true, false],
            FieldType::Enum => self::enumCases($field),
            FieldType::Options => self::optionKeys($field),
            default => [],
        };

        $labels = [];

        foreach ($values as $value) {
            // ★ Şablonda SATIR YOKTUR: biçimlendiriciye `$row = null` gider. Alanın kendi
            // `format()` kapanışı ya da `options()` kapanışı satıra bakıyorsa null'a
            // hazırlıklı olmalıdır — dokümantasyonda geçen tek sözleşme budur.
            $text = $formatter->format($value, $field, null, $context)->text;

            if ('' === $text || \in_array($text, $labels, true)) {
                continue;
            }

            $labels[] = $text;
        }

        return $labels;
    }

    /**
     * Enum'un tüm durumları.
     *
     * @return list<UnitEnum>
     */
    private static function enumCases(Field $field): array
    {
        $enumClass = $field->getEnumClass();

        if (null === $enumClass || !enum_exists($enumClass)) {
            return [];
        }

        return array_values($enumClass::cases());
    }

    /**
     * Seçenek haritasının anahtarları.
     *
     * @return list<int|string>
     */
    private static function optionKeys(Field $field): array
    {
        $options = $field->getOptions();

        if ($options instanceof Closure) {
            // Kapanış null satırla çağrılır (bkz. `optionsFor()`); satıra bağlı listeler
            // burada boş dönerse o kolona açılır liste konmaz.
            $options = $options(null);
        }

        return \is_array($options) ? array_keys($options) : [];
    }

    // ---------------------------------------------------------------- iç

    /**
     * Şema başlığını Excel'in kabul edeceği bir sayfa adına indirger.
     *
     * `XlsxWriter::resolveTitle()` ile aynı kurallar; tekilleştirme yok, çünkü burada
     * yalnız iki sayfa var. Tek ek kural yardımcı sayfayla ÇAKIŞMAdır: şema başlığı
     * gerçekten "_lists" olsaydı `createSheet()` yinelenen ad yüzünden patlardı.
     */
    private function sheetTitle(Schema $schema, FormatContext $context): string
    {
        $title = $schema->getTitle();

        $resolved = match (true) {
            $title instanceof Closure => (string) $title($context->locale),
            null === $title => $schema->getName(),
            default => $context->trans($title),
        };

        $resolved = str_replace(self::INVALID_TITLE_CHARACTERS, '-', $resolved);

        // Kesme işareti başta/sonda olamaz: Excel sayfa adını formüllerde tek tırnakla
        // sarar ve kenardaki tırnak kendi kaçışıyla çakışır.
        $resolved = trim($resolved, " '");

        // Bayt değil KARAKTER: `substr()` "Ürün Grubu" gibi bir adı çok baytlı harfin
        // ortasından keser ve geçersiz UTF-8 üretirdi.
        if (mb_strlen($resolved) > self::TITLE_MAX_LENGTH) {
            $resolved = rtrim(mb_substr($resolved, 0, self::TITLE_MAX_LENGTH));
        }

        if ('' === $resolved || 0 === strcasecmp($resolved, self::LIST_SHEET_TITLE)) {
            return self::FALLBACK_TITLE;
        }

        return $resolved;
    }

    /**
     * Hedef yolun yazılabilir olduğunu İLK adımda doğrular.
     *
     * Xlsx'te dosya ancak en sonda yazıldığından, yazılamaz bir yol tüm sayfa kurulduktan
     * SONRA patlardı — `XlsxWriter::guardTarget()` ile aynı gerekçe, aynı kontroller.
     */
    private function guardTarget(string $path): void
    {
        if ('' === trim($path)) {
            throw ExportException::unwritableTarget($path, 'hedef yol boş');
        }

        if (is_file($path)) {
            if (!is_writable($path)) {
                throw ExportException::unwritableTarget($path, 'dosya var ve salt okunur');
            }

            return;
        }

        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizini yok', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizinine yazma izni yok', $directory));
        }
    }

    /** `Align`ı Excel'in yatay hizalama koduna çevirir. */
    private static function horizontalOf(Align $align): string
    {
        return match ($align) {
            Align::Right => Alignment::HORIZONTAL_RIGHT,
            Align::Center => Alignment::HORIZONTAL_CENTER,
            default => Alignment::HORIZONTAL_LEFT,
        };
    }
}
