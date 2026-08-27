<?php

declare(strict_types=1);

namespace Balin\Tabula\Value;

use Balin\Tabula\Format;
use Balin\Tabula\Port\Translator;
use Balin\Tabula\Settings\TabulaSettings;

/**
 * Biçimlendiricilerin ihtiyaç duyduğu her şey — tek taşıyıcı.
 *
 * Locale burada AÇIKÇA taşınır; kuyruk işçisinde "istekten gelen dil" yoktur.
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
