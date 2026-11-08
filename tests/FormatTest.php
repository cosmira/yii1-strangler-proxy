<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Exception\InvalidUpstreamResponse;
use Cosmira\Strangler\Schema\Format;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testTimestampRetainsFractionalPrecisionAndTheDeclaredTimezone(): void
    {
        self::assertSame('2026-10-19 15:03:13.240965', Format::timestamp('2026-10-19T15:03:13.240965+03:00', 'Y-m-d H:i:s.u'));
        self::assertSame('2026-10-19 12:03:13.000000', Format::timestamp('2026-10-19T12:03:13Z', 'Y-m-d H:i:s.u'));
    }

    #[DataProvider('invalidTimestamps')]
    public function testInvalidTimestampCannotBecomeAValidLookingNativeDate(mixed $value): void
    {
        $this->expectException(InvalidUpstreamResponse::class);

        Format::timestamp($value, 'Y-m-d H:i:s.u');
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidTimestamps(): iterable
    {
        yield 'null' => [null];
        yield 'number' => [123];
        yield 'empty' => [''];
        yield 'invalid calendar date' => ['2026-02-30T12:00:00Z'];
        yield 'embedded nul' => ["2026-10-19T12:00:00Z\0"];
    }
}
