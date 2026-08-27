<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\WriterException;

/**
 * Excel yazıcısının görünüm ayarları.
 *
 * Renkler PhpSpreadsheet'in beklediği ARGB biçimindedir (`FFRRGGBB`) — baştaki iki hane
 * saydamlıktır ve atlanırsa renk sessizce yanlış çıkar.
 */
final readonly class XlsxOptions
{
    /**
     * @param string $creator            Dosya özelliklerine yazılan üretici adı
     * @param string $headerFill         Başlık satırının dolgusu (ARGB)
     * @param string $requiredHeaderFill Zorunlu kolon başlığının dolgusu (ARGB)
     * @param string $headerBorderColor  Başlığın altındaki ince çizgi (ARGB)
     * @param bool   $boldHeader         Başlık kalın yazılsın mı
     * @param bool   $freezeHeader       Başlık satırı kaydırırken sabit kalsın mı
     * @param bool   $autoFilter         Başlığa süzme okları eklensin mi
     */
    public function __construct(
        public string $creator = 'Tabula',
        public string $headerFill = 'FFF2F2F2',
        public string $requiredHeaderFill = 'FFFCE4E4',
        public string $headerBorderColor = 'FFBFBFBF',
        public bool $boldHeader = true,
        public bool $freezeHeader = true,
        public bool $autoFilter = true,
    ) {
        // PhpSpreadsheet ayrıştıramadığı rengi sessizce yutar; hata ancak dosya açılınca
        // görülür. Kurulum anında reddetmek, "ayar çalışmıyor" ya da simsiyah başlık bandı
        // olarak dönen bir hata raporundan çok daha ucuzdur.
        foreach ([
            'header_fill' => $headerFill,
            'required_header_fill' => $requiredHeaderFill,
            'header_border_color' => $headerBorderColor,
        ] as $setting => $color) {
            if (1 !== preg_match('/^(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $color)) {
                throw WriterException::invalidArgbColor($setting, $color);
            }
        }
    }

    /**
     * Süslemesiz çıktı — başka bir sistemin okuyacağı ara dosyalar için.
     *
     * Dondurulmuş satır ve süzme okları makineyi rahatsız etmez ama gereksiz XML üretir;
     * çok sayfalı büyük çıktıda dosya boyutunu ölçülebilir biçimde şişirir.
     */
    public static function plain(): self
    {
        return new self(
            boldHeader: false,
            freezeHeader: false,
            autoFilter: false,
        );
    }
}
