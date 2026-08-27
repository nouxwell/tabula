<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Port\Translator;
use Balin\Tabula\Settings\TabulaSettings;

/**
 * Ayrıştırıcıların ihtiyaç duyduğu bağlam — `FormatContext`in ters yöndeki karşılığı.
 *
 * Çevirmen burada da gereklidir çünkü ayrıştırma çoğu zaman ÇEVİRİYİ TERSİNE ÇEVİRMEKTİR:
 * kullanıcının hücreye yazdığı "Evet" değerinin `true`ya, "Açık" değerinin
 * `Status::Open` enum'una dönmesi gerekir. Bu yüzden dil, biçimlendirmede olduğu gibi
 * açıkça taşınır.
 *
 * `FormatContext`ten ayrı bir sınıf olmasının sebebi `format` alanıdır: hangi biçime
 * YAZDIĞIMIZ ayrıştırma sırasında anlamsızdır ve orada durması yanlış soruları davet ederdi.
 */
final readonly class ParseContext
{
    public function __construct(
        public string $locale,
        public Translator $translator,
        public TabulaSettings $settings,
    ) {
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, $this->locale);
    }
}
