<?php

declare(strict_types=1);

namespace Balin\Tabula\Settings;

/**
 * Kütüphanenin tüm yapılandırması tek yerde.
 *
 * Symfony köprüsü bunu `config/packages/tabula.yaml` üzerinden doldurur;
 * çerçevesiz kullanımda doğrudan kurulur.
 */
final readonly class TabulaSettings
{
    public function __construct(
        public NumberSettings $numbers = new NumberSettings(),
        public DateSettings $dates = new DateSettings(),
        public string $defaultLocale = 'en',
        /** Boole değerlerin çeviri anahtarları — TEK aile (ERP'de üç paralel aile vardı). */
        public string $boolTrueKey = 'tabula.bool.yes',
        public string $boolFalseKey = 'tabula.bool.no',
        /** Boş hücrede yazılacak metin. */
        public string $emptyText = '',
        /**
         * Bir sayfaya yazılacak azami satır.
         *
         * Varsayılan `SingleSheet` stratejisi bunu taşma koruması olarak kullanır: sayfa
         * dolunca `Ad (2)` diye devam eder. Varsayılan değer Excel'in gerçek tavanıdır
         * (1.048.576 satır, başlık dahil), yani normal bir dışa aktarımda hiç devreye girmez.
         * Açıkça bir `SheetStrategy` verildiğinde bu değer kullanılmaz — strateji kendi kuralını uygular.
         */
        public int $maxRowsPerSheet = 1_048_575,
    ) {
    }
}
