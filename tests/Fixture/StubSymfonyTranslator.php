<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The smallest possible stand-in for `symfony/translation` in the container tests.
 *
 * The real package is not installed (only `translation-contracts` is), yet the bridge sets up a
 * `service(TranslatorInterface::class)` reference; if no service with that id can be found
 * during compilation, the container never comes up at all. A CONCRETE class is mandatory for
 * the reference to be resolvable — an anonymous class cannot be handed to the container as a
 * Definition.
 *
 * On a missing key it imitates Symfony's behaviour: IT RETURNS THE KEY ITSELF. Because
 * `SymfonyTranslator`'s domain chain looks at exactly that signal before moving on to the next
 * domain, imitating the "no hit" behaviour correctly is a precondition of the chain test.
 */
final readonly class StubSymfonyTranslator implements TranslatorInterface
{
    /**
     * @param array<string, array<string, string>> $catalogue domain => (key => text)
     */
    public function __construct(
        private array $catalogue = [],
        private string $locale = 'en',
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->catalogue[$domain ?? 'messages'][$id] ?? $id;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }
}
