<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Export\Writer;

use Nouxwell\Tabula\Exception\WriterException;

/**
 * The appearance options of the Excel writer.
 *
 * The colours are in the ARGB format PhpSpreadsheet expects (`FFRRGGBB`) — the leading two digits
 * are the alpha channel, and if they are left out the colour silently comes out wrong.
 */
final readonly class XlsxOptions
{
    /**
     * @param string $creator            The producer name written into the file properties
     * @param string $headerFill         The fill of the header row (ARGB)
     * @param string $requiredHeaderFill The fill of a required column's header (ARGB)
     * @param string $headerBorderColor  The thin line underneath the header (ARGB)
     * @param bool   $boldHeader         Whether the header is written in bold
     * @param bool   $freezeHeader       Whether the header row stays put while scrolling
     * @param bool   $autoFilter         Whether filter arrows are added to the header
     */
    public function __construct(
        public string $creator = 'Tabula',
        public string $headerFill = 'FFF2F2F2',
        public string $requiredHeaderFill = 'FFFCE4E4',
        public string $headerBorderColor = 'FFBFBFBF',
        public bool $boldHeader = true,
        public bool $freezeHeader = true,
        public bool $autoFilter = true,
    ) {
        // PhpSpreadsheet silently swallows a colour it cannot parse; the mistake only becomes
        // visible once the file is opened. Rejecting it at construction time is far cheaper than a
        // bug report that comes back as "the setting does not work" or as a pitch-black header band.
        foreach ([
            'header_fill' => $headerFill,
            'required_header_fill' => $requiredHeaderFill,
            'header_border_color' => $headerBorderColor,
        ] as $setting => $color) {
            if (1 !== preg_match('/^(?:[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/', $color)) {
                throw WriterException::invalidArgbColor($setting, $color);
            }
        }
    }

    /**
     * Undecorated output — for intermediate files that another system will read.
     *
     * A frozen row and filter arrows do not bother a machine, but they do produce needless XML; on
     * large multi-sheet output they inflate the file size measurably.
     */
    public static function plain(): self
    {
        return new self(
            boldHeader: false,
            freezeHeader: false,
            autoFilter: false,
        );
    }
}
