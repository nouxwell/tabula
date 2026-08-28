<?php

declare(strict_types=1);

namespace Nouxwell\Tabula\Value;

use Nouxwell\Tabula\Format;
use Nouxwell\Tabula\Port\Translator;
use Nouxwell\Tabula\Settings\TabulaSettings;

/**
 * Everything the formatters need — a single carrier.
 *
 * The locale is carried here EXPLICITLY; inside a queue worker there is no such thing as
 * "the language taken from the request".
 */
final readonly class FormatContext
{
    public function __construct(
        public string $locale,
        public Translator $translator,
        public TabulaSettings $settings,
        public Format $format,
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
