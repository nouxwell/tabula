<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value;

use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Settings\TabulaSettings;

/**
 * The context the parsers need — the mirror image of `FormatContext`.
 *
 * The translator is required here as well, because parsing is most often TRANSLATION IN
 * REVERSE: the "Yes" the user typed into the cell has to come back as `true`, and "Open" has
 * to come back as the `Status::Open` enum case. That is why the language is carried
 * explicitly here, exactly as it is when formatting.
 *
 * The reason this is a class of its own, separate from `FormatContext`, is the `format`
 * field: WHICH format we are writing to is meaningless while parsing, and keeping it here
 * would invite the wrong questions.
 */
final readonly class ParseContext
{
    public function __construct(
        public string $locale,
        public Translator $translator,
        public TabulaSettings $settings,
    ) {
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, $this->locale);
    }
}
