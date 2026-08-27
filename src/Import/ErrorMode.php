<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/**
 * Bozuk satırla karşılaşınca ne yapılacağı.
 *
 * Mevcut ERP'de seçenek YOKTU: içe aktarmanın tamamı TEK bir Doctrine işlemine sarılıydı,
 * yani 5.000 satırlık bir dosyada 37. satırdaki bir yazım hatası diğer 4.999 satırı da geri
 * alıyordu. Kullanıcı hatayı düzeltip her şeyi baştan yüklüyordu.
 */
enum ErrorMode: string
{
    /**
     * Varsayılan: hataları TOPLA, geçerli satırları işle.
     *
     * Sonuçta kullanıcı "4.812 satır aktarıldı, şu 3 satır şu yüzden alınmadı" görür.
     * ⚠ İşlem (transaction) yönetimi ÇAĞIRANIN sorumluluğundadır: kütüphane satırları
     * geri çağırıma verir, veritabanına yazmaz.
     */
    case Collect = 'collect';

    /** İlk hatada dur. "Ya hep ya hiç" gereken akışlar için. */
    case FailFast = 'fail_fast';
}
