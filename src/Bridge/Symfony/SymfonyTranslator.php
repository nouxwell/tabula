<?php

declare(strict_types=1);

namespace Balin\Tabula\Bridge\Symfony;

use Balin\Tabula\Port\Translator;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The Symfony implementation of the `Translator` port.
 *
 * THE TRANSLATION DOMAIN PROBLEM: Symfony catalogues are split into domains — in the system
 * this replaces, labels live in `messages` and enum texts in the `enum` domain. But
 * enums return their translation key through `label(): string`, and that key CARRIES NO domain
 * information. There are two ways to solve this without touching more than 200 existing enums:
 *
 *  1. AN EXPLICIT PREFIX — `enum:purchase_status.open`. If the prefix is in the
 *     `addressableDomains` list it counts as a domain; otherwise it is taken to be part of the
 *     key (which stops keys containing a colon from being split by accident).
 *  2. A DOMAIN CHAIN — with no prefix, `domains` are tried in order and the FIRST domain that
 *     defines the key wins.
 *
 * HIT DETECTION — this is the treacherous part. The first version assumed "Symfony gives the
 * key back on a miss" and compared the returned string with `strtr($key, $params)`. That gives
 * the wrong answer in THREE cases (all three verified against the real
 * `Symfony\Component\Translation\Translator`):
 *
 *   a) A FALSE MISS — a translation equal to its own key. `messages: ['IBAN' => 'IBAN']` is
 *      defined but is taken for a miss, and the NEXT domain in the chain silently wins.
 *      A translation equal to itself is not rare at all (IBAN, VAT, ISO codes, unit symbols).
 *   b) A FALSE HIT — an ICU domain (`messages+intl-icu`). On a miss the ICU formatter DOES NOT
 *      substitute `%name%`; the returned string is not equal to the `strtr` result, the chain
 *      stops right there and the raw key is written into the cell.
 *   c) A FALSE HIT — plural keys containing `|`. A miss is formatted by `IdentityTranslator`
 *      with plural selection, not with `strtr`.
 *
 * The fix: ASK the catalogue instead of INFERRING the hit from the returned string. Symfony's
 * real translator (and the Logging/DataCollector wrappers) implement `TranslatorBagInterface`;
 * since `defines()` does not walk their fallback catalogues, the fallback chain is walked by
 * hand. If a translator that does not implement `TranslatorBagInterface` is given, we drop
 * back to the old heuristic — flawed, but better than nothing at all.
 */
final readonly class SymfonyTranslator implements Translator
{
    /** @var list<string> */
    private array $addressableDomains;

    /**
     * @param list<string>      $domains            the translation domains, tried in order
     * @param list<string>|null $addressableDomains the domains reachable with a `domain:key` prefix
     *                                              (null = the same as `$domains`)
     */
    public function __construct(
        private TranslatorInterface $translator,
        private array $domains = ['messages'],
        private string $domainSeparator = ':',
        ?array $addressableDomains = null,
    ) {
        // The explicitly addressable domains are a SUPERSET of the chain: every domain in the
        // chain must also be callable with a prefix, and domains outside the chain (e.g.
        // `validators`) may be listed as well. Otherwise the prefix would only work for
        // domains that are already in the chain — that is, exactly the domains that have no
        // need of a prefix — which would be the very opposite of the feature.
        $this->addressableDomains = array_values(array_unique(
            [...$domains, ...($addressableDomains ?? [])],
        ));
    }

    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $normalized = $this->normalizeParams($params);
        [$explicitDomain, $id] = $this->splitDomain($key);

        if (null !== $explicitDomain) {
            return $this->translator->trans($id, $normalized, $explicitDomain, $locale);
        }

        if ($this->translator instanceof TranslatorBagInterface) {
            $domain = $this->findDefiningDomain($id, $locale);

            return null === $domain
                ? strtr($id, $normalized)
                : $this->translator->trans($id, $normalized, $domain, $locale);
        }

        return $this->guessByComparison($id, $normalized, $locale);
    }

    /**
     * Finds the first domain that DEFINES the key; the fallback catalogues are walked as well.
     *
     * Since ICU domains sit in a separate catalogue domain (`<domain>+intl-icu`), both are asked.
     */
    private function findDefiningDomain(string $id, ?string $locale): ?string
    {
        \assert($this->translator instanceof TranslatorBagInterface);

        $catalogue = $this->translator->getCatalogue($locale);

        while (null !== $catalogue) {
            foreach ($this->domains as $domain) {
                if ($catalogue->defines($id, $domain)
                    || $catalogue->defines($id, $domain.MessageCatalogueInterface::INTL_DOMAIN_SUFFIX)
                ) {
                    return $domain;
                }
            }

            $catalogue = $catalogue->getFallbackCatalogue();
        }

        return null;
    }

    /**
     * The fallback heuristic for translators that do not implement `TranslatorBagInterface`.
     *
     * It carries the three defects described above; it is here only because we cannot ask the
     * catalogue.
     *
     * @param array<string, string> $normalized
     */
    private function guessByComparison(string $id, array $normalized, ?string $locale): string
    {
        $miss = strtr($id, $normalized);

        foreach ($this->domains as $domain) {
            $translated = $this->translator->trans($id, $normalized, $domain, $locale);

            if ($translated !== $miss) {
                return $translated;
            }
        }

        // In no domain at all: let the key itself stay visible — the cell is never left empty,
        // and a missing translation is visible to the eye in the output (better than silently
        // leaving a blank).
        return $miss;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function splitDomain(string $key): array
    {
        $position = strpos($key, $this->domainSeparator);

        if (false === $position || 0 === $position) {
            return [null, $key];
        }

        $prefix = substr($key, 0, $position);

        if (!in_array($prefix, $this->addressableDomains, true)) {
            return [null, $key];
        }

        $id = substr($key, $position + strlen($this->domainSeparator));

        // A key with no body, such as `enum:`, would produce an empty cell; showing the broken
        // key as it is beats silently leaving a blank.
        return '' === $id ? [null, $key] : [$prefix, $id];
    }

    /**
     * The library passes parameters by plain name (`['count' => 5]`); Symfony, on the other
     * hand, expects the placeholder form (`['%count%' => 5]`). Names already wrapped in
     * percent signs pass through as they are.
     *
     * @param array<string, string|int|float> $params
     *
     * @return array<string, string>
     */
    private function normalizeParams(array $params): array
    {
        $normalized = [];

        foreach ($params as $name => $value) {
            $normalized['%'.trim($name, '%').'%'] = (string) $value;
        }

        return $normalized;
    }
}
