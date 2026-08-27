<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Sheet;

use BackedEnum;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Value\FormatContext;
use Balin\Tabula\Value\ValueResolver;
use Closure;
use UnitEnum;

/**
 * Alan değerine göre sayfa — her depo/kategori kendi sekmesinde.
 *
 * DİKKAT: strateji satırları yeniden sıralamaz. Aynı grubun tek sayfada toplanması için
 * veri kaynağı o alana göre SIRALI gelmelidir; aksi hâlde aynı ad birden çok kez açılır.
 *
 * Değer okuma işi `ValueResolver`e devredilir — burada ikinci bir yol yürüyüşü YAZILMAZ.
 * (Elle yazılmış bir kopya, çözümleyicinin "önce düz anahtarı dene" adımını kaçırdığı için
 * `['c.code' => 'X']` gibi Doctrine projeksiyon satırlarında sessizce yedek ada düşüyordu.)
 */
final readonly class GroupedSheets implements SheetStrategy
{
    private ValueResolver $resolver;

    /** Yol tabanlı okumayı çözümleyiciye taşıyan taşıyıcı alan; kapanış verildiyse null. */
    private ?Field $field;

    /**
     * @param string|Closure(mixed): mixed $by alan anahtarı/nokta yolu ya da satırdan değer üreten kapanış
     */
    public function __construct(
        private string|Closure $by,
        private string $fallbackName = 'Diğer',
    ) {
        $this->resolver = new ValueResolver();
        $this->field = $by instanceof Closure ? null : Field::string('__sheet')->from($by);
    }

    public function sheetFor(int $rowIndex, mixed $row, FormatContext $context): string
    {
        if (null === $this->field) {
            /** @var Closure $by kapanış verildiğinde taşıyıcı alan kurulmaz */
            $by = $this->by;

            return $this->toSheetName($by($row));
        }

        return $this->toSheetName($this->resolver->resolve($this->field, $row));
    }

    private function toSheetName(mixed $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        // `false` ÖZEL DURUM: `(string) false` boş dizedir ve boş ad yedek ada düşer.
        // O zaman "hayır" grubu ile "değeri olmayan" grubu AYNI sayfada birleşirdi — veri kaybı.
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (null === $value || !is_scalar($value)) {
            return $this->fallbackName;
        }

        $name = (string) $value;

        return '' === trim($name) ? $this->fallbackName : $name;
    }
}
