<?php

declare(strict_types=1);

namespace Eleph\Schema\Tests;

use Eleph\Schema\Error\CompilationResult;
use Eleph\Schema\Ir\Cardinality;
use Eleph\Schema\Ir\Primitive;
use Eleph\Schema\Ir\RelationKind;
use Eleph\Schema\Ir\TerminalRule;
use Eleph\Schema\Ir\TriggerPhase;
use Eleph\Schema\SchemaCompiler;
use Eleph\Schema\SpecSource;
use Eleph\Schema\Tests\Support\TestIntegrations;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SchemaCompilerTest extends TestCase
{
    public function testCompilesTheValidFixtureSet(): void
    {
        $result = $this->compile('valid');

        self::assertSame([], array_map(
            static fn ($error) => $error->describe(),
            $result->errors,
        ));
        self::assertTrue($result->isSuccess());
    }

    public function testMergesPatternFieldsIntoTheEntity(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);

        // Its own field, plus Timestamps via Auditable, plus WordPressPost.
        self::assertArrayHasKey('title', $post->fields);
        self::assertArrayHasKey('createdAt', $post->fields);
        self::assertArrayHasKey('postId', $post->fields);
    }

    public function testTracksWhereAMergedMemberCameFrom(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);
        self::assertSame('the entity spec', $post->fields['title']->origin->describe());
        self::assertSame('pattern Timestamps', $post->fields['createdAt']->origin->describe());
    }

    public function testResolvesPatternsUsedByOtherPatterns(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);
        // Auditable uses Timestamps, which Post never names directly.
        self::assertNotContains('Timestamps', $post->uses);
        self::assertArrayHasKey('updatedAt', $post->fields);
        self::assertSame(TriggerPhase::PostCommit, $post->triggers['audit']->phase);

        // appliedPatterns is the other list: everything that actually applies,
        // transitively pulled-in patterns included — what a consumer wanting to know
        // "does this entity have Timestamps" actually needs.
        self::assertContains('Timestamps', $post->appliedPatterns);
        self::assertContains('Auditable', $post->appliedPatterns);
    }

    public function testDerivesRelationKindFromCardinalityAndInverseUniqueness(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);

        $comments = $post->edges['comments'];
        self::assertSame(Cardinality::Many, $comments->cardinality);
        self::assertSame(RelationKind::OneToMany, $comments->relation());
        self::assertFalse($comments->relation()->needsJoinTable());

        $tags = $post->edges['tags'];
        self::assertSame(RelationKind::ManyToMany, $tags->relation());
        self::assertTrue($tags->relation()->needsJoinTable());
    }

    public function testDerivesTheInverseAccessorNameFromTheDeclaringEntity(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);
        self::assertSame('post', $post->edges['comments']->inverse?->nameFor('Post'));
        self::assertSame('posts', $post->edges['tags']->inverse?->nameFor('Post'));
    }

    public function testReadsBothInlineAndDeclaredEnums(): void
    {
        $schema = $this->compile('valid')->schema();
        $post = $schema->entity('Post');

        self::assertNotNull($post);
        self::assertSame(Primitive::Enum, $post->fields['status']->type->primitive);
        self::assertSame('PostStatus', $post->fields['status']->enum?->declaredType);
        self::assertSame(['public', 'private'], $post->fields['visibility']->enum?->inlineValues);

        $postStatus = $schema->type('PostStatus');
        $money = $schema->type('Money');

        self::assertNotNull($postStatus);
        self::assertNotNull($money);
        self::assertTrue($postStatus->isEnum());
        self::assertFalse($money->isEnum());
        self::assertTrue($money->hasProcessors);
    }

    public function testKeepsRequiredAndNullableSeparate(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');

        self::assertNotNull($post);
        self::assertTrue($post->fields['title']->required);
        self::assertFalse($post->fields['title']->nullable);
        self::assertFalse($post->fields['price']->required);
        self::assertTrue($post->fields['price']->nullable);
    }

    public function testMergesPoliciesInPatternThenEntityOrder(): void
    {
        $post = $this->compile('valid')->schema()->entity('Post');
        $pattern = $this->compile('valid')->schema()->pattern('Auditable');

        self::assertNotNull($post);
        self::assertNotNull($pattern);
        self::assertSame(['owner', 'reporter'], array_keys($post->readPolicies));
        self::assertSame(['noBackwards'], array_keys($post->writePolicies));
        self::assertSame(TerminalRule::Allow, $post->terminalRead);
        self::assertSame(['owner'], array_keys($pattern->readPolicies));
    }

    private function compile(string $fixture): CompilationResult
    {
        return (new SchemaCompiler(integrations: TestIntegrations::registry()))->compile(
            new SpecSource(__DIR__ . '/fixtures/' . $fixture),
        );
    }
}
