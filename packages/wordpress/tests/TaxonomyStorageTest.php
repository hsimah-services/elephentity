<?php

declare(strict_types=1);

namespace Eleph\WordPress\Tests;

use Eleph\Runtime\Identity\EntityId;
use Eleph\Runtime\Identity\PendingId;
use Eleph\Runtime\Storage\Comparison;
use Eleph\Runtime\Storage\Criteria;
use Eleph\Runtime\Storage\EdgeFilter;
use Eleph\Runtime\Storage\Filter;
use Eleph\Runtime\Storage\Write\Delete;
use Eleph\Runtime\Storage\Write\Insert;
use Eleph\Runtime\Storage\Write\Link;
use Eleph\Runtime\Storage\Write\Unlink;
use Eleph\Runtime\Storage\Write\Update;
use Eleph\WordPress\Taxonomy\TaxonomyStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(TaxonomyStorage::class)]
final class TaxonomyStorageTest extends TestCase
{
    private const TAXONOMY = 'aka';

    public function testGetReturnsARecordForAnExistingTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $record = $storage->get('Aka', self::TAXONOMY, EntityId::of($id));

        self::assertNotNull($record);
        self::assertSame('Sparky', $record->value('name'));
        self::assertSame($id, $record->id->raw());
    }

    public function testGetReturnsNullForAMissingTerm(): void
    {
        [, $storage] = $this->storage();

        self::assertNull($storage->get('Aka', self::TAXONOMY, EntityId::of(999)));
    }

    public function testGetManySilentlyDropsMissingIds(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $records = $storage->getMany('Aka', self::TAXONOMY, [EntityId::of($id), EntityId::of(999)]);

        self::assertCount(1, $records);
        self::assertSame('Sparky', $records[0]->value('name'));
    }

    public function testInsertCreatesATermAndReturnsItsId(): void
    {
        [$terms, $storage] = $this->storage();

        $id = $storage->insert(self::TAXONOMY, new Insert('Aka', new PendingId('Aka'), ['name' => 'Sparky']));

        self::assertSame('Sparky', $terms->name(self::TAXONOMY, (int) $id->raw()));
    }

    public function testInsertRequiresAStringName(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->insert(self::TAXONOMY, new Insert('Aka', new PendingId('Aka'), ['name' => null]));
    }

    public function testUpdateChangesTheName(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->update(self::TAXONOMY, new Update('Aka', EntityId::of($id), ['name' => 'Rex']));

        self::assertSame('Rex', $terms->name(self::TAXONOMY, $id));
    }

    public function testUpdateWithNoNameTouchesNothing(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->update(self::TAXONOMY, new Update('Aka', EntityId::of($id), []));

        self::assertSame('Sparky', $terms->name(self::TAXONOMY, $id));
    }

    public function testDeleteRemovesTheTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $id = $terms->insert(self::TAXONOMY, 'Sparky');

        $storage->delete(self::TAXONOMY, new Delete('Aka', EntityId::of($id)));

        self::assertNull($terms->name(self::TAXONOMY, $id));
    }

    public function testLinkAttachesATermWithoutDisturbingOthers(): void
    {
        [$terms, $storage] = $this->storage();
        $existing = $terms->insert(self::TAXONOMY, 'Existing');
        $new = $terms->insert(self::TAXONOMY, 'New');
        $terms->setTerms(self::TAXONOMY, 5, [$existing], append: false);

        $storage->link(self::TAXONOMY, new Link('Tutorial', 'akas', EntityId::of(5), EntityId::of($new)));

        self::assertSame([$existing, $new], $terms->relationships[self::TAXONOMY][5]);
    }

    public function testUnlinkWithATargetRemovesOnlyThatTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $a = $terms->insert(self::TAXONOMY, 'A');
        $b = $terms->insert(self::TAXONOMY, 'B');
        $terms->setTerms(self::TAXONOMY, 5, [$a, $b], append: false);

        $storage->unlink(self::TAXONOMY, new Unlink('Tutorial', 'akas', EntityId::of(5), EntityId::of($a)));

        self::assertSame([$b], $terms->relationships[self::TAXONOMY][5]);
    }

    public function testUnlinkWithNoTargetClearsEverything(): void
    {
        [$terms, $storage] = $this->storage();
        $a = $terms->insert(self::TAXONOMY, 'A');
        $terms->setTerms(self::TAXONOMY, 5, [$a], append: false);

        $storage->unlink(self::TAXONOMY, new Unlink('Tutorial', 'akas', EntityId::of(5)));

        self::assertArrayNotHasKey(5, $terms->relationships[self::TAXONOMY]);
    }

    public function testQueryWithNoLinksListsEveryTermAlphabeticallyByInsertion(): void
    {
        [$terms, $storage] = $this->storage();
        $terms->insert(self::TAXONOMY, 'B');
        $terms->insert(self::TAXONOMY, 'A');

        $page = $storage->query('Aka', self::TAXONOMY, new Criteria('Aka'));

        self::assertCount(2, $page->items);
        self::assertFalse($page->hasMore());
    }

    public function testQueryPaginatesAndReportsMore(): void
    {
        [$terms, $storage] = $this->storage();
        $a = $terms->insert(self::TAXONOMY, 'A');
        $terms->insert(self::TAXONOMY, 'B');
        $terms->insert(self::TAXONOMY, 'C');

        $page = $storage->query('Aka', self::TAXONOMY, (new Criteria('Aka'))->take(2));

        self::assertCount(2, $page->items);
        self::assertTrue($page->hasMore());
        self::assertSame($a, $page->items[0]->id->raw());

        $next = $storage->query('Aka', self::TAXONOMY, (new Criteria('Aka'))->take(2, $page->next));

        self::assertCount(1, $next->items);
        self::assertFalse($next->hasMore());
    }

    public function testQueryFieldFiltersAreRefused(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);

        $storage->query('Aka', self::TAXONOMY, (new Criteria('Aka'))->where(new Filter('name', Comparison::Equals, 'x')));
    }

    public function testQueryLinkedToReturnsTheAttachedTerms(): void
    {
        [$terms, $storage] = $this->storage();
        $sparky = $terms->insert(self::TAXONOMY, 'Sparky');
        $terms->setTerms(self::TAXONOMY, 5, [$sparky], append: false);

        $page = $storage->query(
            'Aka',
            self::TAXONOMY,
            (new Criteria('Aka'))->linkedTo(EdgeFilter::along('Tutorial', 'akas', EntityId::of(5))),
        );

        self::assertCount(1, $page->items);
        self::assertSame('Sparky', $page->items[0]->value('name'));
        self::assertNull($page->items[0]->value(EdgeFilter::PARENT_COLUMN));
    }

    public function testQueryLinkedToBatchesAcrossParentsAndProjectsWhichOneEachCameFrom(): void
    {
        [$terms, $storage] = $this->storage();
        $sparky = $terms->insert(self::TAXONOMY, 'Sparky');
        $rex = $terms->insert(self::TAXONOMY, 'Rex');
        $terms->setTerms(self::TAXONOMY, 5, [$sparky], append: false);
        $terms->setTerms(self::TAXONOMY, 6, [$rex], append: false);

        $page = $storage->query(
            'Aka',
            self::TAXONOMY,
            (new Criteria('Aka'))->linkedTo(EdgeFilter::along('Tutorial', 'akas', EntityId::of(5), EntityId::of(6))),
        );

        $byParent = [];

        foreach ($page->items as $record) {
            $byParent[(int) $record->value(EdgeFilter::PARENT_COLUMN)] = (string) $record->value('name');
        }

        self::assertSame(['Sparky' => 5, 'Rex' => 6], array_flip($byParent));
    }

    public function testQueryLinkedToReadBackwardsIsRefused(): void
    {
        [, $storage] = $this->storage();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be read backwards');

        $storage->query(
            'Aka',
            self::TAXONOMY,
            (new Criteria('Aka'))->linkedTo(EdgeFilter::back('Tutorial', 'akas', EntityId::of(5))),
        );
    }

    public function testCountWithNoLinksCountsEveryTerm(): void
    {
        [$terms, $storage] = $this->storage();
        $terms->insert(self::TAXONOMY, 'A');
        $terms->insert(self::TAXONOMY, 'B');

        self::assertSame(2, $storage->count(self::TAXONOMY, new Criteria('Aka')));
    }

    public function testCountLinkedToCountsAttachedTerms(): void
    {
        [$terms, $storage] = $this->storage();
        $sparky = $terms->insert(self::TAXONOMY, 'Sparky');
        $terms->setTerms(self::TAXONOMY, 5, [$sparky], append: false);

        $count = $storage->count(
            self::TAXONOMY,
            (new Criteria('Aka'))->linkedTo(EdgeFilter::along('Tutorial', 'akas', EntityId::of(5))),
        );

        self::assertSame(1, $count);
    }

    /**
     * @return array{0: FakeTerms, 1: TaxonomyStorage}
     */
    private function storage(): array
    {
        $terms = new FakeTerms();

        return [$terms, new TaxonomyStorage($terms)];
    }
}
