<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Tests\Fixture;

use Nouxwell\Tabula\Export\Page\Page;
use PHPUnit\Framework\Assert;

/**
 * A tiny reader that reads the GEOMETRY of a produced PDF.
 *
 * In this library, verifying that the page setting was applied at the "the object was
 * constructed" level is not enough: the reason this phase exists was a paper setting that was
 * set up but never made it into the output. The assertion is therefore built from the bytes of
 * the document.
 *
 * Because the text streams are compressed, the cell contents cannot be grepped; the geometry
 * can:
 *  - `/MediaBox [0 0 width height]` → the paper's real measurement in points,
 *  - `/Type /Page` → each individual sheet (`/Type /Pages` is the root of the tree, not a sheet).
 *
 * The expected measurement is NOT WRITTEN BY HAND, it is derived from `Page` itself
 * (`assertPaper`): a constant saying "A4 landscape is 841.89 points" would be a second copy of
 * the paper table, and once the two drifted apart the test would be looking at its own
 * constant rather than at the code.
 */
final class PdfDocument
{
    /** How many points a millimetre is; the PDF unit is 1/72 inch. */
    private const float POINTS_PER_MM = 72.0 / 25.4;

    /**
     * Half a point of tolerance: Dompdf rounds `/MediaBox` values to three decimals, so a
     * 210 mm sheet is printed as 595.276 points and exact equality never holds.
     */
    private const float TOLERANCE_PT = 0.5;

    private function __construct(
        private readonly string $path,
        private readonly string $bytes,
    ) {
    }

    public static function at(string $path): self
    {
        Assert::assertFileExists($path);

        return new self($path, (string) file_get_contents($path));
    }

    /** Verifies that the paper size PRINTED into the document is the same as the expected `Page`. */
    public function assertPaper(Page $expected): void
    {
        [$width, $height] = $this->paper();

        Assert::assertEqualsWithDelta(
            self::toPoints($expected->widthMm()),
            $width,
            self::TOLERANCE_PT,
            sprintf('The paper width of the document "%s" differs from the expected one.', $this->path),
        );

        Assert::assertEqualsWithDelta(
            self::toPoints($expected->heightMm()),
            $height,
            self::TOLERANCE_PT,
            sprintf('The paper height of the document "%s" differs from the expected one.', $this->path),
        );
    }

    public function assertPageCount(int $expected): void
    {
        Assert::assertSame(
            $expected,
            $this->pageCount(),
            sprintf('The sheet count of the document "%s" differs from the expected one.', $this->path),
        );
    }

    /**
     * The first `/MediaBox` entry in the document, in points.
     *
     * @return array{float, float} width, height
     */
    public function paper(): array
    {
        if (1 !== preg_match('#/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)#', $this->bytes, $found)) {
            Assert::fail(sprintf('There is no /MediaBox in the document "%s" — the format of Dompdf\'s output may have changed.', $this->path));
        }

        return [(float) $found[1], (float) $found[2]];
    }

    /** The number of sheets in the document. */
    public function pageCount(): int
    {
        // The `[^s]` suffix filters out the `/Type /Pages` entry (the ROOT of the page tree).
        // The `/Count` field is not looked at: the CPDF output also contains an empty
        // `/Count 0`, and working out which one is the real one would be extra work.
        return (int) preg_match_all('#/Type\s*/Page[^s]#', $this->bytes);
    }

    private static function toPoints(float $mm): float
    {
        return $mm * self::POINTS_PER_MM;
    }
}
