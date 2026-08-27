<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Value\Cell;
use PhpOffice\PhpSpreadsheet\Cell\AddressRange;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as SpreadsheetXlsxWriter;

/**
 * PhpSpreadsheet ile yazan .xlsx yazıcı.
 *
 * `CsvWriter`in aksine burada gerçek bir akış YOKTUR: xlsx bir ZIP arşividir, paylaşılan
 * dize tablosu ve stil tablosu ancak son satır bilindiğinde yazılabilir. Bu yüzden
 * PhpSpreadsheet çalışma kitabını bellekte tutar ve bunu değiştiremeyiz. Değiştirebildiğimiz
 * — ve mevcut ERP'nin kaçırdığı — üç şey var:
 *
 *  1. `close()` dosyayı DOĞRUDAN diske yazar. Eski motor `ob_start()` açıp writer'ı
 *     `php://output`a bastırıyor, `ob_get_clean()` ile tüm arşivi tek bir PHP dizesine
 *     alıyor ve o dizeyi dosyaya yazıyordu: çalışma kitabının yanında bir de dosya boyutu
 *     kadar ikinci kopya. Elli bin satırlık bir dışa aktarma bu yüzden `memory_limit`e
 *     takılıyordu.
 *  2. Boş hücre HİÇ yaratılmaz (bkz. `writeRow()`), stiller aralık üzerinden uygulanır
 *     (bkz. `applyColumnAlignment()`). İkisi de "kolay" yolun satır×kolon kadar nesne
 *     ürettiği yerler.
 *  3. Değerler tipli yazılır: sayı sayı, metin metin kalır. Eski motor her şeyi
 *     `setCellValue()` ile yazıyor, varsayılan bağlayıcı da "0501" gibi bir kodu 501'e,
 *     "12.05" gibi bir seri numarasını tarihe çeviriyordu.
 *
 * Yaşam döngüsü `Writer` arayüzündeki sırayla aynıdır; ihlaller `WriterException` fırlatır.
 */
final class XlsxWriter implements Writer
{
    private const string CREATOR = 'Tabula';

    /** Başlık satırının açık gri dolgusu. */
    private const string HEADER_FILL = 'FFF2F2F2';

    /** Zorunlu kolon başlığının açık kırmızı dolgusu. */
    private const string REQUIRED_HEADER_FILL = 'FFFCE4E4';

    /** Başlığın altındaki ince çizginin rengi; siyah çok sert, gri satırı ayırmaya yeter. */
    private const string HEADER_BORDER_COLOR = 'FFBFBFBF';

    /** Excel'in sayfa adı sınırı. */
    private const int TITLE_MAX_LENGTH = 31;

    /**
     * Excel'in sayfa adında yasakladığı karakterler.
     *
     * Liste PhpSpreadsheet'in `Worksheet::INVALID_CHARACTERS` sabitiyle birebir aynıdır;
     * oradaki sabit `private` olduğu için kopyalandı. Adı önden temizlemezsek
     * `setTitle()` doğrulaması istisna fırlatır ve dışa aktarma, kullanıcının hiç
     * kontrol edemediği bir veri değeri ("Satış/İade" gibi bir grup adı) yüzünden ölür.
     *
     * @var list<string>
     */
    private const array INVALID_TITLE_CHARACTERS = ['*', ':', '/', '\\', '?', '[', ']'];

    /** Adı tamamen yasak karakterlerden ibaret olan sayfalar için taban ad. */
    private const string FALLBACK_TITLE = 'Sayfa';

    private ?Spreadsheet $spreadsheet = null;

    /** Şu an yazılan sayfa; sayfa yokken null. */
    private ?Worksheet $sheet = null;

    /** `open()` ile verilen hedef yol. */
    private ?string $path = null;

    /** @var list<Column> aktif sayfanın kolonları */
    private array $columns = [];

    /**
     * Kolon harfleri, kolonlarla aynı sırada.
     *
     * Her hücrede yeniden hesaplamak yerine sayfa başında bir kez üretilir; koordinat
     * kurmak `$letters[$i].$row` kadar ucuza iner.
     *
     * @var list<string>
     */
    private array $letters = [];

    /** Son kolonun harfi — otomatik filtre ve başlık aralığı için. */
    private string $lastLetter = 'A';

    /** Kaçıncı sayfadayız; 0 ise hazır (varsayılan) sayfa henüz kullanılmadı. */
    private int $sheetIndex = 0;

    /**
     * Kullanılmış sayfa adları (küçük harfe katlanmış) — ad tekilleştirme için.
     *
     * @var array<string, true>
     */
    private array $usedTitles = [];

    /** Sıradaki veri satırı; başlık 1. satırda olduğu için veri 2'den başlar. */
    private int $nextRow = 2;

    /** @var list<string> yazılan dosya yolları */
    private array $paths = [];

    public function open(string $path): void
    {
        if (null !== $this->spreadsheet) {
            throw WriterException::alreadyOpen();
        }

        // Hedef daha İLK adımda denetlenir. Xlsx'te dosya ancak `close()`ta yazıldığından,
        // yazılamaz bir yol tüm satırlar işlendikten SONRA patlardı: kullanıcı dakikalarca
        // bekleyip "izin yok" hatası alırdı. Kontrol bir `is_dir()` kadar ucuz.
        $this->guardTarget($path);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator(self::CREATOR);

        // Varsayılan sayfa BURADA SİLİNMEZ. `new Spreadsheet()` "Worksheet" adıyla bir
        // sayfa yaratır; onu silip sonra `createSheet()` demek gereksiz nesne trafiği,
        // hiç dokunmamak ise her dosyanın başında boş bir sekme bırakmak olurdu.
        // Bunun yerine ilk `startSheet()` o sayfayı devralır (bkz. `sheetIndex`).
        $this->spreadsheet = $spreadsheet;
        $this->path = $path;
        $this->sheet = null;
        $this->sheetIndex = 0;
        $this->usedTitles = [];
        $this->paths = [];
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        $spreadsheet = $this->spreadsheet ?? throw WriterException::notOpened();

        // Önceki sayfa `finishSheet()` görmeden yenisi başlatıldıysa sessizce bitiririz —
        // `CsvWriter` ile aynı davranış. Fırlatmak yerine kapatmak, sayfa stratejisi
        // değiştiğinde boru hattının fazladan bir çağrı yapmak zorunda kalmamasını sağlar.
        $this->finalizeSheet();

        $sheet = 0 === $this->sheetIndex
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();

        // 2. argüman false: çıktı dosyasında formül yok, dolayısıyla yeniden adlandırmada
        // adlandırılmış formülleri taramanın anlamı da yok. 3. argüman (doğrulama) açık
        // bırakıldı; adı kendimiz temizlesek de kütüphanenin son savunmasını kapatmayız.
        $sheet->setTitle($this->resolveTitle($name), false, true);

        // Kütüphane adı yine de değiştirmiş olabilir (bizim harf katlamamızla kendi
        // karşılaştırması ayrışırsa kendi sonekini ekler); kayda GERÇEK adı yazarız,
        // yoksa sonraki sayfa aynı adı boşta sanır.
        $this->usedTitles[mb_strtolower($sheet->getTitle())] = true;

        ++$this->sheetIndex;

        $this->sheet = $sheet;
        $this->columns = array_values($columns);
        $this->letters = [];
        $this->nextRow = 2;

        $this->writeHeader($sheet);
    }

    /**
     * @param list<Cell> $cells kolonlarla aynı sırada
     */
    public function writeRow(array $cells): void
    {
        $sheet = $this->sheet ?? throw WriterException::noActiveSheet();

        $row = $this->nextRow;
        $index = 0;

        foreach ($cells as $cell) {
            // Kolon sayısından fazla hücre gelirse harfi anında türetiriz: boru hattı
            // hücreleri zaten kolon listesinden ürettiği için bu olmamalı, ama olursa
            // veriyi sessizce düşürmek en kötü davranış olurdu.
            $letter = $this->letters[$index] ?? Coordinate::stringFromColumnIndex($index + 1);
            ++$index;

            $value = $cell->value;

            if ($cell->isEmpty()) {
                // GERÇEKTEN boş hücre HİÇ yaratılmaz. PhpSpreadsheet her hücre için bir nesne
                // tutar; "boş yaz" ile "hiç yazma" Excel'de aynı görünür ama bellekte satır×kolon
                // kadar fark eder. Eski motor tam olarak boş hücreleri de yazıyordu.
                //
                // Ölçüt `null === $value` DEĞİL `isEmpty()`: `TabulaSettings::$emptyText` bir
                // yer tutucu ("-", "—") olarak ayarlandığında değer null ama METİN doludur ve
                // o yer tutucunun yazılması gerekir. Aşağıdaki `default` kolu bunu halleder.
                continue;
            }

            $coordinate = $letter.$row;

            // Hepsi AÇIK tiple yazılır. `setCellValue()` varsayılan bağlayıcıdan geçer ve
            // sayıya benzeyen dizeleri sayıya, tarihe benzeyenleri tarihe çevirir: baştaki
            // sıfırı yiyen cari kodlar ve tarihe dönen seri numaraları hep buradan çıkar.
            match (true) {
                is_string($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING),
                is_int($value), is_float($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_NUMERIC),
                is_bool($value) => $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_BOOL),
                // Beklenmedik bir tip (ör. DateTime, Stringable) geldiğinde tahmin yürütmeyiz:
                // hücrenin zaten yerelleştirilmiş `text` gösterimi var, metin olarak o yazılır.
                default => $sheet->setCellValueExplicit($coordinate, $cell->text, DataType::TYPE_STRING),
            };

            if (null !== $cell->numberFormat) {
                // Biçim kolon başına değil, HÜCRE başına uygulanır: `Field::currency()` bir
                // kapanış olabildiği için aynı kolonda satır satır farklı para birimi —
                // dolayısıyla farklı biçim kodu — çıkabilir. Maliyet göründüğü kadar ağır
                // değil; PhpSpreadsheet aynı stili hash'leyip tek bir kayıtta paylaştırır,
                // hücrede duran yalnızca bir tamsayı indeks olur.
                $sheet->getStyle($coordinate)
                    ->getNumberFormat()
                    ->setFormatCode($cell->numberFormat);
            }
        }

        ++$this->nextRow;
    }

    public function finishSheet(): void
    {
        if (null === $this->sheet) {
            throw WriterException::noActiveSheet();
        }

        $this->finalizeSheet();
    }

    /**
     * Çalışma kitabını diske yazar ve belleği bırakır.
     *
     * `CsvWriter::close()`un aksine BU METOT FIRLATABİLİR: xlsx'te tüm iş son anda
     * yapıldığı için buradaki bir hata "dosya hiç yazılamadı" demektir; sessizce yutulursa
     * çağıran var olmayan bir dosyayı kullanıcıya sunar.
     *
     * @return list<string> yazılan dosya yolları
     */
    public function close(): array
    {
        $spreadsheet = $this->spreadsheet;

        if (null === $spreadsheet) {
            // Zaten kapalı: art arda çağrılabilsin, `finally` içinde kullanılabilsin.
            return $this->paths;
        }

        // Açık kalan sayfa varsa önce bitiririz; yoksa otomatik filtre ve hizalama hiç
        // uygulanmadan dosya kapanır.
        $this->finalizeSheet();

        $path = $this->path ?? throw WriterException::notOpened();

        // `finally` içinde koşulsuz `unset()` edebilmek için baştan tanımlanır; `try`
        // içinde tanımlansaydı kurucu patladığında tanımsız değişken kalırdı.
        $writer = null;

        try {
            // DOĞRUDAN DİSKE. Ara tampon yok, `php://output` yok, dizeye alma yok.
            $writer = new SpreadsheetXlsxWriter($spreadsheet);
            $writer->save($path);
        } catch (SpreadsheetException $exception) {
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Worksheet ile Spreadsheet birbirini tutar; PHP'nin sayaç tabanlı çöp toplayıcısı
            // bu döngüyü kendi başına çözemez, bellek istek sonuna kadar elde kalır.
            // `disconnectWorksheets()` bağı koparır, `unset()` son referansı düşürür — aynı
            // süreçte peş peşe dışa aktarma yapan uzun ömürlü işçiler (messenger) için şart.
            $spreadsheet->disconnectWorksheets();
            unset($writer, $spreadsheet);

            $this->spreadsheet = null;
            $this->path = null;
            $this->sheet = null;
            $this->sheetIndex = 0;
            $this->usedTitles = [];
        }

        $this->paths = [$path];

        return $this->paths;
    }

    /** Hedef yolun yazılabilir olduğunu `open()` anında doğrular. */
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

        // `dirname()` göreli yollarda '.' döner; `is_dir('.')` de doğru cevabı verir.
        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizini yok', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizinine yazma izni yok', $directory));
        }
    }

    /** Başlık satırını yazar, kolon genişliklerini kurar ve başlığı dondurur. */
    private function writeHeader(Worksheet $sheet): void
    {
        if ([] === $this->columns) {
            // Boru hattı kolonsuz sayfa göndermez (`Schema::only()` boş seçimi zaten
            // reddeder); yine de burada patlamak yerine boş bir sayfa bırakmayı yeğliyoruz.
            $this->lastLetter = 'A';

            return;
        }

        foreach ($this->columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 1);
            $this->letters[] = $letter;
            $this->lastLetter = $letter; // döngü bitince son kolonun harfi elde kalır

            // Başlık da AÇIK tiple yazılır: "2024" ya da "12.05" gibi bir kolon adı
            // varsayılan bağlayıcı tarafından sayıya/tarihe çevrilirdi.
            $sheet->setCellValueExplicit($letter.'1', $column->label, DataType::TYPE_STRING);

            $dimension = $sheet->getColumnDimension($letter);

            if (null === $column->width) {
                // Otomatik genişlik kaydetme anında, yazı tipi metrikleriyle hesaplanır;
                // bedeli vardır ama şemada genişlik verilmemişse tek makul davranış budur.
                $dimension->setAutoSize(true);
            } else {
                $dimension->setWidth($column->width);
            }
        }

        // Başlık stili tek aralıkta uygulanır; kolon kolon uygulamak aynı stili
        // kolon sayısı kadar kez hash'letirdi.
        $header = $sheet->getStyle('A1:'.$this->lastLetter.'1');
        $header->getFont()->setBold(true);
        $header->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB(self::HEADER_FILL);
        $header->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()
            ->setARGB(self::HEADER_BORDER_COLOR);
        $header->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Zorunlu kolonlar açık kırmızı. Dosya bir içe aktarma şablonu olarak indirildiğinde
        // kullanıcı hangi sütunu boş bırakamayacağını başlığa bakarak görür; eski ERP'nin
        // şablonlarındaki işe yarayan tek görsel kural buydu, korundu.
        foreach ($this->columns as $index => $column) {
            if (!$column->required) {
                continue;
            }

            $sheet->getStyle($this->letters[$index].'1')
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB(self::REQUIRED_HEADER_FILL);
        }

        // "A2'yi dondur" = 1. satır sabit kalsın. Yüz binlik listelerde kaydırırken
        // başlığın kaybolmaması, kullanıcıdan gelen en eski isteklerden biriydi.
        $sheet->freezePane('A2');
    }

    /**
     * Sayfayı kapatır: satır sayısı ancak burada bilindiği için otomatik filtre ve
     * hizalama son adımda uygulanır.
     *
     * `finishSheet()`in aksine sayfa yoksa sessizce döner — `startSheet()` ve `close()`
     * bunu "ne olursa olsun toparla" niyetiyle çağırır.
     */
    private function finalizeSheet(): void
    {
        $sheet = $this->sheet;

        if (null === $sheet) {
            return;
        }

        if ([] !== $this->columns) {
            // Otomatik filtre başlık + veri aralığının TAMAMINI kapsamalı. Eski motor bunu
            // sabit bir aralıkla ("A1:Z1000") kuruyordu; bin satırdan uzun dışa aktarmalarda
            // son satırlar filtrenin dışında kalıyor, kullanıcı süzünce veri "kayboluyordu".
            $lastRow = $this->nextRow - 1; // veri hiç gelmediyse 1 (yalnız başlık)
            $sheet->setAutoFilter('A1:'.$this->lastLetter.$lastRow);

            $this->applyColumnAlignment($sheet);
        }

        $this->sheet = null;
        $this->columns = [];
        $this->letters = [];
        $this->lastLetter = 'A';
        $this->nextRow = 2;
    }

    /** Kolonların yatay hizalamasını `Column::$align` üzerinden uygular. */
    private function applyColumnAlignment(Worksheet $sheet): void
    {
        foreach ($this->columns as $index => $column) {
            $letter = $this->letters[$index];

            // Aralık BİLEREK tam kolon ("B1:B1048576"). PhpSpreadsheet stil uygularken
            // aralığın biçimine bakar:
            //  - hücre aralığı verilirse (ör. "B2:B50000") aradaki HER koordinat için
            //    `getCell()` çağırır ve olmayan hücreleri YARATIR — null değerleri hiç
            //    yazmayarak kazandığımız belleği tek satırda geri verirdik;
            //  - tam kolon biçiminde ise yalnız var olan hücreler gezilir, boşlar için
            //    kolonun kendi varsayılan stili (ColumnDimension.xfIndex) güncellenir.
            //
            // Uygulama yalnız 'horizontal' anahtarını birleştirdiği için başlığın kalınlığı,
            // dolgusu, çizgisi ve hücrelerin sayı biçimi olduğu gibi kalır.
            $sheet->getStyle($letter.'1:'.$letter.AddressRange::MAX_ROW)
                ->getAlignment()
                ->setHorizontal(self::horizontalOf($column->align));
        }
    }

    /**
     * Sayfa adını Excel'in kabul edeceği, o dosyada tekil bir başlığa indirger.
     *
     * Ad çoğu zaman VERİDEN gelir (`GroupedSheets` bir alanın değerini sayfa adı yapar),
     * yani kullanıcı "Satış/İade" ya da 31 karakterden uzun bir müşteri unvanı girdiği anda
     * dışa aktarma patlayabilir. Burada temizlemek, orada patlamamak demektir.
     */
    private function resolveTitle(string $name): string
    {
        $title = str_replace(self::INVALID_TITLE_CHARACTERS, '-', $name);

        // Kesme işareti başta ya da sonda olamaz: Excel sayfa adını formüllerde tek tırnakla
        // sarar ve kenardaki tırnak kendi kaçışıyla çakışır.
        $title = trim($title, " '");
        $title = $this->truncate($title, self::TITLE_MAX_LENGTH);

        if ('' === $title) {
            $title = self::FALLBACK_TITLE.' '.($this->sheetIndex + 1);
        }

        return $this->deduplicate($title);
    }

    /**
     * Aynı adı ikinci kez gören sayfaya " (2)", " (3)" … ekler.
     *
     * Tekilleştirme şart: Excel aynı adı taşıyan iki sayfası olan bir kitabı "onarılması
     * gereken dosya" sayar ve içeriği atarak açar. Sonek 31 karakterlik sınıra dahildir;
     * kırpılan taraf HER ZAMAN taban addır — sonek kırpılsaydı "(2)" ile "(3)" ayırt
     * edilemez hâle gelirdi.
     */
    private function deduplicate(string $title): string
    {
        if (!isset($this->usedTitles[mb_strtolower($title)])) {
            return $title;
        }

        for ($suffixIndex = 2;; ++$suffixIndex) {
            $suffix = ' ('.$suffixIndex.')';
            $candidate = $this->truncate($title, self::TITLE_MAX_LENGTH - mb_strlen($suffix)).$suffix;

            if (!isset($this->usedTitles[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }
    }

    /**
     * Adı en fazla `$length` KARAKTERE indirir.
     *
     * Bayt değil karakter: "Ürün Grubu" gibi bir adda `substr()` çok baytlı bir harfin
     * ortasından keserdi ve ortaya geçersiz UTF-8 çıkardı. PhpSpreadsheet de sınırı
     * `mb_strlen` ile ölçer; aynı birimi kullanmazsak doğrulamayla ayrışırız.
     */
    private function truncate(string $value, int $length): string
    {
        if ($length < 1) {
            return '';
        }

        return mb_strlen($value) > $length
            ? rtrim(mb_substr($value, 0, $length))
            : $value;
    }

    /**
     * `Align`ı Excel'in yatay hizalama koduna çevirir.
     *
     * `Column::$align` bu noktada çözülmüştür, yani `Auto` gelmez; `default` yine de
     * sola hizalar — hizalanamayan bir kolon yüzünden dışa aktarma durmaz.
     */
    private static function horizontalOf(Align $align): string
    {
        return match ($align) {
            Align::Right => Alignment::HORIZONTAL_RIGHT,
            Align::Center => Alignment::HORIZONTAL_CENTER,
            default => Alignment::HORIZONTAL_LEFT,
        };
    }
}
