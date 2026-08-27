<?php

declare(strict_types=1);

namespace Balin\Tabula\Tests\Fixture;

use RuntimeException;

/** A per-test isolated, self-cleaning temporary directory. */
final class TempDirectory
{
    private function __construct(
        public readonly string $path,
    ) {
    }

    public static function create(): self
    {
        $path = sys_get_temp_dir().'/tabula-test-'.bin2hex(random_bytes(6));

        if (!mkdir($path, 0o777, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('The temporary directory could not be created: %s', $path));
        }

        return new self($path);
    }

    public function file(string $name): string
    {
        return $this->path.'/'.$name;
    }

    public function remove(): void
    {
        if (!is_dir($this->path)) {
            return;
        }

        foreach (glob($this->path.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($this->path);
    }
}
