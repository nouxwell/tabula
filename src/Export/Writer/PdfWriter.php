<?php

declare(strict_types=1);

namespace Balin\Tabula\Export\Writer;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Exception\WriterException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Value\Cell;
use Dompdf\Dompdf;
use Dompdf\Exception as DompdfException;
use Dompdf\Options;

/**
 * Dompdf ile yazan PDF yazıcı.
 *
 * PDF'te iki şey xlsx ve CSV'den temelden farklıdır:
 *
 *  1. KÂĞIDIN ENİ VARDIR. Kolonlar sığmıyorsa bir yerde kesilmeleri gerekir; bu kararı
 *     `ColumnBudget::split()` verir ve burada TEK BİR KEZ, `startSheet()` içinde çağrılır.
 *     Dönen her grup bir "sayfa takımı"dır: önce tüm satırlar 1. grubun kolonlarıyla basılır,
 *     sonra aynı satırlar 2. grubun kolonlarıyla baştan basılır.
 *  2. ÇIKTI SON ANDA ÜRETİLİR. Dompdf HTML'i bir bütün olarak alır; satır satır akıtılamaz.
 *
 * (2) yüzünden satırlar biriktirilmek zorunda. Biriken şey `Cell` NESNESİ DEĞİL, hazır
 * `<tr>` HTML DİZESİDİR: aynı O(satır) büyüme, ama nesne başına ~200 bayt yerine yalnız
 * metnin kendisi. Daha önemlisi bu, kaynağın TEK GEÇİŞTE okunmasını sağlar. Grup başına
 * kaynağı yeniden sorgulamak — ki "ikinci geçiş" tam olarak bu demektir — iki sorgu
 * arasında değişen bir veri yüzünden 1. grubu 2. gruptan farklı satırlar içeren, yani
 * KENDİ İÇİNDE ÇELİŞEN bir PDF üretirdi. Mevcut ERP'nin çok kolonlu raporlarında tam olarak
 * bu vardı ve "PDF'te tutar farklı çıkıyor" şikâyetinin kaynağıydı.
 *
 * Yaşam döngüsü ve ihlal davranışı `CsvWriter`/`XlsxWriter` ile birebir aynıdır.
 */
final class PdfWriter implements PageAware, Writer
{
    /** `open()` ile verilen hedef; PDF her zaman TEK dosyadır. */
    private ?string $path = null;

    private bool $opened = false;

    /** @var list<string> yazılan dosya yolları */
    private array $paths = [];

    /** Tamamlanmış sayfaların HTML gövdesi — belge `close()`ta bundan kurulur. */
    private string $body = '';

    /**
     * Gövdeye şimdiye dek eklenmiş bölüm sayısı.
     *
     * İlk bölüm hariç hepsi yeni sayfada başlar; sayaç belgenin TAMAMI için tutulur, sayfa
     * başına değil — böylece ikinci sayfanın ilk grubu da yeni kâğıda düşer.
     */
    private int $sectionCount = 0;

    /**
     * Aktif sayfa var mı?
     *
     * Grup sayısına bakılamaz: kolonsuz bir sayfada `split()` boş liste döner ama sayfa
     * yine de AÇIKTIR ve `writeRow()` `noActiveSheet` fırlatmamalıdır.
     */
    private bool $inSheet = false;

    private string $sheetName = '';

    /**
     * Her grup için, o grubun kolonlarının ÖZGÜN kolon listesindeki konumları.
     *
     * Eşleştirme anahtar üzerinden yapılır (`Column::$key` gruba kopyalanırken korunur) ve
     * `startSheet()`te bir kez kurulur; `writeRow()` içinde anahtar arama yapılmaz.
     *
     * @var list<list<int>>
     */
    private array $positions = [];

    /**
     * Her grup için hazır `<td …>` açılış etiketleri (hizalama sınıfı gömülü).
     *
     * Satır başına yeniden üretilmemeleri için önden hesaplanır: elli bin satır × on kolon
     * = yarım milyon `match` çağrısı, hepsi aynı cevabı verirdi.
     *
     * @var list<list<string>>
     */
    private array $cellTags = [];

    /**
     * Her grup için hazır `<colgroup>` + `<thead>` bloğu.
     *
     * @var list<string>
     */
    private array $tableHead = [];

    /**
     * Her grup için biriken `<tr>` dizesi.
     *
     * @var list<string>
     */
    private array $rows = [];

    public function __construct(
        private readonly PdfOptions $options = new PdfOptions(),
    ) {
    }

    /**
     * Sayfa ayarını değiştirilmiş bir KOPYA döndürür (bkz. `PageAware`).
     *
     * Kopya taze bir yazıcıdır: yarım kalmış bir belgenin gövdesi yeni ayarla devam
     * edemez, zaten devam etmemeli de.
     */
    public function withPage(?Page $page, ?ColumnBudget $budget): static
    {
        if (null === $page && null === $budget) {
            return $this;
        }

        return new self(new PdfOptions(
            page: $page ?? $this->options->page,
            budget: $budget ?? $this->options->budget,
            fontFamily: $this->options->fontFamily,
            fontSizePt: $this->options->fontSizePt,
            repeatHeader: $this->options->repeatHeader,
            title: $this->options->title,
        ));
    }

    public function open(string $path): void
    {
        if ($this->opened) {
            throw WriterException::alreadyOpen();
        }

        // Hedef daha İLK adımda denetlenir — `XlsxWriter` ile aynı gerekçeyle: dosya ancak
        // `close()`ta yazıldığı için yazılamaz bir yol tüm satırlar işlendikten SONRA
        // patlar ve kullanıcı dakikalarca bekleyip "izin yok" hatası alırdı.
        $this->guardTarget($path);

        $this->path = $path;
        $this->opened = true;
        $this->body = '';
        $this->sectionCount = 0;
        $this->paths = [];
        $this->resetSheet();
    }

    /**
     * @param list<Column> $columns
     */
    public function startSheet(string $name, array $columns): void
    {
        if (!$this->opened) {
            throw WriterException::notOpened();
        }

        // Önceki sayfa `finishSheet()` görmeden yenisi başlatıldıysa sessizce bitiririz —
        // diğer iki yazıcıyla aynı davranış.
        $this->finalizeSheet();

        $columns = array_values($columns);

        // Bölme TEK DOĞRULUK KAYNAĞIDIR ve burada BİR KEZ çağrılır. Gruplamayı yazıcı
        // içinde yeniden türetmek (ör. genişliğe bakarak) `ColumnBudget`in çapa/öncelik
        // kurallarını sessizce yok sayardı.
        $groups = $this->options->budget->split($columns, $this->options->page);

        // Anahtar → özgün konum. Grup kolonları özgün sırayı KORUMAZ (çapalar öne alınır),
        // bu yüzden eşleştirme sıraya değil anahtara dayanmak zorunda.
        $indexByKey = [];
        foreach ($columns as $index => $column) {
            $indexByKey[$column->key] = $index;
        }

        $this->positions = [];
        $this->cellTags = [];
        $this->tableHead = [];
        $this->rows = [];

        foreach ($groups as $group) {
            $widths = self::widthsOf($group);

            $head = '<colgroup>';
            foreach ($widths as $width) {
                $head .= '<col style="width:'.$width.'%">';
            }
            $head .= '</colgroup><thead><tr>';

            $positions = [];
            $tags = [];

            foreach ($group as $column) {
                $class = self::alignClass($column->align);

                $head .= '<th'.$class.'>'.self::escape($column->label).'</th>';
                $tags[] = '<td'.$class.'>';

                // -1 "bu kolonun karşılığı yok" demek. Boru hattı grupları hep kendi
                // verdiği kolon listesinden ürettiği için gerçekleşmemeli; gerçekleşirse
                // hücre boş basılır, dışa aktarma durmaz.
                $positions[] = $indexByKey[$column->key] ?? -1;
            }

            $this->tableHead[] = $head.'</tr></thead>';
            $this->cellTags[] = $tags;
            $this->positions[] = $positions;
            $this->rows[] = '';
        }

        $this->sheetName = $name;
        $this->inSheet = true;
    }

    /**
     * @param list<Cell> $cells kolonlarla aynı sırada
     */
    public function writeRow(array $cells): void
    {
        if (!$this->inSheet) {
            throw WriterException::noActiveSheet();
        }

        // Tampon yerinde `.=` ile büyütülmez, baştan kurulur: grup sayısı kadar elemanlı
        // küçücük bir dizi (pratikte bir ya da iki) ve karşılığında `list` garantisi.
        $rows = [];

        foreach ($this->positions as $group => $positions) {
            $tags = $this->cellTags[$group];
            $row = '<tr>';

            foreach ($positions as $slot => $position) {
                // Tipli `value` DEĞİL, yerelleştirilmiş `text` basılır: PDF bir görüntüdür,
                // hücre tipi diye bir şeyi yoktur. Ham float yazsaydık "1234.5" çıkardı.
                $cell = $cells[$position] ?? null;

                $row .= $tags[$slot].(null === $cell ? '' : self::escape($cell->text)).'</td>';
            }

            $rows[] = $this->rows[$group].$row.'</tr>';
        }

        $this->rows = $rows;
    }

    public function finishSheet(): void
    {
        if (!$this->inSheet) {
            throw WriterException::noActiveSheet();
        }

        $this->finalizeSheet();
    }

    /**
     * Belgeyi kurar, Dompdf'e verir ve diske yazar.
     *
     * `XlsxWriter::close()` gibi BU METOT DA FIRLATABİLİR: bütün iş burada yapıldığı için
     * buradaki hata "dosya hiç yazılamadı" demektir ve sessizce yutulursa çağıran var
     * olmayan bir dosyayı kullanıcıya sunar.
     *
     * @return list<string> yazılan dosya yolları
     */
    public function close(): array
    {
        if (!$this->opened) {
            // Zaten kapalı: art arda çağrılabilsin, `finally` içinde kullanılabilsin.
            return $this->paths;
        }

        $this->finalizeSheet();

        $path = $this->path ?? throw WriterException::notOpened();
        $html = $this->document();

        try {
            $dompdf = new Dompdf($this->dompdfOptions());

            // Kodlama AÇIKÇA verilir. Verilmezse Dompdf `<meta>` etiketine bakar, onu da
            // bulamazsa Latin-1 varsayar; Türkçe harfler daha ayrıştırma aşamasında bozulur.
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->render();

            $pdf = $dompdf->output();

            if (null === $pdf || '' === $pdf) {
                throw ExportException::unwritableTarget($path, 'Dompdf boş çıktı üretti');
            }

            if (false === file_put_contents($path, $pdf)) {
                throw ExportException::unwritableTarget($path, 'dosya yazılamadı (disk dolu ya da izin kaybedilmiş olabilir)');
            }
        } catch (DompdfException $exception) {
            // Dompdf kendi istisnasını `\Exception` soyundan fırlatır; sarmalamazsak
            // dışa aktarmayı saran `catch (TabulaException)` bloğu onu kaçırır.
            throw ExportException::unwritableTarget($path, $exception->getMessage());
        } finally {
            // Ne olursa olsun belleği bırak: biriken HTML gövdesi elli bin satırda
            // onlarca megabayt olabilir ve uzun ömürlü işçilerde (messenger) elde kalırdı.
            $this->opened = false;
            $this->path = null;
            $this->body = '';
            $this->sectionCount = 0;
            $this->resetSheet();
        }

        $this->paths = [$path];

        return $this->paths;
    }

    // ---------------------------------------------------------------- belge kurulumu

    /**
     * Aktif sayfayı gövdeye işler.
     *
     * `finishSheet()`in aksine sayfa yoksa sessizce döner — `startSheet()` ve `close()`
     * bunu "ne olursa olsun toparla" niyetiyle çağırır.
     */
    private function finalizeSheet(): void
    {
        if (!$this->inSheet) {
            return;
        }

        $caption = $this->captionFor($this->sheetName);

        foreach ($this->tableHead as $group => $head) {
            // İlk bölüm bulunduğu sayfada başlar; sonrakiler yeni kâğıda. Sayfa kırılımı
            // CSS sınıfıyla verilir, `:first-child` ile değil: Dompdf'in seçici desteği
            // sınırlı ve burada tahmin yürütmenin bedeli "boş ilk sayfa" olurdu.
            $break = $this->sectionCount > 0 ? ' class="set"' : '';
            ++$this->sectionCount;

            $this->body .= '<section'.$break.'>'
                // Sayfa adı yalnız ilk tablonun üstünde; sonraki gruplar aynı sayfanın
                // devamıdır ve satırları çapa kolonlardan tanınır.
                .(0 === $group ? $caption : '')
                .'<table>'.$head.'<tbody>'.$this->rows[$group].'</tbody></table>'
                .'</section>';
        }

        $this->resetSheet();
    }

    /** Tek parça HTML belge. */
    private function document(): string
    {
        $title = null === $this->options->title ? '' : trim($this->options->title);
        $escaped = self::escape($title);

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            // `<title>` süs değil: Dompdf onu PDF dosya özelliklerinin "Title" alanına
            // yazar, yani belge okuyucunun sekmesinde ve arama sonuçlarında görünür.
            .('' === $title ? '' : '<title>'.$escaped.'</title>')
            .'<style>'.$this->css().'</style>'
            .'</head><body>'
            .('' === $title ? '' : '<h1>'.$escaped.'</h1>')
            .$this->body
            .'</body></html>';
    }

    /**
     * Belgenin biçemi.
     *
     * `@page` kuralı `Page::cssPageRule()`den gelir ve kâğıt boyutunun TEK kaynağıdır.
     * `Dompdf::setPaper()` bilerek ÇAĞRILMAZ: Dompdf render sırasında CSS'teki `size`
     * değerini görürse `setPaper()`ı kendisi ezer (bkz. `Dompdf::render()`), yani iki yerde
     * boyut tanımlayan kod SESSİZCE CSS'e uyar. Mevcut ERP'nin en sinsi hatası buydu —
     * PHP tarafı A4 yatay derken şablon A5 yatay diyordu ve `setPaper()` çağrısı fiilen
     * dekoratifti. Tek kaynak: CSS.
     */
    private function css(): string
    {
        $options = $this->options;
        $size = $options->fontSizePt;

        return $options->page->cssPageRule()
            .sprintf(
                'body{margin:0;color:#000;font-family:%s;font-size:%spt;}',
                self::cssFontFamily($options->fontFamily),
                self::number($size),
            )
            .'h1{margin:0 0 3mm;font-size:'.self::number($size + 4.0).'pt;}'
            .'h2{margin:0 0 2mm;font-size:'.self::number($size + 2.0).'pt;}'
            // `display:block` Dompdf'in kendi biçeminde de var; yine de yazıyoruz, çünkü
            // `section` satır içi kalsaydı sayfa kırılımı hiç uygulanmaz ve tüm gruplar
            // aynı kâğıda yığılırdı — sessizce.
            .'section{display:block;}section.set{page-break-before:always;}'
            // `table-layout:fixed` şart: otomatik yerleşimde Dompdf kolon genişliğini
            // İÇERİĞE göre dağıtır ve tek bir uzun açıklama diğer tüm kolonları ezip
            // okunamaz hâle getirir. Sabit yerleşimde `<col>` yüzdeleri geçerlidir.
            .'table{table-layout:fixed;width:100%;border-collapse:collapse;}'
            // Başlığın her sayfada tekrarlanması `table-header-group` ile olur; ayar
            // kapalıysa AÇIKÇA `table-row-group` yazılır, yoksa Dompdf'in kendi
            // varsayılanı yine tekrarlardı ve ayar hiçbir işe yaramazdı.
            .($options->repeatHeader ? 'thead{display:table-header-group;}' : 'thead{display:table-row-group;}')
            // Satır ortasından kâğıt değiştirmek, sarmalı hücrelerde satırın yarısını
            // bir sayfada yarısını diğerinde bırakır.
            .'tr{page-break-inside:avoid;}'
            // `word-wrap:break-word`: sığmayan uzun bir değer (ör. boşluksuz bir kod)
            // kolonun dışına taşıp komşusunun üzerine basmasın.
            .'th,td{border:0.5pt solid #bfbfbf;padding:1mm 1.2mm;text-align:left;vertical-align:top;word-wrap:break-word;}'
            .'th{background-color:#f2f2f2;font-weight:bold;}'
            .'.r{text-align:right;}.c{text-align:center;}';
    }

    /** Dompdf çalışma ayarları. */
    private function dompdfOptions(): Options
    {
        $options = new Options();

        // CSS'te de aynı aile yazılır; `defaultFont` oradaki ad çözülemediğinde devreye
        // giren güvenlik ağıdır. İkisi de DejaVu'ya bakmazsa Türkçe harfler kutuya döner.
        $options->setDefaultFont($this->options->fontFamily);

        // Uzak kaynak KAPALI. Şablonu baştan sona biz üretiyoruz, dışarıdan tek bayt
        // çekilmesi gerekmiyor; açık bırakılsaydı hücre metnine gömülmüş bir adres render
        // sırasında sunucudan istek çıkarabilirdi (SSRF).
        $options->setIsRemoteEnabled(false);

        // `<script type="text/php">` değerlendirmesi KAPALI. Metni zaten kaçırıyoruz ama
        // veriden beslenen bir belgede yorumlayıcıyı hiç açık bırakmamak doğru duruştur.
        $options->setIsPhpEnabled(false);

        // Ayıklama çıktısı KAPALI: açık kaldığında Dompdf HTML'in içine yerleşim kutuları
        // ve CSS dökümü basar, yani "bozuk" bir PDF üretir.
        //
        // NOT: Dompdf 3.x'te `isHtmlParserDebugEnabled` diye bir ayar YOKTUR (0.8'deki
        // `isHtml5ParserEnabled` ile birlikte kaldırıldı). Bunu dizi anahtarı olarak
        // geçmek de işe yaramazdı: `Options::set()` tanımadığı anahtarı `method_exists()`
        // kontrolüyle SESSİZCE yutar — `setPaper()` hatasının aynısı, bir başka kılıkta.
        // Bu yüzden gerçekten var olan iki ayıklama düğmesi açıkça kapatılıyor.
        $options->setDebugCss(false);
        $options->setDebugLayout(false);

        return $options;
    }

    /**
     * Sayfa adını tablonun üstüne başlık olarak yazar.
     *
     * PDF'in sekmesi yoktur; `GroupedSheets` ile bölünmüş bir çıktıda sayfa adı (ör. müşteri
     * unvanı) yazılmazsa o bilgi TAMAMEN kaybolur. Ad belge başlığının aynısıysa basılmaz:
     * varsayılan tek-sayfalı dışa aktarmada sayfa adı zaten şemanın başlığıdır ve aynı
     * metni iki kez üst üste yazmanın anlamı yok.
     */
    private function captionFor(string $name): string
    {
        $name = trim($name);

        if ('' === $name) {
            return '';
        }

        $title = null === $this->options->title ? '' : trim($this->options->title);

        if ('' !== $title && mb_strtolower($name) === mb_strtolower($title)) {
            return '';
        }

        return '<h2>'.self::escape($name).'</h2>';
    }

    private function resetSheet(): void
    {
        $this->inSheet = false;
        $this->sheetName = '';
        $this->positions = [];
        $this->cellTags = [];
        $this->tableHead = [];
        $this->rows = [];
    }

    /**
     * Hedef yolun yazılabilir olduğunu `open()` anında doğrular.
     *
     * `XlsxWriter`daki eşinin aynısı; ikisi de dosyayı `close()`ta yazdığı için aynı
     * erken kontrole ihtiyaç duyar. Ortak bir özelliğe çıkarmadık: yazıcılar birbirinden
     * bağımsız kalsın, biri diğerinin davranışını değiştirmesin.
     */
    private function guardTarget(string $path): void
    {
        if ('' === trim($path)) {
            throw ExportException::unwritableTarget($path, 'hedef yol boş');
        }

        if (is_file($path)) {
            if (!is_writable($path)) {
                throw ExportException::unwritableTarget($path, 'dosya var ve salt okunur');
            }

            return;
        }

        $directory = \dirname($path);

        if (!is_dir($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizini yok', $directory));
        }

        if (!is_writable($directory)) {
            throw ExportException::unwritableTarget($path, sprintf('"%s" dizinine yazma izni yok', $directory));
        }
    }

    // ---------------------------------------------------------------- küçük yardımcılar

    /**
     * Bir grubun kolon genişliklerini YÜZDE olarak dağıtır.
     *
     * `Column::$width` Excel'in karakter birimindedir ve milimetre karşılığı yoktur; burada
     * yalnız GÖRECELİ ağırlık olarak kullanılır. Genişliği verilmemiş kolon, verilenlerin
     * ortalaması kadar pay alır — hiçbiri verilmemişse hepsi eşit paya iner. Toplam her
     * durumda tam 100 olur; `table-layout:fixed` eksik toplamda son kolonu daraltır ve
     * tablonun sağ kenarı tutmaz.
     *
     * @param list<Column> $group
     *
     * @return list<string> yüzdeler, kolonlarla aynı sırada
     */
    private static function widthsOf(array $group): array
    {
        $count = count($group);

        if (0 === $count) {
            return [];
        }

        $sized = [];
        foreach ($group as $column) {
            if (null !== $column->width && $column->width > 0) {
                $sized[] = $column->width;
            }
        }

        // Hiç genişlik verilmemişse ortalama 1.0 olur ve tüm ağırlıklar eşitlenir; iki
        // durum için ayrı kol yazmaya gerek kalmaz.
        $average = [] === $sized ? 1.0 : array_sum($sized) / count($sized);

        $weights = [];
        foreach ($group as $column) {
            $weights[] = null !== $column->width && $column->width > 0 ? (float) $column->width : $average;
        }

        $total = array_sum($weights);
        $percents = [];
        $used = 0.0;

        foreach ($weights as $index => $weight) {
            // Son kolon yuvarlama artığını yutar, böylece toplam tam 100 kalır.
            if ($index === $count - 1) {
                $percents[] = self::number(100.0 - $used);

                break;
            }

            $percent = round($weight / $total * 100.0, 4);
            $used += $percent;
            $percents[] = self::number($percent);
        }

        return $percents;
    }

    /**
     * `Align`ı hizalama sınıfına çevirir.
     *
     * `Column::$align` bu noktada çözülmüştür, yani `Auto` gelmez; `default` yine de sola
     * hizalar (CSS varsayılanı) — hizalanamayan bir kolon yüzünden dışa aktarma durmaz.
     */
    private static function alignClass(Align $align): string
    {
        return match ($align) {
            Align::Right => ' class="r"',
            Align::Center => ' class="c"',
            default => '',
        };
    }

    /**
     * Hücre/başlık metnini HTML'e güvenli hâle getirir.
     *
     * `ENT_QUOTES`: metin yalnız düğüm içeriğine değil, ileride bir özniteliğe de girebilir.
     * `ENT_SUBSTITUTE`: bayrak verilmezse GEÇERSİZ UTF-8 içeren tek bir hücre `htmlspecialchars`
     * tarafından BOŞ DİZEYE çevrilir ve veri sessizce kaybolur — veri tabanında latin1'den
     * kalma bozuk bir kayıt varsa tam olarak bu olur.
     */
    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Yazı tipi ailesini CSS'e gömülebilir hâle getirir.
     *
     * Ad yapılandırmadan gelir; tırnak ya da süslü parantez içeren bir değer `<style>`
     * bloğunu kapatıp kuralların geri kalanını çöpe çevirebilirdi.
     */
    private static function cssFontFamily(string $family): string
    {
        $clean = str_replace(['"', "'", '\\', ';', '{', '}', '<', '>'], '', $family);

        return '"'.$clean.'",sans-serif';
    }

    /** `8` yazsın, `8.0000` değil — kural okunur kalsın (bkz. `Page::trim()`). */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
