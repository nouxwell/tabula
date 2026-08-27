<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

/** Tamamlanmış bir içe aktarmanın sonucu. */
final readonly class ImportResult
{
    /**
     * @param int            $read     dosyada okunan veri satırı sayısı (başlıklar hariç)
     * @param int            $imported geri çağırıma başarıyla verilen satır sayısı
     * @param list<RowError> $errors   satır/alan bazlı hatalar
     * @param list<string>   $columns  dosyada tanınan alan anahtarları, dosyadaki sırayla
     * @param list<string>   $ignored  şemada karşılığı olmayan, yok sayılan başlıklar
     */
    public function __construct(
        public int $read,
        public int $imported,
        public array $errors,
        public array $columns,
        public array $ignored = [],
    ) {
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    public function isCompletelySuccessful(): bool
    {
        return [] === $this->errors && $this->read === $this->imported;
    }

    /** Reddedilen satır sayısı. */
    public function rejected(): int
    {
        return $this->read - $this->imported;
    }

    /**
     * Hataları satır numarasına göre gruplar — kullanıcıya "37. satırda şunlar var"
     * diye göstermenin en doğal yolu.
     *
     * @return array<int, list<RowError>>
     */
    public function errorsByRow(): array
    {
        $grouped = [];

        foreach ($this->errors as $error) {
            $grouped[$error->row][] = $error;
        }

        ksort($grouped);

        return $grouped;
    }
}
