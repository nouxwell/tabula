<?php

declare(strict_types=1);

namespace Balin\Tabula\Import\Reader;

/**
 * Dosyadan ham satır okur.
 *
 * Hücreler DİZEYE ÇEVRİLMEZ, olduğu gibi verilir: Excel bir tarihi seri numarası (float),
 * bir miktarı gerçek sayı olarak döndürür. Hepsini dizeye çevirmek bu bilgiyi yok eder ve
 * ayrıştırıcıyı "45296" gibi bir dizeden tarihi geri çıkarmak zorunda bırakırdı.
 * Ayrıştırıcılar hem yerel tipi hem dizeyi kabul edecek şekilde hoşgörülüdür.
 *
 * Satır numarası KULLANICININ GÖRDÜĞÜ numaradır (1 tabanlı) — hata mesajı "37. satır"
 * dediğinde kullanıcı Excel'de 37. satıra bakabilmelidir.
 */
interface Reader
{
    public function supports(string $path): bool;

    /**
     * @return iterable<int, list<mixed>> satır numarası (1 tabanlı) => hücre değerleri
     */
    public function rows(string $path): iterable;
}
