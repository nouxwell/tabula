<?php

declare(strict_types=1);

namespace Balin\Tabula\Import;

use ArrayIterator;
use Balin\Tabula\Exception\ImportException;
use Balin\Tabula\Exception\ParseException;
use Balin\Tabula\Import\Reader\ReaderRegistry;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Schema\Field;
use Balin\Tabula\Schema\Schema;
use Balin\Tabula\Settings\TabulaSettings;
use Balin\Tabula\Value\ParseContext;
use Balin\Tabula\Value\Parser\StringParser;
use Balin\Tabula\Value\ParserRegistry;
use Balin\Tabula\Value\ValueParser;
use Closure;
use Iterator;
use IteratorIterator;
use Traversable;

/**
 * Tek bir içe aktarmanın akıcı kurulumu ve çalıştırılması — `ExportBuilder`ın ters yönü.
 *
 * Kurulum değiştirilemezdir: her ayar yeni bir kopya döndürür, böylece aynı temel
 * yapılandırmadan birden çok çalıştırma türetilebilir (ör. önce kuru bir doğrulama
 * turu, sonra gerçek yazma turu):
 *
 *     $base = $tabula->import($schema)->from($path)->locale('tr');
 *
 *     $onizleme = $base->each(static fn (ImportedRow $row) => $satirlar[] = $row->toArray())->run();
 *     $sonuc    = $base->each(static fn (ImportedRow $row) => $repo->save($row))->run();
 *
 * ⚠ İŞLEM (TRANSACTION) YÖNETİMİ ÇAĞIRANIN İŞİDİR. Kütüphane satırı ayrıştırır ve geri
 * çağırıma verir; veritabanına yazmaz. Mevcut ERP'de içe aktarmanın tamamı TEK bir
 * Doctrine işlemine sarılıydı, yani 5.000 satırlık bir dosyada 37. satırdaki yazım hatası
 * diğer 4.999 satırı da geri alıyordu (bkz. `ErrorMode`).
 */
final class ImportBuilder
{
    private ?string $path = null;

    private ?string $locale = null;

    private MatchStrategy $strategy = MatchStrategy::Auto;

    private ErrorMode $errorMode = ErrorMode::Collect;

    /** @var (Closure(ImportedRow): void)|null */
    private ?Closure $handler = null;

    public function __construct(
        private readonly Schema $schema,
        private readonly Translator $translator,
        private readonly TabulaSettings $settings,
        private readonly ParserRegistry $parsers,
        private readonly ReaderRegistry $readers,
    ) {
    }

    // ---------------------------------------------------------------- kurulum

    /** Okunacak dosya. Tür UZANTIDAN seçilir (bkz. `ReaderRegistry`). */
    public function from(string $path): self
    {
        return $this->with(static function (self $b) use ($path): void {
            $b->path = $path;
        });
    }

    /**
     * Ayrıştırma dili.
     *
     * Dışa aktarmadaki gibi süs değil: "Evet" değerinin `true`ya, "Açık" değerinin
     * `Status::Open` enum'una dönmesi bu dile bağlıdır (bkz. `ParseContext`).
     */
    public function locale(string $locale): self
    {
        return $this->with(static function (self $b) use ($locale): void {
            $b->locale = $locale;
        });
    }

    public function matchBy(MatchStrategy $strategy): self
    {
        return $this->with(static function (self $b) use ($strategy): void {
            $b->strategy = $strategy;
        });
    }

    public function onError(ErrorMode $mode): self
    {
        return $this->with(static function (self $b) use ($mode): void {
            $b->errorMode = $mode;
        });
    }

    /**
     * Geçerli her satır için çağrılacak kapanış.
     *
     * Yalnız HATASIZ satırlar gelir; yarısı ayrıştırılmış bir satır asla verilmez.
     *
     * @param Closure(ImportedRow): void $fn
     */
    public function each(Closure $fn): self
    {
        return $this->with(static function (self $b) use ($fn): void {
            $b->handler = $fn;
        });
    }

    // ---------------------------------------------------------------- çalıştırma

    public function run(): ImportResult
    {
        $path = $this->path ?? throw ImportException::noSource();
        $handler = $this->handler ?? throw ImportException::noHandler();

        // Okunabilirlik BURADA denetlenir, kayıt defterinden ÖNCE: defter uzantıya bakar
        // ve var olmayan bir "liste.txt" için "tanınmayan dosya türü" derdi. Kullanıcının
        // gerçek sorunu dosyanın kayıp/izinsiz olması; mesaj onu söylemeli.
        if (!is_file($path) || !is_readable($path)) {
            throw ImportException::fileNotReadable($path);
        }

        $reader = $this->readers->for($path);

        $context = new ParseContext(
            locale: $this->locale ?? $this->settings->defaultLocale,
            translator: $this->translator,
            settings: $this->settings,
        );

        // Akış tek yönlüdür (okuyucular Generator döndürür): dosyayı ikinci kez dolaşmak
        // yok. Oysa hangi satırın VERİ olduğunu bilmek için önce ilk iki satırı görmek
        // gerekir — `foreach` öne bakamadığı için imleci elle ilerletiyoruz.
        $iterator = self::iterator($reader->rows($path));
        $iterator->rewind();

        if (!$iterator->valid()) {
            throw ImportException::emptyFile($path);
        }

        $firstRow = $iterator->current();
        $iterator->next();
        $secondRow = $iterator->valid() ? $iterator->current() : [];

        $map = HeaderMap::resolve($firstRow, $secondRow, $this->schema, $this->strategy, $context);

        // Ayrıştırıcılar TEK sefer, hiç satır okunmadan çözülür. `ParserRegistry::for()`
        // kayıtlı ayrıştırıcı bulamazsa `ParseException` fırlatır; bu bir YAPILANDIRMA
        // hatasıdır, satır hatası değil. Döngünün içinde çözseydik aynı eksiklik satır
        // sayısı kadar `RowError`e dönüşür ve kullanıcı "5.000 satırın hepsi bozuk"
        // raporuyla baş başa kalırdı.
        /** @var list<array{int, Field, ValueParser}> $columns */
        $columns = [];

        foreach ($map->fields as $index => $field) {
            $columns[] = [$index, $field, $this->parsers->for($field)];
        }

        $read = 0;
        $imported = 0;
        /** @var list<RowError> $errors */
        $errors = [];

        // İmleç 2. satırda duruyor; başlık satırlarını satır NUMARASINA göre eliyoruz,
        // sayarak değil. Numaralar okuyucudan geldiği gibi, yani kullanıcının Excel'de
        // gördüğü hâliyle kullanılır.
        for (; $iterator->valid(); $iterator->next()) {
            $number = $iterator->key();

            if ($number < $map->firstDataRow) {
                continue;
            }

            $cells = $iterator->current();

            // ★ Tamamen boş satır SESSİZCE atlanır ve `read` sayacına da girmez.
            // Excel dosyalarının sonunda takılı kalan boş satır kuraldır, istisna değil;
            // onu veri satırı saymak zorunlu alanı olan HER gerçek dosyayı "hatalı"
            // yapardı — hem de kullanıcının hiç dokunmadığı bir satır yüzünden.
            // `read`e saymamak da şart: aksi hâlde `rejected()` hiçbir `RowError`e
            // karşılık gelmeyen bir sayı döndürür ve rapor kendi kendisiyle çelişirdi.
            if (self::isEmptyRow($cells)) {
                continue;
            }

            ++$read;

            /** @var list<RowError> $rowErrors */
            $rowErrors = [];
            /** @var array<string, mixed> $values */
            $values = [];

            foreach ($columns as [$index, $field, $parser]) {
                $key = $field->getKey();
                // Kısa satır kolon KAYDIRMAZ: CSV'de sondaki hücreler hiç yazılmamış
                // olabilir, eksik hücre boş hücredir.
                $raw = $cells[$index] ?? null;

                try {
                    $value = $parser->parse($raw, $field, $context);
                } catch (ParseException $exception) {
                    // Ham değer hatanın yanında taşınır: "geçersiz değer" diyen bir mesaj
                    // kullanıcıya hangi hücreye bakacağını söylemez.
                    $rowErrors[] = RowError::forField($number, $key, $exception->getMessage(), StringParser::describe($raw));

                    continue;
                }

                // ★ ZORUNLULUK DENETİMİ BURADA YAŞAR. Ayrıştırıcı alanın zorunlu
                // olduğunu BİLMEZ ve bilmemeli: boş hücre onun için hata değil, "değer
                // yok"tur (bkz. `StringParser::isBlank()`). Zorunluluk şemanın bilgisidir
                // ve şemayı yalnız bu döngü görür.
                if (null === $value && $field->isRequired()) {
                    $rowErrors[] = RowError::forField($number, $key, ParseException::required($field)->getMessage());

                    continue;
                }

                $values[$key] = $value;
            }

            if ([] !== $rowErrors) {
                // ★ Satır BÜTÜN olarak reddedilir. Yarısı ayrıştırılmış bir satırı geri
                // çağırıma vermek, çağıranın yarım bir kaydı veritabanına yazması demekti.
                if (ErrorMode::FailFast === $this->errorMode) {
                    // Satırın TÜM hataları taşınır, yalnız ilk hücrenin hatası değil:
                    // aynı satırı dört kez düzeltip dört kez yüklemek kimsenin işine
                    // yaramaz. İstisnanın mesajında yine ilki görünür.
                    throw ImportException::stoppedAtFirstError($rowErrors);
                }

                foreach ($rowErrors as $error) {
                    $errors[] = $error;
                }

                continue;
            }

            // Geri çağırımın fırlattığı istisna YUTULMAZ: çağıranın veritabanı hatası bir
            // `RowError` değildir ve "4.812 satır aktarıldı" raporunun içinde kaybolmamalıdır.
            // (Okuyucuların `finally` blokları imleç yok edilirken dosyayı yine de kapatır.)
            $handler(new ImportedRow($number, $values));
            ++$imported;
        }

        return new ImportResult(
            read: $read,
            imported: $imported,
            errors: $errors,
            columns: $map->columns(),
            ignored: $map->ignored,
        );
    }

    // ---------------------------------------------------------------- iç

    /**
     * Okuyucunun verdiği `iterable`ı elle ilerletilebilir bir imlece çevirir.
     *
     * @param iterable<int, list<mixed>> $rows
     *
     * @return Iterator<int, list<mixed>>
     */
    private static function iterator(iterable $rows): Iterator
    {
        return match (true) {
            // Yerleşik okuyucular Generator döndürür, yani ilk dal. `rewind()` henüz
            // başlamamış bir Generator'da zararsızdır.
            $rows instanceof Iterator => $rows,
            $rows instanceof Traversable => new IteratorIterator($rows),
            default => new ArrayIterator($rows),
        };
    }

    /**
     * Satırın TAMAMI boş mu?
     *
     * Denetim eşleşen kolonlarla sınırlı DEĞİLDİR: eşleşmeyen bir sütununda veri olan
     * satırı "boş" sayıp atlamak sessiz veri kaybı olurdu. Böyle bir satır işlenir ve
     * zorunlu alanları boşsa kullanıcıya hata olarak görünür — sessizce yok olmaz.
     *
     * @param list<mixed> $cells
     */
    private static function isEmptyRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (!StringParser::isBlank($cell)) {
                return false;
            }
        }

        return true;
    }

    private function with(Closure $mutator): self
    {
        $clone = clone $this;
        $mutator($clone);

        return $clone;
    }
}
