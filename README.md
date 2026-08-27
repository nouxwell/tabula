# Tabula

**Tek şema, üç yön.** Tablo verisini tek bir şema tanımından Excel, CSV ve PDF'e yazan; aynı şemayla
içe aktaran ve şablon üreten PHP kütüphanesi.

Yazar: **Hüseyin Niyazi Balın**

---

## Neden

Bir kolonun ne olduğu tipik bir ERP'de dört ayrı yerde yaşar ve hiçbiri diğerini bilmez: tarayıcının
görünür tablo kolonları, controller içindeki elle yazılmış kolon dizileri, alan **adına** bakan küresel
bir ondalık haritası ve içe aktarma şablonlarının kendi şema sağlayıcısı. Sonuç: aynı kolon dört kez
tarif edilir, dışa aktarılan dosya geri içe aktarılamaz, çeviri değişince eski dosyalar bozulur.

Tabula bunu tersine çevirir: **bir kolonun tanımı yalnızca bir yerde yazılır.** Geri kalan her şey —
yazıcılar, biçimlendiriciler, sayfa stratejileri — o tanımı okuyan tüketicilerdir.

## Kurulum

```bash
composer require balin/tabula
```

PHP 8.5+ gerekir. PDF çıktısı için ayrıca `dompdf/dompdf` kurun (Faz 3).

## Hızlı başlangıç

```php
use Balin\Tabula\Tabula;
use Balin\Tabula\Format;
use Balin\Tabula\Schema\{Schema, Field, Priority};
use Balin\Tabula\Source\ArraySource;
use Balin\Tabula\Port\ArrayTranslator;
use Balin\Tabula\Settings\{TabulaSettings, NumberSettings};

$schema = Schema::make('customer')
    ->title('export.customer.title')
    ->fields(
        Field::string('code')->label('export.customer.code')->width(14)->required()
            ->priority(Priority::Always),
        Field::string('name')->label('export.customer.name'),
        Field::string('city')->label('export.customer.city')->from('address.city'),
        Field::quantity('stock')->label('export.customer.stock')->decimals(3),
        Field::money('balance')->label('export.customer.balance')
            ->currency(fn (array $row): string => $row['currencyCode']),
        Field::bool('isActive')->label('export.customer.active'),
        Field::date('createdAt')->label('export.customer.created')->priority(Priority::Optional),
    );

$tabula = new Tabula(
    new ArrayTranslator(['tr' => ['export.customer.code' => 'Kod', /* … */]]),
    new TabulaSettings(numbers: new NumberSettings(currencySymbols: ['TRY' => '₺'])),
);

$result = $tabula->export($schema)
    ->from(ArraySource::of($rows))
    ->locale('tr')
    ->to(Format::Xlsx)
    ->write('/tmp/musteriler.xlsx');

$result->path();  // /tmp/musteriler.xlsx
$result->rows;    // yazılan satır sayısı
```

## Alan tanımı

| Anahtar | Ne işe yarar |
| --- | --- |
| `key` | Kanonik alan adı. Dosyadaki gerçek kimlik; çeviri değişse de sabit kalır. |
| `label` | Çeviri anahtarı, düz metin ya da `fn(string $locale): string`. |
| `from` | Değerin kaynağı: dizi anahtarı, nokta yolu (`address.city`), DQL takma adı ya da kapanış. |
| `type` | `string · integer · decimal · money · quantity · bool · date · datetime · enum · options` |
| `decimals` | Basamak sayısı — **alanın kendinde**, küresel bir haritada değil. |
| `currency` | Sabit kod ya da `fn($row): string`. Simge ve konumu ayardan gelir. |
| `enumClass` | PHP enum sınıfı; değer otomatik olarak çeviri anahtarına dönüşür. |
| `options` | `options` tipi için seçenek kümesi (dizi ya da kapanış). |
| `width` | Kolon genişliği; verilmezse otomatik. |
| `align` | Verilmezse tipten türetilir: sayı sağa, boole/tarih ortaya, metin sola. |
| `required` | Şablonda başlığı işaretler, içe aktarmada boş geçilemez yapar. |
| `priority` | PDF kolon bütçesinde önem sırası: `Always` · `Normal` · `Optional`. |
| `only` | Alanı belirli çıktılara sınırlar. |
| `format` | Biçimlendirmeyi tümüyle devralan kapanış. |

## Veri kaynakları

Veriyi elinle verebilir ya da kütüphanenin sayfa sayfa çekmesini sağlayabilirsin. Şema tarafı ikisini
de aynı görür.

```php
use Balin\Tabula\Source\{ArraySource, IteratorSource, CallableSource, DoctrineSource};

ArraySource::of($rows);                      // elde hazır dizi
IteratorSource::of(fn () => $generator());   // sabit bellekte akış
CallableSource::of(                          // sunucu-taraflı sayfalama
    fn (int $page, int $limit) => $repo->fetchPaged($page, $limit),
    pageSize: 2000,
);
DoctrineSource::of($queryBuilder);           // tek sorgu, satır satır akış
DoctrineSource::of($queryBuilder)->chunk(2000);  // sayfa sayfa (ORDER BY şart)
```

Tüm kaynaklar tembeldir: satırlar tüketildikçe üretilir, tamamı belleğe alınmaz.

### DoctrineSource iki kip

**Akış (varsayılan)** tek sorgu açar ve `Query::toIterable()` ile satırları teker teker hidrasyona
sokar. Sayfalama aritmetiği hiç devreye girmediği için satır atlama/tekrar riski yoktur ve çağıranın
koyduğu `setFirstResult`/`setMaxResults` penceresi korunur.

**Parçalı** (`chunk(n)`) her sayfa için ayrı sorgu açar. İki katı kural vardır ve ikisi de sessiz veri
bozulmasını önlemek içindir:

- **ORDER BY zorunludur.** Sıralama olmadan `LIMIT/OFFSET` satır atlar ve tekrar eder.
- **Çağıranın `setMaxResults` penceresi varsa reddedilir.** Parçalı kip onu ezerdi; "en fazla 50
  satırlık önizleme" diye kurulmuş bir sorgu tüm tabloyu dışarı akıtırdı.

Sayfalama yalnızca **gerçekten boş** bir sayfada durur, kısa sayfada değil: `getResult()` hidrate
edilmiş sonuç döndürür ve birleştirmeli sorgularda tekrar eden kök satırlar tek nesneye indirgenir,
yani 2000 SQL satırı 900 köke inebilir. "Kısa sayfa = bitti" kuralı böyle bir durumda aktarımı
sessizce ilk sayfada bitirirdi.

> ⚠ `hydrateAs(AbstractQuery::HYDRATE_OBJECT)` kullanırsan belleği kendin yönet: varlıklar
> UnitOfWork'te yönetilmeye devam eder ve kütüphane senin nesnelerini altından çekmemek için
> kendiliğinden `detach()` çağırmaz.

## Çıktı biçimleri

| Yazıcı | Motor | Çok sayfa |
| --- | --- | --- |
| `Format::Xlsx` | PhpSpreadsheet | Gerçek sekmeler |
| `Format::Csv` | Yerel `fputcsv`, tam akış | Parça başına ayrı dosya |
| `Format::Pdf` | Dompdf + Twig | Faz 3 |

CSV varsayılan ayracı `;`'dir: Türkçe/Avrupa Excel'inde `,` **ondalık** ayracıdır ve virgülle ayrılmış
dosya sayıları iki kolona böler. Dosyalar UTF-8 BOM ile yazılır, aksi hâlde Excel Türkçe karakterleri bozar.

## Sayfa stratejileri

```php
use Balin\Tabula\Export\Sheet\{SingleSheet, ChunkedSheets, GroupedSheets};

->sheets(new SingleSheet('Müşteriler'))        // hepsi tek sayfada (varsayılan)
->sheets(new ChunkedSheets(50_000))            // her 50.000 satırda yeni sayfa
->sheets(new GroupedSheets('warehouseName'))   // alan değerine göre sayfa
```

> `GroupedSheets` satırları yeniden sıralamaz. Aynı grubun tek sayfada toplanması için veri kaynağı
> o alana göre **sıralı** gelmelidir.

## Çeviri

Çekirdek Symfony'yi tanımaz; `Translator` portu üzerinden konuşur.

```php
interface Translator {
    public function trans(string $key, array $params = [], ?string $locale = null): string;
}
```

Hazır uygulamalar: `ArrayTranslator` (düz ya da iç içe katalog) ve `PassthroughTranslator` (anahtarı
aynen döndürür). Symfony köprüsü bu portu framework translator'ına bağlar.

Locale **her çağrıda açıkça** taşınır — kuyruk işçisinde "istekten gelen dil" diye bir şey yoktur.

### Enum çevirisi

`EnumFormatter` bir enum değerinden çeviri anahtarını sırayla şöyle çıkarır:

1. `Balin\Tabula\Contract\TranslatableEnum::translationKey()`
2. Enum üzerinde `label(): string` metodu (mevcut ERP geleneği — 200'den fazla enum hiç değişmeden çalışır)
3. `BackedEnum::$value` ya da `UnitEnum::$name`

## Symfony ile kullanım

`config/bundles.php`:

```php
Balin\Tabula\Bridge\Symfony\TabulaBundle::class => ['all' => true],
```

`config/packages/tabula.yaml`:

```yaml
tabula:
    default_locale: '%kernel.default_locale%'
    empty_text: '-'
    translation:
        domains: ['messages', 'enum']       # sırayla denenir, ilk TANIMLI alan kazanır
        addressable_domains: ['validators'] # 'validators:key' önekiyle ayrıca erişilebilir
    numbers:
        currency_symbols:
            TRY: '₺'
```

Ardından `Balin\Tabula\Tabula` her yere enjekte edilebilir.

**Çeviri alanı meselesi.** ERP'de etiketler `messages`, enum karşılıkları `enum` alanındadır; ama
enum'lar anahtarı `label(): string` ile döndürür ve o anahtar alan bilgisi taşımaz. Köprü bunu
**alan zinciriyle** çözer: anahtarı hangi alanın TANIMLADIĞI kataloğa sorulur ve ilk tanımlayan
kazanır. Dönen dizeye bakıp "ıska mı?" diye tahmin etmek üç durumda yanlış sonuç verir — kendi
anahtarına eşit çeviriler (`IBAN`, `KDV`, ISO kodları), ICU alanları ve `|` içeren çoğul anahtarlar —
o yüzden `TranslatorBagInterface::defines()` kullanılır.

**Çevirmeni olmayan uygulama.** `symfony/translation` kurulu değilse ya da `framework.translator`
kapalıysa bundle otomatik olarak `PassthroughTranslator`a düşer; konteyner çökmez.

## Geliştirme

```bash
composer install
composer test     # PHPUnit
composer stan     # PHPStan
composer cs       # php-cs-fixer
```

## Yol haritası

| Faz | Kapsam | Durum |
| --- | --- | --- |
| 0 | Çekirdek şema, biçimlendiriciler, `ArraySource`, Xlsx + CSV yazıcıları | **bitti** |
| 1 | `DoctrineSource`, Symfony köprü bundle'ı | **bitti** |
| 2 | Şemanın sunucuya alınması (istemci yalnız anahtar gönderir) + yazıcı ayarlarının config'e açılması | sırada |
| 3 | PDF yazıcısı, sayfa boyutu (A3/A4/A5) ve kolon bütçesi | |
| 4 | İçe aktarma + şablon üretimi (anahtarla eşleşme, satır bazlı hata, kısmi kabul) | |

> Faz 2'ye taşınan bilinen eksik: `CsvWriter`'ın ayraç/BOM/satır sonu ayarları henüz
> `tabula.yaml` üzerinden verilemiyor; makineye giden bir besleme için hâlâ
> `->writer(new CsvWriter(delimiter: ','))` yazmak gerekiyor.

## Lisans

Proprietary.
