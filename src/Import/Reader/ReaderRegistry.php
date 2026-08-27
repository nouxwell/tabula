<?php

declare(strict_types=1);

namespace Balin\Tabula\Import\Reader;

use Balin\Tabula\Exception\ImportException;

/**
 * Dosyadan okuyucuya eşleme.
 *
 * Seçim UZANTIYA göredir, içeriğe göre değil: kullanıcının yüklediği dosyanın ilk
 * baytlarını koklamak (sihirli sayı) daha "akıllı" görünür ama pratikte yanlış soruyu
 * çözer — bir kullanıcı .xlsx uzantılı bir CSV gönderdiğinde ona "bu dosya CSV, uzantısını
 * düzeltin" demek, dosyayı sessizce kabul edip sonra "hiçbir kolon eşleşmedi" demekten
 * çok daha yararlıdır.
 *
 * Öndeki okuyucu kazanır: `with()` ile eklenen özel bir okuyucu yerleşiği ezer
 * (bkz. `FormatterRegistry` — aynı desen, aynı gerekçe).
 */
final class ReaderRegistry
{
    /** @var list<Reader> */
    private array $readers;

    public function __construct(Reader ...$readers)
    {
        $this->readers = array_values($readers);
    }

    /** Yerleşik okuyucularla kurulu kayıt defteri. */
    public static function default(): self
    {
        return new self(new XlsxReader(), new CsvReader());
    }

    /** Özel okuyucuları BAŞA ekleyerek yerleşikleri ezer. */
    public function with(Reader ...$readers): self
    {
        return new self(...array_values($readers), ...$this->readers);
    }

    /**
     * Bu yolu okuyabilecek ilk okuyucu.
     *
     * @throws ImportException uzantıyı tanıyan okuyucu yoksa
     */
    public function for(string $path): Reader
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($path)) {
                return $reader;
            }
        }

        throw ImportException::unsupportedFile($path);
    }

    /** Bu yol için bir okuyucu var mı — istisna fırlatmadan sormak isteyenler için. */
    public function supports(string $path): bool
    {
        foreach ($this->readers as $reader) {
            if ($reader->supports($path)) {
                return true;
            }
        }

        return false;
    }
}
