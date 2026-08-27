<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Value\Cell;

/**
 * Yerel PHP akışlarıyla (fopen/fputcsv) yazan CSV yazıcı.
 *
 * PhpSpreadsheet burada BİLEREK kullanılmadı. Mevcut ERP, sonunda tek bir düz metin
 * dosyası kaydedecek olmasına rağmen önce bellekte koca bir `Spreadsheet` nesnesi
 * kuruyor, tüm satırları hücre nesnesi olarak oraya dolduruyor ve en sonda `Csv`
 * writer'ıyla diske basıyordu: elli bin satırlık bir dışa aktarma yüzlerce megabayt
 * RAM ve saniyelerce CPU demekti. Burada satır, `writeRow()` çağrısının içinde doğrudan
 * dosya tanıtıcısına gider; bellekte duran tek şey o anki satırdır. Akış gerçekten akar.
 *
 * ÇOK SAYFA: CSV'nin sayfa kavramı yoktur. Bu yüzden her `startSheet()` YENİ BİR DOSYA
 * açar — ilk sayfa `open()`'a verilen yolun kendisidir, sonrakiler "<taban>-<ad>.csv"
 * olur. `close()` üretilen tüm yolları oluşturulma sırasıyla döndürür; bunları tek bir
 * arşivde toplamak (ya da kullanıcıya ayrı ayrı sunmak) çağıranın işidir.
 */
final class CsvWriter implements Writer
{
    /**
     * UTF-8 imzası (BOM).
     *
     * Excel, imzasız bir CSV'yi sistemin kod sayfasıyla açar — Türkçe Windows'ta cp1254 —
     * ve "ş ğ ı İ ö ç ü" harfleri daha ilk satırda bozulur. Dosyanın UTF-8 olduğunu
     * Excel'e söyleyen başka bir işaret yoktur; bu üç bayt pazarlık konusu değildir.
     * Sadece makine tarafından tüketilecek (ör. başka bir sisteme aktarılacak) dosyalarda
     * imza gereksiz kalabileceği için yapılandırılabilir bırakıldı.
     */
    private const string BOM = "\xEF\xBB\xBF";

    /** @var resource|null aktif sayfanın dosya tanıtıcısı; sayfa yokken null */
    private $handle;

    /** `open()` ile verilen yol; ilk sayfanın dosyası ve diğer adların türetildiği taban. */
    private ?string $basePath = null;

    /** Şu an yazılan dosyanın yolu — yazma hatasında mesajda geçsin diye tutulur. */
    private ?string $currentPath = null;

    private bool $opened = false;

    /** Kaçıncı sayfadayız; 0 ise henüz hiç sayfa açılmadı (ilk sayfa taban yolu kullanır). */
    private int $sheetIndex = 0;

    /** @var list<string> yazılan dosya yolları, oluşturulma sırasıyla */
    private array $paths = [];

    private readonly string $delimiter;

    private readonly string $enclosure;

    private readonly string $escape;

    private readonly bool $writeBom;

    private readonly string $lineEnding;

    /**
     * Ayarların GEREKÇELERİ (ayraç neden ';', BOM neden şart, kaçış neden kapatılabilir)
     * `CsvOptions` üzerinde durur.
     *
     * Beş skaleri çağrı yerinde doğru sırada dizmek hataya açıktır ve sessizce yanlış bir
     * dosya üretir; `CsvOptions::excel()` / `CsvOptions::rfc4180()` niyeti adıyla söyler.
     */
    public function __construct(CsvOptions $options = new CsvOptions())
    {
        $this->delimiter = $options->delimiter;
        $this->enclosure = $options->enclosure;
        $this->escape = $options->escape;
        $this->writeBom = $options->writeBom;
        $this->lineEnding = $options->lineEnding;
    }

    public function open(string $path): void
    {
        if ($this->opened) {
            throw WriterException::alreadyOpen();
        }

        // Dosya burada AÇILMAZ. CSV'de dosya sayfayla birlikte doğar; sayfa hiç
        // başlamazsa diskte boş bir kabuk bırakmanın da anlamı yoktur.
        $this->basePath = $path;
        $this->opened = true;
        $this->sheetIndex = 0;
        $this->paths = [];
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        if (!$this->opened) {
            throw WriterException::notOpened();
        }

        // Önceki sayfa `finishSheet()` görmeden yenisi başlatıldıysa sessizce kapatırız:
        // CSV'de sayfa = dosya olduğu için açık kalan tanıtıcı doğrudan sızıntıdır.
        $this->closeHandle();

        $path = 0 === $this->sheetIndex
            ? ($this->basePath ?? throw WriterException::notOpened())
            : $this->derivePath($name);

        ++$this->sheetIndex;
        $this->openFile($path);

        // Başlık satırı: `Column::$label` bu noktada çeviri anahtarı değil, çözülmüş metindir.
        $labels = [];
        foreach ($columns as $column) {
            $labels[] = $column->label;
        }

        $this->put($labels);
    }

    /**
     * @param list<Cell> $cells kolonlarla aynı sırada
     */
    public function writeRow(array $cells): void
    {
        if (null === $this->handle) {
            throw WriterException::noActiveSheet();
        }

        $fields = [];
        foreach ($cells as $cell) {
            // Excel'e yazılan tipli `value` DEĞİL, yerelleştirilmiş `text` yazılır:
            // CSV'de hücre tipi diye bir şey yoktur, dosyada ne varsa kullanıcı onu görür.
            // Ham float yazsaydık "1234.5" çıkar, Türkçe Excel bunu tarih ya da metin sanırdı.
            $fields[] = $cell->text;
        }

        $this->put($fields);
    }

    public function finishSheet(): void
    {
        if (null === $this->handle) {
            throw WriterException::noActiveSheet();
        }

        $this->closeHandle();
    }

    /**
     * @return list<string> yazılan dosya yolları
     */
    public function close(): array
    {
        // Bilerek FIRLATMAZ: çağıran bunu `finally` içinde kullanabilsin ve dışa aktarma
        // ortasında patlayan bir hata bile arkada açık tanıtıcı bırakmasın istiyoruz.
        $this->closeHandle();

        $this->opened = false;
        $this->basePath = null;
        $this->sheetIndex = 0;

        // `paths` sıfırlanmaz; `close()` art arda çağrılırsa aynı listeyi döndürsün.
        // Yeni bir `open()` listeyi zaten baştan kurar.
        return $this->paths;
    }

    /** Tanıtıcıyı kapatır (fclose tamponu da boşaltır) ve durumu sayfasız hâle getirir. */
    private function closeHandle(): void
    {
        if (null === $this->handle) {
            return;
        }

        fclose($this->handle);
        $this->handle = null;
        $this->currentPath = null;
    }

    private function openFile(string $path): void
    {
        // Uyarı metnini `error_get_last()` yerine kendi geçici işleyicimizle yakalıyoruz:
        // `@fopen` sonrası `error_get_last()` çağrının hiç uyarı üretmediği durumda ÖNCEKİ,
        // alakasız bir hatayı döndürüp mesajı yanıltıcı hâle getirebiliyor. Bu maliyet
        // dosya başına bir kez ödenir, satır başına değil.
        $reason = null;
        set_error_handler(static function (int $severity, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });

        try {
            // 'wb': ikili kip, çünkü satır sonunu biz belirliyoruz — Windows'ta metin kipi
            // "\r\n" dizimizi "\r\r\n" yapardı.
            $handle = fopen($path, 'wb');
        } finally {
            restore_error_handler();
        }

        if (false === $handle) {
            throw ExportException::unwritableTarget($path, $reason ?? 'dosya açılamadı');
        }

        $this->handle = $handle;
        $this->currentPath = $path;

        // Yol listeye dosya AÇILIR AÇILMAZ eklenir: akış ortasında hata alsak bile
        // çağıran yarım kalan dosyayı görüp temizleyebilsin.
        $this->paths[] = $path;

        if ($this->writeBom) {
            fwrite($handle, self::BOM);
        }
    }

    /**
     * Tek satırı diske yazar.
     *
     * `$escape` argümanı AÇIKÇA geçilir: PHP 8.4'ten beri bu parametrenin varsayılanına
     * güvenmek "deprecated" uyarısı üretir (standart dışı kaçış mekanizması PHP 9'da
     * varsayılan olarak kapatılacak). Değeri açıkça vermek — '\\' de olsa, '' de olsa —
     * uyarıyı susturur; bu yüzden PHP 8.5'te tek bir deprecation çıkmaz. Aynı şekilde
     * satır sonu da 6. argümanla verilir, yoksa fputcsv her satırı "\n" ile bitirir.
     *
     * Argüman sırası: $stream, $fields, $separator, $enclosure, $escape, $eol.
     *
     * @param list<string> $fields
     */
    private function put(array $fields): void
    {
        $handle = $this->handle ?? throw WriterException::noActiveSheet();

        // Burası satır başına çalışan sıcak yol: hata yakalama kurulumu bilerek yok,
        // tek bir dönüş değeri kontrolü var. Uyarının kendisini PHP zaten kendi
        // kanalına basar; biz yalnız akışı durdururuz.
        $written = fputcsv($handle, $fields, $this->delimiter, $this->enclosure, $this->escape, $this->lineEnding);

        if (false === $written) {
            // Disk doldu ya da akış koptu. Sessizce yutulursa kullanıcı eksik bir dosyayı
            // tam sanıp indirir — mevcut ERP'de tam olarak bu oluyordu.
            throw ExportException::unwritableTarget($this->currentPath ?? '(bilinmeyen)', 'satır yazılamadı (disk dolu ya da akış kapanmış olabilir)');
        }
    }

    /**
     * İkinci ve sonraki sayfalar için dosya yolu türetir.
     *
     * Uzantı taban yoldan alınır (yoksa 'csv'), böylece "/tmp/rapor.csv" tabanı için
     * ikinci sayfa "/tmp/rapor-sayfa-2.csv" olur. Dizin kısmına hiç dokunulmaz — yol
     * dizesi olduğu gibi kesilip eklenir; `dirname()` kullanmak "rapor.csv" gibi göreli
     * yolları "./rapor-...csv" hâline getirirdi.
     */
    private function derivePath(string $sheetName): string
    {
        $base = $this->basePath ?? throw WriterException::notOpened();

        $extension = pathinfo($base, PATHINFO_EXTENSION);
        $stem = '' === $extension ? $base : substr($base, 0, -(strlen($extension) + 1));
        $extension = '' === $extension ? 'csv' : $extension;

        $slug = $this->slugify($sheetName);

        if ('' === $slug) {
            // Ad tamamen sembolden ibaretse (ya da boşsa) sıraya düşeriz; dosya adı boş kalmamalı.
            $slug = 'sayfa-'.($this->sheetIndex + 1);
        }

        $path = $stem.'-'.$slug.'.'.$extension;

        // İki sayfa aynı ada sadeleşirse (ör. "Ürün A" ve "Ürün-A") ikincisi birincisinin
        // üzerine yazardı; sıra numarası ekleyerek çakışmayı kırıyoruz.
        if (in_array($path, $this->paths, true)) {
            $path = $stem.'-'.$slug.'-'.($this->sheetIndex + 1).'.'.$extension;
        }

        return $path;
    }

    /**
     * Sayfa adını dosya adında güvenle kullanılabilecek bir parçaya indirger.
     *
     * Türkçe harfler ASCII karşılıklarına çevrilir: dosya adı indirme başlığından
     * (Content-Disposition) zip girdisine kadar birçok yerden geçiyor ve bu katmanların
     * her biri UTF-8'i doğru taşımıyor — "Ürün Listesi.csv" kullanıcının diskine
     * "ÃœrÃ¼n Listesi.csv" olarak düşebiliyor. Adın kendisi (sayfa başlığı) zaten
     * dosyanın İÇİNDE değil, sadece adında sadeleşir.
     */
    private function slugify(string $name): string
    {
        $ascii = strtr($name, [
            'ş' => 's', 'Ş' => 's',
            'ğ' => 'g', 'Ğ' => 'g',
            'ı' => 'i', 'İ' => 'i',
            'ö' => 'o', 'Ö' => 'o',
            'ç' => 'c', 'Ç' => 'c',
            'ü' => 'u', 'Ü' => 'u',
        ]);

        $lower = strtolower($ascii);

        // Geriye kalan her şey (boşluk, nokta, eğik çizgi, çevrilemeyen unicode) tireye döner.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';

        return trim($slug, '-');
    }
}
