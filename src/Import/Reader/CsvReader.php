<?php

declare(strict_types=1);

namespace Balin\Tabula\Import\Reader;

use Balin\Tabula\Exception\ImportException;
use Generator;

/**
 * Yerel PHP akışlarıyla (fopen/fgetcsv) okuyan CSV okuyucu.
 *
 * PhpSpreadsheet'in `Csv` okuyucusu BİLEREK kullanılmadı — `CsvWriter`daki gerekçenin
 * aynısı ters yönde: o okuyucu düz metni önce bellekte koca bir `Spreadsheet` nesnesine
 * hücre hücre kurar, biz ise satırı okuduğumuz anda tüketiciye veririz. Bellekte duran
 * tek şey o anki satırdır.
 *
 * Dosya bizim ürettiğimiz şablon olmayabilir; bu yüzden iki şey SEZİLİR:
 *
 *  1. UTF-8 imzası (BOM). Kendi yazıcımız bunu bilerek yazar (Excel'in dosyayı cp1254
 *     sanmaması için). Atmazsak ilk satırın ilk hücresi görünmez üç baytla başlar ve
 *     "\u{FEFF}code" hiçbir şema anahtarıyla eşleşmez: dosya "hiçbir kolon tanınmadı"
 *     diye reddedilir, üstelik ekranda başlık gayet doğru görünür.
 *  2. Ayraç. Sabitlemek ölümcüldür: kendi yazıcımız ';' yazar (Türkçe Excel'de ',' ondalık
 *     ayıracıdır), ama kullanıcının başka bir sistemden aldığı dosya ',' olabilir. Yanlış
 *     ayraçla okunan bir satır TEK hücreye düşer ve dosya yine sessizce reddedilir.
 */
final class CsvReader implements Reader
{
    /** UTF-8 imzası — `CsvWriter::BOM` ile aynı üç bayt. */
    private const string BOM = "\xEF\xBB\xBF";

    private const string ENCLOSURE = '"';

    /**
     * PHP'nin standart dışı kaçışı KAPALI.
     *
     * Boş dize `fgetcsv()`ı RFC 4180'e oturtur: `""` ikilemesi doğru çözülür, ters bölü
     * ise sıradan bir karakter olarak okunur. Açık bırakılsaydı `"C:\yol\"` gibi bir hücre
     * kapanış tırnağını yutar ve satırın geri kalanı tek bir dev hücreye akardı.
     * Argümanın AÇIKÇA geçilmesi ayrıca şart: PHP 8.4'ten beri varsayılana güvenmek
     * "deprecated" uyarısı üretir ve test paketi `failOnDeprecation` ile çalışır.
     */
    private const string ESCAPE = '';

    public function supports(string $path): bool
    {
        return 'csv' === strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * @return Generator<int, list<mixed>> satır numarası (1 tabanlı) => hücre değerleri
     */
    public function rows(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            $firstLine = fgets($handle);

            if (false === $firstLine) {
                // Tamamen boş dosya. Burada `emptyFile` FIRLATMIYORUZ: okuyucunun işi satır
                // vermektir, "başlık satırı bile yok" kararı şemayı bilen içe aktarıcıya aittir.
                return;
            }

            $hasBom = str_starts_with($firstLine, self::BOM);
            $delimiter = self::sniffDelimiter($hasBom ? substr($firstLine, \strlen(self::BOM)) : $firstLine);

            // Başa dönülür; BOM varsa imzanın ARDINDAN başlanır. Böylece ilk satır da
            // diğerleriyle aynı `fgetcsv()` yolundan geçer — imzayı sonradan dizeden
            // kırpmak, ilk hücrenin tırnaklı olduğu dosyalarda yanlış yeri keserdi.
            rewind($handle);

            if ($hasBom) {
                fseek($handle, \strlen(self::BOM));
            }

            $number = 0;

            // `$length = null`: satır uzunluğu sınırsız. Sabit bir tampon vermek, uzun bir
            // adres/açıklama hücresinde satırı ortasından ikiye bölerdi.
            while (false !== ($fields = fgetcsv($handle, null, $delimiter, self::ENCLOSURE, self::ESCAPE))) {
                ++$number;

                // Boş satır `[null]` olarak gelir; olduğu gibi verilir. "Tamamen boş satır"
                // kararı da içe aktarıcıya aittir — burada atlasaydık satır numaraları
                // kullanıcının Excel'de gördüğü numaralardan kayardı.
                yield $number => $fields;
            }
        } finally {
            // Tüketici `foreach`ten `break` ile çıksa bile (ör. `ErrorMode::FailFast`)
            // generator yok edilirken burası çalışır ve tanıtıcı sızmaz.
            fclose($handle);
        }
    }

    /**
     * İlk satırdaki ayracı ';' ve ',' arasından seçer.
     *
     * Sayım TIRNAK DIŞINDA yapılır: "Ankara, Çankaya" gibi tırnaklı tek bir hücre, aksi
     * hâlde dosyayı virgüllü sanmamıza yeter ve tüm sütun eşlemesi bozulurdu.
     * Tarama bayt bayttır; ';' ',' ve '"' ASCII olduğu için çok baytlı UTF-8 karakterlerin
     * devam baytlarıyla (>= 0x80) çakışmaz.
     *
     * Beraberlikte — sıfır–sıfır dahil — ';' kazanır: kendi yazdığımız dosyaların ayracı
     * budur, yani tek kolonluk bir dosyada da doğru tarafa düşeriz.
     */
    private static function sniffDelimiter(string $line): string
    {
        $semicolons = 0;
        $commas = 0;
        $inQuotes = false;
        $length = \strlen($line);

        for ($index = 0; $index < $length; ++$index) {
            $character = $line[$index];

            if (self::ENCLOSURE === $character) {
                // İkilenmiş tırnak ("") iki kez döner, yani durum değişmez — ayrı bir kural gerekmez.
                $inQuotes = !$inQuotes;

                continue;
            }

            if ($inQuotes) {
                continue;
            }

            if (';' === $character) {
                ++$semicolons;
            } elseif (',' === $character) {
                ++$commas;
            }
        }

        return $commas > $semicolons ? ',' : ';';
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        // 'rb': ikili kip. Metin kipi Windows'ta satır sonlarını dönüştürür ve tırnak
        // içindeki gerçek bir "\r\n" ile satır sonunu ayırt edemez hâle gelirdik.
        // `@`: yukarıdaki kontrolle aramızda yarış olabilir (dosya silinebilir, izin
        // değişebilir); uyarıyı bastırıp aynı istisnaya çeviriyoruz.
        $handle = @fopen($path, 'rb');

        if (false === $handle) {
            throw ImportException::fileNotReadable($path);
        }

        return $handle;
    }
}
