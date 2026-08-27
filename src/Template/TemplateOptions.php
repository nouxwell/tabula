<?php

declare(strict_types=1);

namespace Balin\Tabula\Template;

use Balin\Tabula\Export\Writer\XlsxOptions;

/**
 * İçe aktarma şablonunun üretim ayarları.
 *
 * Görsel ayarlar (başlık rengi, zorunlu kolon dolgusu, dondurma, süzme) BİLEREK
 * `XlsxOptions` üzerinden gelir: şablon, dışa aktarılan dosyanın aynası olmalıdır.
 * İkisi ayrı ayarlarla üretilseydi kullanıcı "indirdiğim dosya ile doldurduğum şablon
 * neden farklı görünüyor" sorusunu sorardı — ve zorunlu kolonların kırmızı başlığı gibi
 * öğrenilmiş tek görsel ipucu iki yerde ayrı ayrı bakım isterdi.
 */
final readonly class TemplateOptions
{
    /**
     * @param bool        $includeKeyRow Kanonik anahtar satırı (1. satır) yazılsın mı.
     *                                   Kapatmak dosyayı ETİKETLE eşleşmeye mahkûm eder,
     *                                   yani çevirinin değişmesi şablonu bozar — eski
     *                                   ERP'nin ölümcül kusuru tam olarak buydu. Yalnızca
     *                                   şablonu başka bir sisteme yem olarak verirken kapatın.
     * @param bool        $hideKeyRow    Anahtar satırı Excel'de gizlensin mi. Gizli satır
     *                                   dosyada DURMAYA devam eder; kullanıcı teknik
     *                                   anahtarları görmez, içe aktarma yine de görür.
     * @param int         $sampleRows    Başlığın altında önceden biçimlendirilmiş kaç boş
     *                                   satır oluşturulsun. 0 (varsayılan) = hiç; sıfırın
     *                                   altındaki değerler de "hiç" sayılır.
     * @param XlsxOptions $xlsx          Başlık görünümü — dışa aktarmayla ORTAK ayar nesnesi
     */
    public function __construct(
        public bool $includeKeyRow = true,
        public bool $hideKeyRow = true,
        public int $sampleRows = 0,
        public XlsxOptions $xlsx = new XlsxOptions(),
    ) {
    }
}
