<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\WriterException;

/**
 * CSV yazıcısının ayarları.
 *
 * Tek bir CSV varsayılanı yoktur; iki ayrı hedef kitle vardır ve istedikleri şey birbirine zıttır:
 *
 *  - İNSAN, dosyayı Türkçe Excel'de açar → `;` ayraç + UTF-8 BOM şart.
 *  - MAKİNE, dosyayı bir betikle okur → RFC 4180 (`,` ayraç, BOM yok, kaçış kapalı).
 *
 * Bu yüzden ayarlar adlandırılmış kuruculardan seçilir; çağıranın beş skaleri elle
 * doğru sırada dizmesi gerekmez.
 */
final readonly class CsvOptions
{
    /**
     * @param string $delimiter  Varsayılan ';' — Türkçe/Avrupa yerelinde Excel ondalık ayıracı
     *                           olarak ',' kullanır; virgüllü dosyada "1.234,56" iki sütuna bölünür.
     * @param string $escape     PHP'nin standart dışı kaçışı. '' verilirse kapanır ve çıktı
     *                           RFC 4180'e birebir uyar (PHP 9'da varsayılan da bu olacak).
     * @param bool   $writeBom   BOM olmadan Excel dosyayı cp1254 sanır ve ş/ğ/ı/İ/ö/ç/ü bozulur
     * @param string $lineEnding varsayılan CRLF: hem RFC 4180'in şart koştuğu hem de Windows
     *                           Excel'in sorunsuz açtığı satır sonu
     */
    public function __construct(
        public string $delimiter = ';',
        public string $enclosure = '"',
        public string $escape = '\\',
        public bool $writeBom = true,
        public string $lineEnding = "\r\n",
    ) {
        // KURULUM anında doğrula, yazma anında değil. `fputcsv()` çok baytlı bir ayraçta ham
        // `ValueError` fırlatır; o istisna `TabulaException` değildir ve dosya oluşturulduktan
        // SONRA patladığı için diskte yalnız BOM içeren bir kalıntı bırakır.
        // Ölçü `strlen` (BAYT), `mb_strlen` değil: `fputcsv()` tek bayt ister, yani 'ş' geçersizdir.
        if (1 !== \strlen($delimiter)) {
            throw WriterException::csvCharacterMustBeSingleByte('delimiter', $delimiter);
        }

        if (1 !== \strlen($enclosure)) {
            throw WriterException::csvCharacterMustBeSingleByte('enclosure', $enclosure);
        }

        // Kaçış BİLEREK boş olabilir: '' verilince PHP'nin standart dışı kaçışı kapanır.
        if ('' !== $escape && 1 !== \strlen($escape)) {
            throw WriterException::csvCharacterMustBeSingleByte('escape', $escape, emptyAllowed: true);
        }
    }

    /** Türkçe/Avrupa Excel'inde çift tıklayıp açmak için — varsayılan. */
    public static function excel(): self
    {
        return new self();
    }

    /** Makineye giden besleme: RFC 4180, BOM yok, kaçış kapalı. */
    public static function rfc4180(): self
    {
        return new self(
            delimiter: ',',
            enclosure: '"',
            escape: '',
            writeBom: false,
            lineEnding: "\r\n",
        );
    }
}
