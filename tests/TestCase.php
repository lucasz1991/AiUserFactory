<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function assertJsonSame(mixed $expected, mixed $actual): void
    {
        $this->assertSame($this->canonicalJsonValue($expected), $this->canonicalJsonValue($actual));
    }

    protected function canonicalJsonValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalJsonValue($item), $value);
    }
}
