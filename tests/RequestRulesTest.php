<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Convention;
use Cosmira\Strangler\Response\JsonResource;
use Cosmira\Strangler\Strangler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use stdClass;

final class RequestRulesTest extends TestCase
{
    public function testCreateRulesWrapPresentValuesAndLeaveEditNamesOrdinary(): void
    {
        $rules = Convention::requestRules([], ['PARENT_ID' => 'parent_ids'], ['is_visible' => true], ['create']);
        $convention = new Convention('GROUP_ID', JsonResource::make(), rules: $rules);

        foreach ([0, false, '', 'parent', ['invalid' => true]] as $parent) {
            self::assertSame(['parent_ids' => [$parent], 'name' => 'group', 'is_visible' => true],
                $convention->payload(['GROUP_ID' => 1, 'PARENT_ID' => $parent, 'NAME' => 'group'], ['action' => 'create']));
        }
        self::assertSame(['parent_id' => null, 'is_visible' => true],
            $convention->payload(['PARENT_ID' => null], ['action' => 'create']));
        self::assertSame(['is_visible' => true], $convention->payload([], ['action' => 'create']));
        self::assertSame(['parent_id' => 'parent'],
            $convention->payload(['PARENT_ID' => 'parent'], ['action' => 'edit']));
    }

    public function testDefaultsPreserveExplicitNullAndFalseAndUseValueFormatters(): void
    {
        $rules = Convention::requestRules([], [], ['is_visible' => true, 'label' => 'default'], []);
        $convention = new Convention('GROUP_ID', JsonResource::make(),
            requestFields: ['TITLE' => 'label'],
            requestValues: ['label' => static fn (mixed $value): mixed => is_string($value) ? strtoupper($value) : $value],
            rules: $rules);

        self::assertSame(['is_visible' => true, 'label' => 'DEFAULT'], $convention->payload([]));
        self::assertSame(['is_visible' => false, 'label' => null],
            $convention->payload(['IS_VISIBLE' => false, 'TITLE' => null]));
        self::assertSame(['label' => 'EXPLICIT', 'is_visible' => true], $convention->payload(['TITLE' => 'explicit']));
    }

    public function testListTargetsShareAliasCollisionProtection(): void
    {
        $rules = Convention::requestRules([], ['PARENT_ID' => 'parent_ids'], [], ['create']);
        $convention = new Convention('GROUP_ID', JsonResource::make(),
            requestFields: ['PARENTS' => 'parent_ids'], rules: $rules);

        foreach (['PARENTS', 'PARENT_IDS', 'parent_ids'] as $alias) {
            self::assertFalse($convention->payload(['PARENT_ID' => 'one', $alias => ['two']], ['action' => 'create']));
        }
        self::assertSame(['parent_ids' => ['one']],
            $convention->payload(['PARENTS' => ['one']], ['action' => 'create']));
    }

    public function testSharedAndScopedRulesAccumulateWithoutReplacingOtherMaps(): void
    {
        $rules = Convention::requestRules([], ['OWNER_ID' => 'owner_ids'], ['is_visible' => false], []);
        $rules = Convention::requestRules($rules, ['PARENT_ID' => 'parent_ids'], ['is_visible' => true], ['CrEaTe']);
        $rules = Convention::requestRules($rules, [], ['label' => 'default'], ['create']);
        self::assertSame($rules, Convention::requestRules($rules, [], [], ['edit']));
        $convention = new Convention('GROUP_ID', JsonResource::make(), rules: $rules);

        self::assertSame(['owner_ids' => [1], 'parent_ids' => [2], 'is_visible' => true, 'label' => 'default'],
            $convention->payload(['OWNER_ID' => 1, 'PARENT_ID' => 2], ['action' => 'create']));
        self::assertSame(['owner_ids' => [1], 'parent_id' => 2, 'is_visible' => false],
            $convention->payload(['OWNER_ID' => 1, 'PARENT_ID' => 2], ['action' => 'edit']));
    }

    public function testRulesPreserveObjectKindAndOpaqueNestedValuesWithoutMutatingInput(): void
    {
        $opaque = (object) ['OriginalName' => (object) []];
        $input = (object) ['PARENT_ID' => $opaque, 'META' => $opaque];
        $rules = Convention::requestRules([], ['PARENT_ID' => 'parent_ids'], ['is_visible' => true], []);
        $convention = new Convention('GROUP_ID', JsonResource::make(), rules: $rules);
        $mapped = $convention->payload($input);

        self::assertInstanceOf(stdClass::class, $mapped);
        self::assertSame([$opaque], $mapped->parent_ids);
        self::assertSame($opaque, $mapped->meta);
        self::assertTrue($mapped->is_visible);
        self::assertSame($opaque, $input->PARENT_ID);
        self::assertEquals((object) [], (new Convention('GROUP_ID', JsonResource::make()))->payload((object) []));
    }

    public function testRequestRulesRequireAnEnabledNamingConvention(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Field mappings require a native identifier.');

        Strangler::proxy('example')
            ->request(defaults: ['is_visible' => true])
            ->post('create', '/items')->build();
    }

    public function testInvalidRegistrationsFailBeforeRuntime(): void
    {
        foreach ([
            static fn (): array => Convention::requestRules([], ['A' => 'same', 'B' => 'same'], [], []),
            static fn (): array => Convention::requestRules([], [], ['' => true], []),
            static fn (): mixed => (new \ReflectionMethod(Convention::class, 'requestRules'))->invoke(null, [], [], [0 => true], []),
            static fn (): array => Convention::requestRules([], [], ['valid' => true], ['']),
            static fn (): array => Convention::requestRules(
                Convention::requestRules([], ['A' => 'same'], [], []), ['B' => 'same'], [], [],
            ),
        ] as $register) {
            try {
                $register();
                self::fail('Invalid request rules must fail at registration.');
            } catch (InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
