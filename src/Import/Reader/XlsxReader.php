<?php

declare(strict_types=1);

namespace Balin\Tabula\Import\Reader;

use Balin\Tabula\Exception\ImportException;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Cell as SpreadsheetCell;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as SpreadsheetReaderException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\CellIterator;

/**
 * PhpSpreadsheet ile okuyan .xlsx/.xls okuyucu.
 *
 * `CsvReader`in aksine burada GERÇEK BİR AKIŞ YOKTUR ve olamaz: xlsx bir ZIP arşividir,
 * paylaşılan dize tablosu ayrı bir girdide durur ve satırlar ancak o tablo çözüldükten
 * sonra anlam kazanır. Kütüphane çalışma kitabını belleğe kurar; bunu biz seçmiyoruz.
 * Seçebildiğimiz — ve maliyeti belirgin biçimde düşüren — üç şey var:
 *
 *  1. `setReadDataOnly(true)`: stil, koşullu biçimlendirme, çizim, sayfa düzeni hiç
 *     okunmaz. İçe aktarmada bunların tamamı çöptür ve büyük bir şablonda dosyanın
 *     çözülme süresinin çoğunu yiyen kısımdır.
 *  2. `setReadEmptyCells(false)`: değeri boş olan hücre için nesne yaratılmaz. Kolon
 *     hizası bundan ETKİLENMEZ (bkz. `rows()`); yalnız bellek kazanılır.
 *  3. `disconnectWorksheets()`: Worksheet ile Spreadsheet birbirini tutar, PHP'nin
 *     sayaç tabanlı çöp toplayıcısı bu döngüyü kendi başına çözemez. Bağı koparmazsak
 *     art arda dosya işleyen bir kuyruk işçisi her dosyayı bellekte biriktirir.
 *
 * ★ Tarihler SERİ NUMARASI (float) olarak gelir. `setReadDataOnly(true)` sayı biçimlerini
 * okumadığı için "bu hücre tarih mi" sorusunun cevabı burada YOKTUR. Bu bilinçli bir
 * bölüşümdür: okuyucu ham veriyi taşır, tarihe çevirme kararını alanın TİPİNİ bilen
 * ayrıştırıcı verir (bkz. `Reader` arayüzünün sözleşmesi). Hücreyi burada dizeye
 * çevirseydik — eski ERP'nin yaptığı buydu — seri numarası "45296" gibi anlamsız bir
 * dizeye dönüşür ve tarih geri kazanılamazdı.
 */
final class XlsxReader implements Reader
{
    /**
     * Desteklenen uzantılar.
     *
     * Liste `ImportException::unsupportedFile()` mesajındaki listeyle bilerek birebir
     * aynıdır: kullanıcıya "şunlar desteklenir" deyip sessizce başka bir uzantıyı da
     * kabul etmek, hata mesajını yalancı yapar.
     *
     * @var list<string>
     */
    private const array EXTENSIONS = ['xlsx', 'xls'];

    public function supports(string $path): bool
    {
        return \in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }

    /**
     * @return Generator<int, list<mixed>> satır numarası (1 tabanlı) => hücre değerleri
     */
    public function rows(string $path): Generator
    {
        $spreadsheet = $this->load($path);

        try {
            // İLK sayfa okunur, "aktif" sayfa değil. Aktiflik kullanıcının dosyayı en son
            // hangi sekmede kaydettiğine bağlıdır; aynı şablonu iki kullanıcı doldurup
            // gönderdiğinde farklı sayfaların okunması sessiz ve tekrarlanamaz bir hata olurdu.
            // Şablonumuzun yardımcı "_lists" sayfası da her zaman ilkin ARDINDA durur.
            $sheet = $spreadsheet->getSheet(0);

            // Kolon sınırı SAYFA genelinden alınır, satır satır değil: en uzun satır kaç
            // kolonsa her satır o kadar hücreyle döner. Aksi hâlde kısa satırlar kısa
            // dizilere çevrilir ve içe aktarma tarafında kolon indeksleri kayardı.
            $highestColumn = $sheet->getHighestColumn();

            foreach ($sheet->getRowIterator() as $number => $row) {
                $cells = $row->getCellIterator('A', $highestColumn);

                // ★ BU SATIR BU SINIFTAKİ EN ÖNEMLİ AYRINTIDIR.
                // `true` olsaydı yalnız GERÇEKTEN VAR OLAN hücreler gezilirdi; boş bir hücre
                // atlanır, ondan sonraki her kolon bir sola kayar ve satırın tamamı yanlış
                // alanlara eşlenirdi — "Kod" kolonundaki değer "Ad" alanına yazılır, üstelik
                // hiçbir hata üretmeden. Boş hücre KOLON YERİNİ korumalıdır.
                $cells->setIterateOnlyExistingCells(false);

                // Var olmayan hücre için YENİ HÜCRE YARATMA, null döndür. Varsayılan
                // (IF_NOT_EXISTS_CREATE_NEW) davranışı okuma sırasında çalışma kitabını
                // satır×kolon kadar hücre nesnesiyle şişirir: salt okuma bir işlemde
                // belleğe yazmak.
                $cells->setIfNotExists(CellIterator::IF_NOT_EXISTS_RETURN_NULL);

                $values = [];

                // `foreach` yerine ELLE gezinme: `CellIterator` kendini
                // `Iterator<TKey, Cell>` diye tanıtır, oysa yukarıdaki ayar yüzünden
                // `current()` gerçekten null döndürebilir. Elle gezinince `?Cell` olan
                // GERÇEK dönüş tipini görürüz ve boş hücreyi null'a çevirebiliriz;
                // `foreach` ile yazıldığında statik çözümleyici null kontrolünü
                // "her zaman false" sayıp siler, çalışma anında ise hücre null gelir.
                $cells->rewind();

                while ($cells->valid()) {
                    $cell = $cells->current();
                    $values[] = null === $cell ? null : self::valueOf($cell);
                    $cells->next();
                }

                // Anahtar KULLANICININ GÖRDÜĞÜ satır numarasıdır; iterator zaten 1 tabanlı verir.
                yield $number => $values;
            }
        } finally {
            // `finally`: tüketici `foreach`ten `break` ile çıkarsa (ör. `ErrorMode::FailFast`)
            // generator yok edilirken burası yine çalışır ve çalışma kitabı serbest kalır.
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * Hücrenin değeri; formül ise SONUCU.
     *
     * Kullanıcı şablonu doldururken toplam/birleştirme formülü yazar ("=B2*C2", "=A2&' '&B2").
     * `getValue()` bu hücrede formülün METNİNİ döndürür ve içe aktarma veritabanına
     * "=B2*C2" dizesini yazar — eski ERP'de en sık görülen sessiz bozulmalardan biriydi.
     */
    private static function valueOf(SpreadsheetCell $cell): mixed
    {
        try {
            $value = $cell->getCalculatedValue();
        } catch (SpreadsheetException) {
            // Hesap motoru bu formülü çözemedi (desteklenmeyen fonksiyon, dış dosya
            // referansı, döngüsel başvuru). Tüm dosyayı reddetmek yerine ham değere
            // düşeriz; hücre ayrıştırıcıya gider ve gerekiyorsa ORADA satır hatası olur.
            $value = $cell->getValue();
        }

        // Taşan (spill) dizi formülleri dizi döndürebilir. Tek hücre okuyoruz, ilk skalere
        // ineriz; boş dizi null olur.
        while (\is_array($value)) {
            $values = array_values($value);
            $value = $values[0] ?? null;
        }

        return $value;
    }

    private function load(string $path): Spreadsheet
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (SpreadsheetReaderException) {
            // Uzantı doğru ama içerik tanınmıyor (ör. .xlsx diye kaydedilmiş bir HTML tablosu).
            throw ImportException::unsupportedFile($path);
        }

        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);

        try {
            $spreadsheet = $reader->load($path);
        } catch (SpreadsheetException) {
            // Bozuk arşiv, şifreli dosya, yarım indirilmiş yükleme…
            throw ImportException::fileNotReadable($path);
        }

        if ($spreadsheet->getSheetCount() < 1) {
            $spreadsheet->disconnectWorksheets();

            throw ImportException::emptyFile($path);
        }

        return $spreadsheet;
    }
}
