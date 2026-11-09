<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Format;
use Cosmira\Strangler\Schema\Mapping;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ValueFormatsTest extends TestCase
{
    public function testBooleanNormalizesOnlyExplicitLegacyFlags(): void
    {
        foreach ([0, '0', false] as $value) {
            self::assertFalse(Format::boolean($value));
        }
        foreach ([1, '1', true] as $value) {
            self::assertTrue(Format::boolean($value));
        }
        foreach ([null, '', 'false', 'true', '01', 0.0, 1.0, 2, [], new stdClass()] as $value) {
            self::assertSame($value, Format::boolean($value));
        }
    }

    public function testJsonDecodesStringsAndKeepsOpaqueValues(): void
    {
        self::assertSame(['enabled' => false, 'nested' => ['value' => null]],
            Format::json('{"enabled":false,"nested":{"value":null}}'));
        self::assertSame([], Format::json('{}'));
        self::assertSame([1, null], Format::json('[1,null]'));
        self::assertSame('text', Format::json('"text"'));
        self::assertSame(1, Format::json('1'));
        self::assertFalse(Format::json('false'));
        self::assertNull(Format::json('null'));
        self::assertNull(Format::json('{broken'));
        foreach ([null, false, 1, ['nested' => 'opaque'], new stdClass()] as $value) {
            self::assertSame($value, Format::json($value));
        }
    }

    public function testJsonStringEncodesOnlyArraysAndRetainsEncodingFailure(): void
    {
        self::assertSame('{"enabled":false,"nested":{"value":null}}',
            Format::jsonString(['enabled' => false, 'nested' => ['value' => null]]));
        self::assertSame('[]', Format::jsonString([]));
        self::assertSame('["a\\/b","\\u0410"]', Format::jsonString(['a/b', 'А']));
        self::assertFalse(Format::jsonString(["\xFF"]));
        foreach ([null, false, 1, '{broken', new stdClass()] as $value) {
            self::assertSame($value, Format::jsonString($value));
        }
    }

    public function testPluckFactoryRetainsKeysFallbackOrderAndMalformedValues(): void
    {
        $pluck = Mapping::pluckFrom(['ID', 'id']);
        self::assertSame([
            'primary' => 0, 'fallback' => 'second', 'missing' => null, 'invalid' => 'opaque',
        ], $pluck([
            'primary'  => ['ID' => 0, 'id' => 'ignored'],
            'fallback' => (object) ['ID' => null, 'id' => 'second'],
            'missing'  => [], 'invalid' => 'opaque',
        ]));
        self::assertSame(['next'], $pluck([['id' => 'next']]));
        foreach ([null, false, 'opaque', new stdClass()] as $value) {
            self::assertSame($value, $pluck($value));
        }
    }

    public function testRecordsFactoryMapsFieldsDefaultsAndRequiredSelection(): void
    {
        $map = Mapping::recordsWith(['ID' => 'id', 'NOTE' => 'note'], ['note' => 'default'], ['id']);
        self::assertEquals([
            ['id' => 0, 'note' => null], (object) ['id' => 'second', 'note' => 'default'], 'opaque',
        ], $map([
            ['ID' => 0, 'NOTE' => null, 'ignored' => 'value'], ['NOTE' => 'missing ID'],
            (object) ['ID' => 'second'], 'opaque', ['ID'               => null],
        ]));
        self::assertSame(['named' => ['id' => 'third', 'note' => 'default']],
            $map(['named' => ['ID' => 'third'], 'missing' => []]));
        foreach ([null, false, 'opaque', new stdClass()] as $value) {
            self::assertSame($value, $map($value));
        }
    }

    public function testRecordsFactorySupportsFieldsAndPreservesNestedObjects(): void
    {
        $opaque = (object) ['KEY' => 'unchanged'];
        $map = Mapping::recordsWith(Fields::uppercase()->rename(['id' => 'RECORD_ID']));
        $result = $map([(object) ['RECORD_ID' => 'record', 'VALUE' => $opaque]]);

        self::assertIsArray($result);
        self::assertInstanceOf(stdClass::class, $result[0]);
        self::assertSame('record', $result[0]->id);
        self::assertSame($opaque, $result[0]->value);
    }

    public function testNativeSearchDatesAndVocabularyUseReusableConversions(): void
    {
        $expand = Format::expandWith(['old' => ['new']]);
        self::assertSame('old,new,other', $expand('old,other'));
        self::assertSame('1970-01-01T00:00:00Z', Format::unixTimestamp(0));
        self::assertSame('invalid', Format::unixTimestamp('invalid'));
        $this->expectException(\InvalidArgumentException::class);
        Mapping::field('', Format::boolean(...));
    }
}
