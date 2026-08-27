<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

use Balin\Tabula\Exception\ImportException;

/**
 * Ayrıştırılmış ve doğrulanmış tek satır — geri çağırıma verilen şey.
 *
 * Değerler ALAN ANAHTARIYLA erişilir ve tipleri şemadaki `FieldType`e uygundur:
 * `quantity` float, `bool` gerçek bool, `enum` enum ÖRNEĞİ, `date` DateTimeImmutable.
 * Yani çağıran taraf dize ayrıştırmakla uğraşmaz.
 */
final readonly class ImportedRow
{
    /**
     * @param int                  $row    Kullanıcının Excel'de gördüğü satır numarası
     * @param array<string, mixed> $values alan anahtarı => ayrıştırılmış değer
     */
    public function __construct(
        public int $row,
        private array $values,
    ) {
    }

    /** Bilinmeyen anahtar sessizce null dönmez: yazım hatası hemen görünmeli. */
    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->values)) {
            throw ImportException::unknownRowField($key, array_keys($this->values));
        }

        return $this->values[$key];
    }

    /** Alan dosyada hiç yoksa ya da boşsa varsayılana düş. */
    public function getOr(string $key, mixed $default): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->values;
    }
}
