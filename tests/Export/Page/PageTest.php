<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Export\Page;

use Balin\Tabula\Exception\ExportException;
use Balin\Tabula\Export\Page\Orientation;
use Balin\Tabula\Export\Page\Page;
use Balin\Tabula\Export\Page\PageSize;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sayfa geometrisi — saf aritmetik.
 *
 * Buradaki sayılar "yaklaşık doğru" olamaz. Kâğıdın eni tüm kolon bütçesinin girdisidir:
 * bir milimetrelik sapma `ColumnBudget::capacity()` içindeki `floor()` yüzünden bir KOLON
 * farkına dönüşür ve kullanıcı bunu ancak PDF'te eksik bir sütun olarak görür.
 *
 * `cssPageRule()` ayrıca sınıfın var oluş sebebidir: mevcut ERP'de kâğıt boyutu hem PHP'de
 * (`Dompdf::setPaper()`) hem şablonun `@page` kuralında tanımlıydı, Dompdf sessizce CSS'e
 * uyuyordu ve `setPaper()` fiilen dekoratifti. Aşağıdaki testler kuralın GERÇEKTEN seçilen
 * ölçüyü yansıttığını ve yön değişiminin kurala işlediğini çiviliyor.
 */
#[CoversClass(Page::class)]
#[CoversClass(PageSize::class)]
final class PageTest extends TestCase
{
    // ---------------------------------------------------------------- ölçüler

    #[Test]
    public function standardPapersCarryTheirRealMillimetres(): void
    {
        $a4 = Page::a4();
        self::assertSame(210.0, $a4->widthMm());
        self::assertSame(297.0, $a4->heightMm());

        $a5 = Page::a5();
        self::assertSame(148.0, $a5->widthMm());
        self::assertSame(210.0, $a5->heightMm());

        $a3 = Page::a3();
        self::assertSame(297.0, $a3->widthMm());
        self::assertSame(420.0, $a3->heightMm());

        // Letter/Legal metrik değil: ölçüleri inçten gelir ve tam sayı DEĞİLDİR.
        // Yuvarlanmış bir 216 mm burada sessizce bir kolonluk bütçe farkı yaratabilirdi.
        $letter = Page::letter();
        self::assertSame(215.9, $letter->widthMm());
        self::assertSame(279.4, $letter->heightMm());

        $legal = Page::of(PageSize::Legal);
        self::assertSame(215.9, $legal->widthMm());
        self::assertSame(355.6, $legal->heightMm());
    }

    #[Test]
    public function landscapeSwapsTheEdgesInsteadOfInventingNewOnes(): void
    {
        $a4 = Page::a4()->landscape();
        self::assertSame(297.0, $a4->widthMm());
        self::assertSame(210.0, $a4->heightMm());

        $a3 = Page::a3()->landscape();
        self::assertSame(420.0, $a3->widthMm());
        self::assertSame(297.0, $a3->heightMm());

        self::assertSame(Orientation::Landscape, $a3->orientation);
    }

    #[Test]
    public function customPaperIsGivenInPortraitAndTurnsLikeAnyOther(): void
    {
        $ticket = Page::custom(80.0, 200.0);

        self::assertSame(80.0, $ticket->widthMm());
        self::assertSame(200.0, $ticket->heightMm());
        self::assertSame(200.0, $ticket->landscape()->widthMm());
        self::assertSame(80.0, $ticket->landscape()->heightMm());
    }

    // ---------------------------------------------------------------- kullanılabilir en

    #[Test]
    public function usableWidthIsThePaperMinusTheTwoSideMargins(): void
    {
        // A4 yatay, dört kenarda 10 mm: 297 − 10 − 10 = 277. Kolon bütçesinin tüm hesabı
        // bu sayıdan başlar (bkz. ColumnBudgetTest).
        self::assertSame(277.0, Page::a4()->landscape()->margins(10.0)->usableWidthMm());

        // Yalnız SOL/SAĞ boşluk sayılır: üst/alt boşluk kolon sayısını etkilemez.
        self::assertSame(257.0, Page::a4()->landscape()->marginsOf(5.0, 20.0, 5.0, 20.0)->usableWidthMm());

        // Aynı kâğıt dikeyken kullanılabilir en 190 mm'ye iner — yön değişimi bütçeyi
        // doğrudan etkiler, ayrı bir ayar gerekmez.
        self::assertSame(190.0, Page::a4()->margins(10.0)->usableWidthMm());
    }

    #[Test]
    public function theDefaultMarginIsTenMillimetresOnEveryEdge(): void
    {
        $page = Page::a4();

        self::assertSame(10.0, $page->marginTopMm);
        self::assertSame(10.0, $page->marginRightMm);
        self::assertSame(10.0, $page->marginBottomMm);
        self::assertSame(10.0, $page->marginLeftMm);
    }

    #[Test]
    public function marginsSetsAllFourEdgesAtOnce(): void
    {
        $page = Page::a4()->margins(7.5);

        self::assertSame(7.5, $page->marginTopMm);
        self::assertSame(7.5, $page->marginRightMm);
        self::assertSame(7.5, $page->marginBottomMm);
        self::assertSame(7.5, $page->marginLeftMm);
    }

    #[Test]
    public function marginsOfKeepsTheEdgesInCssOrder(): void
    {
        // Sıra CSS'in `margin` kısayoluyla AYNI: üst, sağ, alt, sol. Farklı olsaydı
        // `cssPageRule()` doğru sayıları yanlış kenarlara yazardı ve kimse fark etmezdi.
        $page = Page::a4()->marginsOf(1.0, 2.0, 3.0, 4.0);

        self::assertSame(1.0, $page->marginTopMm);
        self::assertSame(2.0, $page->marginRightMm);
        self::assertSame(3.0, $page->marginBottomMm);
        self::assertSame(4.0, $page->marginLeftMm);
    }

    // ---------------------------------------------------------------- değişmezlik

    #[Test]
    public function turningThePageLeavesTheOriginalUntouched(): void
    {
        $portrait = Page::a4();
        $landscape = $portrait->landscape();

        self::assertNotSame($portrait, $landscape);
        self::assertSame(Orientation::Portrait, $portrait->orientation);
        self::assertSame(210.0, $portrait->widthMm(), 'Dikey sayfa yatay çevrilirken DEĞİŞTİRİLMİŞ.');
    }

    #[Test]
    public function turningTwiceInTheSameDirectionChangesNothing(): void
    {
        $twice = Page::a4()->landscape()->landscape();

        self::assertSame(297.0, $twice->widthMm());
        self::assertSame(210.0, $twice->heightMm());
    }

    #[Test]
    public function goingBackToPortraitRestoresExactlyThePortraitPage(): void
    {
        // `landscape()` ölçüleri takas etmez, yalnız YÖNÜ tutar; takas okuma anında yapılır.
        // Ölçüler gerçekten takas edilseydi ileri-geri dönüş 297×210 dikey gibi saçma bir
        // sayfa üretirdi ve hata ancak baskıda görünürdü.
        $portrait = Page::a4()->margins(12.0);
        $roundTrip = $portrait->landscape()->portrait();

        self::assertEquals($portrait, $roundTrip);
        self::assertSame($portrait->cssPageRule(), $roundTrip->cssPageRule());
    }

    #[Test]
    public function marginsSurviveAnOrientationChangeAndViceVersa(): void
    {
        $page = Page::a3()->marginsOf(1.0, 2.0, 3.0, 4.0)->landscape();

        self::assertSame(2.0, $page->marginRightMm);
        self::assertSame(420.0, $page->widthMm());

        $again = $page->margins(6.0);
        self::assertSame(Orientation::Landscape, $again->orientation);
        self::assertSame(6.0, $again->marginLeftMm);
    }

    // ---------------------------------------------------------------- reddedilen kurulumlar

    #[Test]
    public function aNegativeMarginIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/negatif olamaz/');

        Page::a4()->margins(-1.0);
    }

    #[Test]
    public function aSingleNegativeEdgeIsEnoughToBeRefused(): void
    {
        // Dördünü birden denetlemek yetmez: tek bir kenarın negatif olması bile
        // `usableWidthMm()` değerini kâğıttan BÜYÜK yapar ve bütçe uydurma bir kolon fazlası verir.
        $this->expectException(ExportException::class);

        Page::a4()->marginsOf(10.0, 10.0, -0.5, 10.0);
    }

    #[Test]
    public function aZeroMarginIsPerfectlyLegal(): void
    {
        // Sıfır boşluk etiket/fiş baskısında gerçek bir ihtiyaç; negatifle aynı kefeye konmamalı.
        $page = Page::a4()->margins(0.0);

        self::assertSame(0.0, $page->marginTopMm);
        self::assertSame(210.0, $page->usableWidthMm());
    }

    #[Test]
    public function aPaperWithoutWidthIsRefused(): void
    {
        $this->expectException(ExportException::class);
        $this->expectExceptionMessageMatches('/en az 1 mm/');

        Page::custom(0.0, 100.0);
    }

    #[Test]
    public function aSubMillimetrePaperIsRefusedBecauseTheCssRuleWouldRoundItToZero(): void
    {
        // `cssPageRule()` ölçüyü iki ondalıkla yazar: 0.004 mm "pozitif" doğrulamasını geçer
        // ama kurala `0mm` olarak düşer ve Dompdf sessizce KENDİ varsayılan kâğıdına döner.
        // Doğrulanan değerle basılan değer ayrışmamalı.
        $this->expectException(ExportException::class);

        Page::custom(0.004, 100.0);
    }

    #[Test]
    public function aPaperWithANegativeEdgeIsRefused(): void
    {
        $this->expectException(ExportException::class);

        Page::custom(100.0, -1.0);
    }

    // ---------------------------------------------------------------- ★ tek doğruluk kaynağı

    #[Test]
    public function theCssRuleSpellsOutTheChosenPaperExactly(): void
    {
        // Kuralın TAM metni çivileniyor: bu dize Dompdf'in kâğıt boyutunu öğrendiği TEK yer.
        // Bir boşluk ya da birim kayması Dompdf'i sessizce varsayılan Letter'a düşürür.
        self::assertSame(
            '@page { size: 420mm 297mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::a3()->landscape()->margins(10.0)->cssPageRule(),
        );
    }

    #[Test]
    public function changingTheOrientationChangesTheRule(): void
    {
        $a3 = Page::a3()->margins(10.0);

        self::assertSame('@page { size: 297mm 420mm; margin: 10mm 10mm 10mm 10mm; }', $a3->cssPageRule());
        self::assertSame('@page { size: 420mm 297mm; margin: 10mm 10mm 10mm 10mm; }', $a3->landscape()->cssPageRule());
    }

    #[Test]
    public function theRuleCarriesEachMarginToItsOwnEdge(): void
    {
        self::assertSame(
            '@page { size: 297mm 210mm; margin: 12.5mm 8mm 12.5mm 8mm; }',
            Page::a4()->landscape()->marginsOf(12.5, 8.0, 12.5, 8.0)->cssPageRule(),
        );
    }

    #[Test]
    public function theRuleDropsNoiseZerosButKeepsARealFraction(): void
    {
        // `210.00mm` de geçerli CSS'tir; okunmayan bir kural ise kimsenin gözden
        // geçirmediği bir kuraldır. Ama sadeleştirme KISALTMA değildir: Letter'ın
        // 215.9'u yuvarlanırsa kâğıt fiilen değişir.
        self::assertSame(
            '@page { size: 210mm 297mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::a4()->cssPageRule(),
        );

        self::assertSame(
            '@page { size: 215.9mm 279.4mm; margin: 10mm 10mm 10mm 10mm; }',
            Page::letter()->cssPageRule(),
        );

        self::assertSame(
            '@page { size: 210mm 297mm; margin: 0mm 0mm 0mm 0mm; }',
            Page::a4()->margins(0.0)->cssPageRule(),
        );
    }
}
