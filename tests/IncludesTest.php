<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Schema\Includes;
use PHPUnit\Framework\TestCase;

final class IncludesTest extends TestCase
{
    public function testNormalizesListsAndObjectsWithoutAcceptingUnsupportedDepth(): void
    {
        $includes = Includes::only(['contacts', 'groups' => ['ID_PATH', 'NAME_PATH']]);

        self::assertSame(['contacts' => [], 'groups' => ['ID_PATH' => []]], $includes->normalize([
            'contacts', 'groups' => ['ID_PATH'],
        ]));
        self::assertSame([], $includes->normalize([]));
        self::assertNull($includes->normalize(['unknown']));
        self::assertNull($includes->normalize(['groups' => ['ID_PATH' => ['recursive']]]));
        self::assertNull($includes->normalize(['contacts' => null]));
        self::assertNull($includes->normalize('contacts'));
    }

    public function testUnrestrictedTreesPreserveUnknownNamesAndNestedSelections(): void
    {
        $includes = Includes::all();
        self::assertSame(['unknown' => [], 'owner' => ['company' => ['address' => []]]], $includes->normalize([
            'unknown', 'owner' => ['company' => ['address']],
        ]));
        self::assertSame([], $includes->normalize([]));
        self::assertNull($includes->normalize(['owner' => 'company']));
        self::assertNull($includes->normalize(['owner' => [false]]));
        self::assertNull($includes->normalize(['']));
    }

    public function testRepeatedRelationNamesUseTheLastSelection(): void
    {
        $includes = Includes::only(['groups' => ['NAME_PATH']]);

        self::assertSame(['groups' => []], $includes->normalize([
            'groups' => ['NAME_PATH'], 'groups',
        ]));
        self::assertSame(['groups' => ['NAME_PATH' => []]], $includes->normalize([
            'groups', 'groups' => ['NAME_PATH'],
        ]));
    }

    public function testInvalidSchemaIsRejectedAtConfigurationTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Includes::only(['contacts' => 'not a relation tree']);
    }
}
