<?php

declare(strict_types=1);

namespace Balin\Tabula\Exception;

use Balin\Tabula\Format;
use RuntimeException;

/** Dışa aktarma akışı hatalı kurulduğunda fırlatılır. */
final class ExportException extends RuntimeException implements TabulaException
{
    public static function noSource(): self
    {
        return new self('Veri kaynağı verilmedi: önce ->from(...) çağırın.');
    }

    /**
     * PDF motoru kurulu değil.
     *
     * Dompdf kütüphanenin ZORUNLU bağımlılığı değildir (yalnız `suggest`): Excel/CSV için
     * dışa aktaran uygulamalar onlarca megabaytlık bir yazı tipi ağacını taşımak zorunda
     * kalmasın diye. Kontrol yazıcının KURULDUĞU anda yapılır; olmasaydı `Dompdf\Dompdf`
     * sınıfı ancak `close()` içinde aranır, yani elli bin satır işlendikten SONRA ham bir
     * `Error` fırlardı — hem geç hem de `TabulaException` olmadığı için çağıranın
     * `catch (TabulaException)` bloğunu ıskalayan bir hata.
     */
    public static function missingPdfEngine(): self
    {
        return new self('PDF çıktısı için Dompdf gerekli ama kurulu değil: composer require dompdf/dompdf');
    }

    /**
     * Sayfa/kolon bütçesi verildi ama bu biçimin yazıcısı kâğıt kavramını tanımıyor.
     *
     * SESSİZCE yok saymak bu fazın ortadan kaldırmak için var olduğu hatanın ta kendisidir:
     * mevcut ERP'de `setPaper()` çağrısı fiilen dekoratifti ve kimse A5 basıldığını fark
     * etmiyordu. Bir ayar ya uygulanır ya da yüksek sesle reddedilir.
     */
    public static function pageSettingsUnsupported(Format $format): self
    {
        return new self(sprintf(
            '->page()/->columns() verildi ama "%s" biçiminin yazıcısı sayfa geometrisini tanımıyor (PageAware değil). '
            .'Sayfa boyutu yalnız PDF için anlamlıdır; Xlsx ve CSV\'de kâğıt diye bir şey yoktur.',
            $format->value,
        ));
    }

    public static function noOutput(): self
    {
        return new self('Dışa aktarma hiç dosya üretmedi.');
    }

    public static function noColumns(string $schema, Format $format): self
    {
        return new self(sprintf(
            '"%s" şemasında "%s" biçimi için yazılacak kolon kalmadı — alanların hepsi Field::only() ile bu biçimin dışında bırakılmış olabilir.',
            $schema,
            $format->value,
        ));
    }

    public static function invalidPageSize(float $widthMm, float $heightMm): self
    {
        return new self(sprintf(
            'Sayfa ölçüleri en az 1 mm olmalı; %s × %s mm verildi.',
            $widthMm,
            $heightMm,
        ));
    }

    public static function negativeMargin(float $mm): self
    {
        return new self(sprintf('Kenar boşluğu negatif olamaz; %s mm verildi.', $mm));
    }

    public static function invalidMinColumnWidth(float $mm): self
    {
        return new self(sprintf('Asgari kolon genişliği pozitif olmalı; %s mm verildi.', $mm));
    }

    public static function invalidMaxColumns(int $columns): self
    {
        return new self(sprintf('Azami kolon sayısı en az 1 olmalı; %d verildi.', $columns));
    }

    /** Sayfa, tek bir okunabilir kolonu bile alamayacak kadar dar. */
    public static function pageTooNarrow(float $usableMm, float $minWidthMm): self
    {
        return new self(sprintf(
            'Kullanılabilir sayfa genişliği %s mm, asgari kolon genişliği ise %s mm — tek kolon bile sığmıyor. '
            .'Sayfayı büyütün (ör. A4 yerine A3), yatay çevirin, kenar boşluğunu azaltın ya da minWidth değerini düşürün.',
            $usableMm,
            $minWidthMm,
        ));
    }

    /**
     * `Priority::Always` kolonları tek başına sayfaya sığmıyor.
     *
     * Eleme yapmak sözü bozardı: hem `Overflow::Drop` hem `Priority` "Always asla düşmez"
     * diyor. Sessizce eksik bir belge basmaktansa duruyoruz.
     */
    public static function mandatoryColumnsExceedBudget(int $mandatory, int $capacity): self
    {
        return new self(sprintf(
            'Zorunlu (Priority::Always) kolon sayısı %d, sayfa bütçesi ise %d kolon — bunlar elenemez. '
            .'Sayfayı büyütün (ör. A4 yerine A3), yatay çevirin, minWidth değerini düşürün '
            .'ya da bazı alanların önceliğini Always olmaktan çıkarın.',
            $mandatory,
            $capacity,
        ));
    }

    /** Çapa kolonlar bütçenin tamamını yiyor; geriye veri kolonu kalmıyor. */
    public static function anchorsFillTheBudget(int $anchors, int $capacity): self
    {
        return new self(sprintf(
            'Çapa kolon sayısı (%d) sayfa bütçesini (%d kolon) dolduruyor, geriye veri kolonu kalmıyor. '
            .'Daha az çapa seçin ya da sayfayı genişletin.',
            $anchors,
            $capacity,
        ));
    }

    public static function unwritableTarget(string $path, string $reason): self
    {
        return new self(sprintf('"%s" hedefine yazılamıyor: %s', $path, $reason));
    }
}
