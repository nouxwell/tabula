<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export\Page;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Column;
use Balin\Tabula\Export\Page\ColumnBudget;
use Balin\Tabula\Export\Page\Overflow;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Schema\Align;
use Balin\Tabula\Schema\FieldType;
use Balin\Tabula\Schema\Priority;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Kolon bütçesi — PDF tarafının asıl işi.
 *
 * Mevcut ERP'de kolon sayısının bir sınırı YOKTU: `table-layout: fixed` altında yirmi kolon
 * okunamayacak kadar eziliyor, tek çare başlığı `<br>` ile elle bölen bir yama oluyordu.
 * Buradaki iddia ise şu: kâğıdın eni ölçülebilir bir şey olduğuna göre kolon sayısı da bir
 * tercih değil, HESAP'tır — ve sığmayanlara ne olacağı bilinçli bir seçimdir.
 *
 * `split()` bilerek saftır (Dompdf'e ihtiyaç duymaz); bu dosya da o yüzden tek bir dosya
 * bile yazmadan bütün gruplama kurallarını doğrulayabiliyor.
 */
#[CoversClass(ColumnBudget::class)]
#[CoversClass(Overflow::class)]
final class ColumnBudgetTest extends TestCase
{
    // ---------------------------------------------------------------- ★ bütçe hesabı

    #[Test]
    public function theBudgetGrowsWithThePaperWithoutTouchingTheConfiguration(): void
    {
        $budget = ColumnBudget::fit();

        // A4 yatay: (297 − 2×10) ÷ 22 = 12,59 → 12 kolon.
        self::assertSame(12, $budget->capacity(Page::a4()->landscape()));

        // AYNI bütçe nesnesi, yalnız kâğıt büyüdü: (420 − 2×10) ÷ 22 = 18,18 → 18 kolon.
        // Mimarinin iddiası tam olarak bu: sayfayı büyütmek kolon sayısını kendiliğinden
        // büyütür, elle bir "maksimum kolon" ayarı aramaya gerek kalmaz.
        self::assertSame(18, $budget->capacity(Page::a3()->landscape()));

        // Ve kâğıt dikeye dönünce daralır: (210 − 2×10) ÷ 22 = 8,63 → 8 kolon. PdfOptions'ın
        // "yatay kâğıt 8 kolon yerine 12 verir" gerekçesi bu iki sayıdır.
        self::assertSame(8, $budget->capacity(Page::a4()));
    }

    #[Test]
    public function aTighterMinimumWidthBuysMoreColumnsOnTheSamePaper(): void
    {
        $page = Page::a4()->landscape();

        // 277 ÷ 40 = 6,9 → 6. Geniş kolon isteyen (ör. adres) bir rapor daha az kolon alır.
        self::assertSame(6, ColumnBudget::fit()->minWidth(40.0)->capacity($page));

        // 277 ÷ 15 = 18,4 → 18. Okunabilirlik tabanını düşürmek bütçeyi büyütür; kararı
        // kütüphane değil çağıran verir.
        self::assertSame(18, ColumnBudget::fit()->minWidth(15.0)->capacity($page));
    }

    #[Test]
    public function theHardCeilingWinsOnlyWhenItIsLowerThanWhatFits(): void
    {
        $page = Page::a4()->landscape();

        // Genişlik 12'ye izin veriyor ama tavan 5: min(12, 5) = 5.
        self::assertSame(5, ColumnBudget::fit()->max(5)->capacity($page));

        // Tavan genişlikten yüksekse kâğıt kazanır — `max()` bir GARANTİ değil, bir SINIRDIR.
        self::assertSame(12, ColumnBudget::fit()->max(50)->capacity($page));

        // null tavanı kaldırır.
        self::assertSame(12, ColumnBudget::fit()->max(5)->max(null)->capacity($page));
    }

    #[Test]
    public function aCeilingBelowOneColumnIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/en az 1 olmalı/');

        ColumnBudget::fit()->max(0);
    }

    #[Test]
    public function aNonPositiveMinimumWidthIsRefused(): void
    {
        // Sıfır asgari genişlik `capacity()` içinde sıfıra bölme demekti; hesabın kendisi
        // değil, KURULUM durdurulmalı — hata mesajı orada hâlâ anlamlı.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/pozitif olmalı/');

        ColumnBudget::fit()->minWidth(0.0);
    }

    #[Test]
    public function aPageTooNarrowForASingleReadableColumnIsRefused(): void
    {
        // A5 dikey (148 mm) üzerinde 70 mm'lik boşluklar geriye 8 mm bırakır: tek kolon bile
        // sığmaz. Sessizce sıfır kolon dönmek boş bir PDF üretir ve kimse nedenini anlamaz.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/tek kolon bile sığmıyor/');

        ColumnBudget::fit()->capacity(Page::a5()->margins(70.0));
    }

    #[Test]
    public function aMinimumWidthWiderThanThePaperIsRefusedToo(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/tek kolon bile sığmıyor/');

        ColumnBudget::fit()->minWidth(300.0)->capacity(Page::a4()->landscape());
    }

    // ---------------------------------------------------------------- değişmezlik

    #[Test]
    public function everySettingReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        // Bütçe tipik olarak bir yerde bir kez kurulup birçok dışa aktarmada paylaşılır;
        // yerinde değişseydi tek bir çağrı yerinin `max(3)`ü tüm uygulamaya bulaşırdı.
        $base = ColumnBudget::fit();
        $page = Page::a4()->landscape();

        $narrow = $base->minWidth(40.0);
        $capped = $base->max(3);
        $anchored = $base->anchor('code');
        $dropping = $base->overflow(Overflow::Drop);

        self::assertNotSame($base, $narrow);
        self::assertNotSame($base, $capped);
        self::assertNotSame($base, $anchored);
        self::assertNotSame($base, $dropping);

        self::assertSame(12, $base->capacity($page), 'Temel bütçe kirletilmiş.');
        self::assertCount(2, $base->split(self::cols(18), $page), 'Temel bütçenin taşma davranışı değişmiş.');
    }

    // ---------------------------------------------------------------- sığan tablo

    #[Test]
    public function columnsThatAlreadyFitComeBackAsOneUntouchedGroup(): void
    {
        $columns = self::cols(5);

        $groups = ColumnBudget::fit()->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        // `assertSame` bilerek: nesneler kopyalanmamalı, sıraları da değişmemeli.
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function splittingNothingGivesNothing(): void
    {
        // Kolonsuz bir sayfa uydurma bir boş grup DÖNDÜRMEMELİ: `PdfWriter` grup sayısına
        // göre `<table>` üretiyor, boş bir grup başlıksız ve hücresiz bir tablo basardı.
        self::assertSame([], ColumnBudget::fit()->split([], Page::a4()->landscape()));
    }

    // ---------------------------------------------------------------- Shrink

    #[Test]
    public function shrinkNeverSplitsNoMatterHowManyColumnsThereAre(): void
    {
        $columns = self::cols(40);

        $groups = ColumnBudget::unlimited()->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function shrinkDoesNotEvenComplainAboutAPageThatIsTooNarrow(): void
    {
        // Shrink "hesabı bana sorma, sığdırmayı render'a bırak" demektir; bütçe hiç
        // hesaplanmadığı için `pageTooNarrow` da fırlamamalı. Aksi hâlde bilinçli olarak
        // sınırsız seçen kullanıcı, hiç umursamadığı bir kurala takılırdı.
        $groups = ColumnBudget::unlimited()->split(self::cols(30), Page::a5()->margins(70.0));

        self::assertCount(1, $groups);
        self::assertCount(30, $groups[0]);
    }

    // ---------------------------------------------------------------- Drop

    #[Test]
    public function dropKeepsExactlyTheBudgetAndSheddsTheOptionalColumnsFirst(): void
    {
        // Özgün sıra: a(Optional) b(Always) c(Normal) d(Optional) e(Normal) f(Always)
        // Bütçe 4. Önce Optional'lar (a, d) düşer; geriye b, c, e, f kalır.
        $columns = [
            self::col('a', Priority::Optional),
            self::col('b', Priority::Always),
            self::col('c', Priority::Normal),
            self::col('d', Priority::Optional),
            self::col('e', Priority::Normal),
            self::col('f', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(4)
            ->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups, 'Drop asla bölmez; tek grup dönmeli.');
        self::assertCount(4, $groups[0], 'Bütçe kadar kolon kalmalıydı.');

        // ★ Sıra ÖZGÜN sıradır, öncelik sırası değil. Eleme önceliğe göre yapılır ama
        // hayatta kalanlar okunabilir sıraya geri sokulur; aksi hâlde çıktı b, f, c, e
        // olur ve kullanıcının seçtiği kolon düzeni sessizce yeniden karılırdı.
        self::assertSame(['b', 'c', 'e', 'f'], self::keys($groups[0]));
    }

    #[Test]
    public function dropSheddsNormalColumnsOnlyAfterTheOptionalOnesAreGone(): void
    {
        // Bütçe 3: iki Optional düşse bile hâlâ bir fazla var, sıradaki kurban en baştaki
        // Normal olmalı — Always'e dokunulmadan.
        $columns = [
            self::col('a', Priority::Normal),
            self::col('b', Priority::Optional),
            self::col('c', Priority::Always),
            self::col('d', Priority::Normal),
            self::col('e', Priority::Optional),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['a', 'c', 'd'], self::keys($groups[0]));
    }

    #[Test]
    public function dropKeepsEveryAlwaysColumnWhileThereIsRoom(): void
    {
        // Yerin olduğu her durumda `Always` dokunulmazdır: cari kodu düşen bir liste
        // okunamaz hâle gelir, eksik olduğu da anlaşılmaz.
        $columns = [
            self::col('k1', Priority::Optional),
            self::col('k2', Priority::Optional),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Optional),
            self::col('k5', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(2)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['k3', 'k5'], self::keys($groups[0]));
    }

    #[Test]
    public function dropRefusesToShedAMandatoryColumnEvenWhenTheyAloneOverflow(): void
    {
        // `Overflow::Drop` ve `Priority` ikisi de "Always asla düşmez" diye söz veriyor.
        // Dört dokunulmaz kolon üç kolonluk bütçeye sığmadığında sözü tutmanın yolu yok —
        // o hâlde kesip sessizce EKSİK bir belge basmak değil, durmak doğrusu. Çıktıda
        // olması ŞART denen bir kolonun kimseye haber vermeden kaybolması, kütüphanenin
        // ortadan kaldırmak için var olduğu hata sınıfının kendisiydi.
        $columns = [
            self::col('k1', Priority::Always),
            self::col('k2', Priority::Always),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Always),
        ];

        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/Zorunlu .*kolon sayısı 4.*bütçesi ise 3/s');

        ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());
    }

    #[Test]
    public function dropKeepsEveryMandatoryColumnWhenTheyFit(): void
    {
        // Sınır: zorunlu sayısı bütçeye EŞİT olduğunda eleme çalışmalı, patlamamalı —
        // isteğe bağlılar düşer, üç Always kolonun üçü de kalır.
        $columns = [
            self::col('k1', Priority::Always),
            self::col('k2', Priority::Optional),
            self::col('k3', Priority::Always),
            self::col('k4', Priority::Optional),
            self::col('k5', Priority::Always),
        ];

        $groups = ColumnBudget::fit()->overflow(Overflow::Drop)->max(3)
            ->split($columns, Page::a4()->landscape());

        self::assertSame(['k1', 'k3', 'k5'], self::keys($groups[0]));
    }

    // ---------------------------------------------------------------- ★ NextPageSet

    #[Test]
    public function nextPageSetCutsTheTableIntoGroupsAndRepeatsTheAnchors(): void
    {
        // 18 kolon, A4 yatayda bütçe 12, iki çapa. Çapalar her grupta tekrarlandığı için
        // veri kolonlarına kalan yuva 12 − 2 = 10'dur.
        $groups = ColumnBudget::fit()->anchor('k1', 'k2')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);

        self::assertSame(
            ['k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7', 'k8', 'k9', 'k10', 'k11', 'k12'],
            self::keys($groups[0]),
        );

        // Kalan 6 veri kolonu + iki çapa = 8. Son grup bütçeyi doldurmak zorunda değil.
        self::assertSame(
            ['k1', 'k2', 'k13', 'k14', 'k15', 'k16', 'k17', 'k18'],
            self::keys($groups[1]),
        );
    }

    #[Test]
    public function theAnchorsComeFirstInTheirOriginalOrderNotInTheOrderTheyWereNamed(): void
    {
        // Çapalar listenin ortasından seçildi ve `anchor()`a TERS sırada verildi. Yine de
        // grubun başına özgün sıralarıyla (k2, k5) geçmeliler: çapalar okuyucunun satırı
        // tanıdığı yerdir, her grupta yer değiştiren bir başlık düzeni tam tersini yapar.
        $groups = ColumnBudget::fit()->anchor('k5', 'k2')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);

        self::assertSame(
            ['k2', 'k5', 'k1', 'k3', 'k4', 'k6', 'k7', 'k8', 'k9', 'k10', 'k11', 'k12'],
            self::keys($groups[0]),
        );

        self::assertSame(
            ['k2', 'k5', 'k13', 'k14', 'k15', 'k16', 'k17', 'k18'],
            self::keys($groups[1]),
        );
    }

    #[Test]
    public function noDataColumnIsLostOrPrintedTwiceWhenTheTableIsCut(): void
    {
        // NextPageSet'in tüm vaadi budur: Drop'un aksine HİÇBİR veri kaybolmaz. Grupların
        // birleşimi, çapalar çıkarıldığında özgün veri kolonlarının aynısı olmalı —
        // aynı sırada, tekrarsız ve eksiksiz.
        $columns = self::cols(18);
        $anchors = ['k1', 'k2'];

        $groups = ColumnBudget::fit()->anchor(...$anchors)
            ->split($columns, Page::a4()->landscape());

        $printed = [];
        foreach ($groups as $group) {
            foreach ($group as $column) {
                if (!in_array($column->key, $anchors, true)) {
                    $printed[] = $column->key;
                }
            }
        }

        $expected = [];
        foreach ($columns as $column) {
            if (!in_array($column->key, $anchors, true)) {
                $expected[] = $column->key;
            }
        }

        self::assertSame($expected, $printed);
        self::assertSame($printed, array_values(array_unique($printed)), 'Bir veri kolonu iki gruba birden düşmüş.');
    }

    #[Test]
    public function withoutAnchorsTheGroupsAreSimplyConsecutiveSlices(): void
    {
        $groups = ColumnBudget::fit()->split(self::cols(25), Page::a4()->landscape());

        self::assertCount(3, $groups);
        self::assertCount(12, $groups[0]);
        self::assertCount(12, $groups[1]);
        self::assertCount(1, $groups[2]);
        self::assertSame(['k25'], self::keys($groups[2]));
    }

    #[Test]
    public function anAnchorTheUserDidNotExportIsQuietlyIgnored(): void
    {
        // Çapa listesi tipik olarak şemayla birlikte sabit yazılır; kullanıcı ise kolonlarını
        // kendi seçer. Seçilmemiş bir çapa HATA DEĞİLDİR — dışa aktarmayı bunun için
        // durdurmak, kullanıcının kolon seçimini kütüphaneye şikâyet ettirmek olurdu.
        $groups = ColumnBudget::fit()->anchor('musteri.kod', 'k1')
            ->split(self::cols(18), Page::a4()->landscape());

        self::assertCount(2, $groups);
        // Yalnız gerçekten var olan çapa tekrarlanır: bütçeden BİR yuva eksilir (iki değil),
        // yani veri kolonlarına 11 yuva kalır ve 17 veri kolonu 11 + 6 olarak bölünür.
        self::assertSame('k1', $groups[0][0]->key);
        self::assertSame('k1', $groups[1][0]->key);
        self::assertCount(12, $groups[0]);
        self::assertCount(7, $groups[1]);
    }

    #[Test]
    public function anchorsThatEatTheWholeBudgetAreRefused(): void
    {
        // Çapa sayısı bütçeye eşitse geriye VERİ kolonu kalmaz: her grup aynı iki çapadan
        // ibaret olur, sonsuz sayıda anlamsız sayfa takımı üretilirdi. Sessiz sonsuz döngü
        // yerine kurulum hatası.
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/geriye veri kolonu kalmıyor/');

        ColumnBudget::fit()->max(2)->anchor('k1', 'k2')
            ->split(self::cols(9), Page::a4()->landscape());
    }

    #[Test]
    public function moreAnchorsThanTheBudgetAreRefusedToo(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/Çapa kolon sayısı/');

        ColumnBudget::fit()->max(2)->anchor('k1', 'k2', 'k3')
            ->split(self::cols(9), Page::a4()->landscape());
    }

    #[Test]
    public function anchorsAreIrrelevantWhenEverythingAlreadyFits(): void
    {
        // Bütçeyi dolduran çapalar bile sığan bir tabloyu bölmeye çalışmamalı: `split()`
        // taşma yoksa hiç gruplama yapmaz, dolayısıyla `anchorsFillTheBudget` de fırlamaz.
        $columns = self::cols(2);

        $groups = ColumnBudget::fit()->max(2)->anchor('k1', 'k2')
            ->split($columns, Page::a4()->landscape());

        self::assertCount(1, $groups);
        self::assertSame($columns, $groups[0]);
    }

    #[Test]
    public function aBiggerPageCanRemoveTheNeedToSplitAtAll(): void
    {
        $columns = self::cols(16);
        $budget = ColumnBudget::fit()->anchor('k1');

        // A4 yatayda 16 kolon sığmaz (bütçe 12) → iki grup.
        self::assertCount(2, $budget->split($columns, Page::a4()->landscape()));

        // A3 yatayda bütçe 18'e çıkar → hiç bölünmez, üstelik tek satır ayar değişmedi.
        self::assertSame([$columns], $budget->split($columns, Page::a3()->landscape()));
    }

    // ---------------------------------------------------------------- yardımcılar

    private static function col(string $key, Priority $priority = Priority::Normal): Column
    {
        return new Column(
            key: $key,
            label: strtoupper($key),
            type: FieldType::String,
            align: Align::Left,
            width: null,
            required: false,
            priority: $priority,
        );
    }

    /** @return list<Column> */
    private static function cols(int $count): array
    {
        $columns = [];

        for ($i = 1; $i <= $count; ++$i) {
            $columns[] = self::col('k'.$i);
        }

        return $columns;
    }

    /**
     * @param list<Column> $columns
     *
     * @return list<string>
     */
    private static function keys(array $columns): array
    {
        return array_map(static fn (Column $column): string => $column->key, $columns);
    }
}
