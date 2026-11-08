<?php

declare(strict_types=1);

namespace Cosmira\Strangler\Tests;

use Cosmira\Strangler\Query;
use Cosmira\Strangler\Schema\Fields;
use Cosmira\Strangler\Schema\Selection;
use PHPUnit\Framework\TestCase;

final class SelectionTest extends TestCase
{
    public function testActionsKeepExplicitEmptyDefaultsAndMergedSelectionDistinct(): void
    {
        $seen = [];
        $selection = new Selection(
            defaults: static function (array $context) use (&$seen): array {
                $seen[] = $context['action'];

                return ['native' => []];
            },
            replaceFor: ['edit'],
            emptyFor: ['delete'],
            extendFor: ['get' => ['contacts', 'status']],
        );
        foreach ([
            ['get', [], ['contacts' => [], 'status' => []]],
            ['get', ['with' => []], []],
            ['get', ['with' => ['custom'], 'merge_with' => ['ignored']], ['contacts' => [], 'status' => [], 'custom' => []]],
            ['edit', ['with' => ['ignored']], ['native' => []]],
            ['delete', ['with' => ['ignored']], []],
            ['view', ['with' => ['custom' => ['nested']], 'merge_with' => ['ignored']], ['custom' => ['nested' => []]]],
            ['view', ['merge_with' => ['merged']], ['native' => [], 'merged' => []]],
            ['create', ['merge_with' => ['ignored']], ['native' => []]],
        ] as [$action, $query, $expected]) {
            self::assertSame($expected, $selection->projection(['action' => $action, 'query' => $query]), $action);
        }
        self::assertSame(['edit', 'view', 'create'], $seen);
    }

    public function testMalformedInputCannotBecomeAValidDefaultSelection(): void
    {
        $selection = new Selection(defaults: static function (): array {
            self::fail('Invalid client selection must not read native defaults.');
        }, emptyFor: ['delete']);
        foreach ([['with' => 'contacts'], ['merge_with' => false], ['with' => [7]]] as $query) {
            self::assertNull($selection->projection(['action' => 'delete', 'query' => $query]));
            self::assertFalse($selection->query($query, ['action' => 'view'], Query::legacy(Fields::uppercase())));
        }
    }

    public function testMissingNativeDefaultsRemainDistinctFromAnEmptyProjection(): void
    {
        $selection = new Selection(defaults: static fn (): ?array => null, replaceFor: ['edit']);
        self::assertNull($selection->projection(['action' => 'edit']));
        self::assertSame(['merged' => []], $selection->projection(['action' => 'view', 'query' => ['merge_with' => ['merged']]]));
        self::assertSame([], (new Selection(defaults: static fn (): array => []))->projection(['action' => 'view']));
    }

    public function testQueryForwardsExactlyTheResponseSelectionAndKeepsOriginalInput(): void
    {
        $selection = new Selection(defaults: static fn (): array => ['contacts' => []]);
        $query = ['merge_with' => ['groups'], 'id' => 'item'];
        self::assertSame(['include' => 'contacts,groups'], $selection->query($query, ['action' => 'view'], Query::legacy(Fields::uppercase())));
        self::assertSame(['merge_with' => ['groups'], 'id' => 'item'], $query);
    }
}
