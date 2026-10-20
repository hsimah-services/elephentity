using System.Text.Json;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.SideEffect;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Storage.Write;
using Elephentity.Runtime.Type;
using Elephentity.Runtime.UnitOfWork;
using Elephentity.Runtime.Verification;
using Decoder = Elephentity.Runtime.Query.ValueDecoder;
using Mut = Elephentity.Runtime.Mutation.Mutation;
using Record = Elephentity.Runtime.Storage.Record;

namespace Elephentity.Runtime.Tests.UnitOfWork;

public sealed class DeletionPlannerTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task AnEntityNothingDependsOnIsJustDeleted()
    {
        var plan = await Plan(new FakeStorage(), [], "Tag", 1);

        Assert.Equal([new Delete("Tag", EntityId.Of(1))], plan);
    }

    [Fact]
    public async Task CascadeDeletesDependentsFirst()
    {
        var storage = new FakeStorage();
        storage.Records["Comment"] = [Row("Comment", 10), Row("Comment", 11)];

        var plan = await Plan(storage, Rule("Post", new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Cascade)), "Post", 1);

        Assert.Equal(
            [new Delete("Comment", EntityId.Of(10)), new Delete("Comment", EntityId.Of(11)), new Delete("Post", EntityId.Of(1))],
            plan);
    }

    [Fact]
    public async Task CascadeRecursesThroughTheDependentsOwnEdges()
    {
        var storage = new FakeStorage();
        storage.Records["Comment"] = [Row("Comment", 10)];
        storage.Records["Reaction"] = [Row("Reaction", 100)];
        var rules = new Dictionary<string, IReadOnlyList<DeletionRule>>
        {
            ["Post"] = [new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Cascade)],
            ["Comment"] = [new DeletionRule("Reaction", "reactions", "Comment", DeletionPolicy.Cascade)],
        };

        var plan = await Plan(storage, rules, "Post", 1);

        Assert.Equal(["Reaction", "Comment", "Post"], plan.Select(operation => operation.Entity));
    }

    [Fact]
    public async Task RestrictRefusesWhileAnythingStillDependsOnIt()
    {
        var storage = new FakeStorage { CountResult = 2 };

        var failure = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            Plan(storage, Rule("Post", new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Restrict)), "Post", 1));

        Assert.Contains("2 Comment still depend on it", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task RestrictAllowsTheDeleteWhenNothingDependsOnIt()
    {
        var plan = await Plan(
            new FakeStorage(),
            Rule("Post", new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Restrict)),
            "Post",
            1);

        Assert.Equal([new Delete("Post", EntityId.Of(1))], plan);
    }

    [Fact]
    public async Task NullifyKeepsDependentsAndClearsTheirReference()
    {
        var storage = new FakeStorage();
        storage.Records["Comment"] = [Row("Comment", 10)];

        var plan = await Plan(storage, Rule("Post", new DeletionRule("Comment", "comments", "Post", DeletionPolicy.Nullify)), "Post", 1);

        Assert.Equal([new Unlink("Post", "comments", EntityId.Of(1)), new Delete("Post", EntityId.Of(1))], plan);
    }

    [Fact]
    public async Task NullifyFromTheTargetUnlinksEachDeclaringRow()
    {
        var storage = new FakeStorage();
        storage.Records["Post"] = [Row("Post", 5), Row("Post", 6)];

        var plan = await Plan(storage, Rule("Tag", new DeletionRule("Post", "tags", "Post", DeletionPolicy.Nullify, ViaJoinTable: true)), "Tag", 1);

        Assert.Equal(
            [
                new Unlink("Post", "tags", EntityId.Of(5), EntityId.Of(1)),
                new Unlink("Post", "tags", EntityId.Of(6), EntityId.Of(1)),
                new Delete("Tag", EntityId.Of(1)),
            ],
            plan);
    }

    [Fact]
    public async Task APolicyLoopIsRefusedRatherThanFollowed()
    {
        var storage = new FakeStorage();
        storage.Records["A"] = [Row("A", 1)];
        storage.Records["B"] = [Row("B", 1)];
        var rules = new Dictionary<string, IReadOnlyList<DeletionRule>>
        {
            ["A"] = [new DeletionRule("B", "b", "A", DeletionPolicy.Cascade)],
            ["B"] = [new DeletionRule("A", "a", "B", DeletionPolicy.Cascade)],
        };

        // Already-visited rows end the walk: each is planned once.
        var plan = await Plan(storage, rules, "A", 1);

        Assert.Equal(["B", "A"], plan.Select(operation => operation.Entity));
    }

    private static Dictionary<string, IReadOnlyList<DeletionRule>> Rule(string entity, DeletionRule rule) =>
        new() { [entity] = [rule] };

    private static Record Row(string entity, long id) => new(entity, EntityId.Of(id), new Dictionary<string, object?>());

    private static async Task<IReadOnlyList<IWriteOperation>> Plan(
        FakeStorage storage,
        Dictionary<string, IReadOnlyList<DeletionRule>> rules,
        string entity,
        long id) =>
        await UnitOfWorkTests.Planner(storage, rules).PlanAsync([new Deletion(entity, EntityId.Of(id))], Token);
}

public sealed class UniquenessCheckTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task ATakenValueIsAViolationWithAFieldPath()
    {
        var storage = new FakeStorage { CountResult = 1 };
        var mutation = new Mut("Item", new PendingId("Item"));
        mutation.Assign("barcode", "123");

        var violations = await Check(storage).CheckAsync(mutation, Token);

        Assert.Equal("Item.barcode", Assert.Single(violations).Path());
    }

    [Fact]
    public async Task AFreeValuePasses()
    {
        var mutation = new Mut("Item", new PendingId("Item"));
        mutation.Assign("barcode", "123");

        Assert.Empty(await Check(new FakeStorage()).CheckAsync(mutation, Token));
    }

    [Fact]
    public async Task ARowDoesNotCollideWithItself()
    {
        var storage = new FakeStorage();
        var mutation = new Mut("Item", EntityId.Of(7));
        mutation.Assign("barcode", "123");

        await Check(storage).CheckAsync(mutation, Token);

        var question = Assert.Single(storage.Counted);
        Assert.Contains(question.Filters, filter => filter.Field == "id" && filter.Comparison == Comparison.NotEqual);
    }

    [Fact]
    public async Task NullIsNeverTaken()
    {
        var storage = new FakeStorage { CountResult = 5 };
        var mutation = new Mut("Item", new PendingId("Item"));
        mutation.Assign("barcode", null);

        Assert.Empty(await Check(storage).CheckAsync(mutation, Token));
        Assert.Empty(storage.Counted);
    }

    [Fact]
    public async Task AFieldTheMutationDoesNotTouchIsNotChecked()
    {
        var storage = new FakeStorage { CountResult = 5 };
        var mutation = new Mut("Item", EntityId.Of(1));
        mutation.Assign("name", "x");

        Assert.Empty(await Check(storage).CheckAsync(mutation, Token));
        Assert.Empty(storage.Counted);
    }

    private static UniquenessCheck Check(FakeStorage storage) =>
        new(
            storage,
            new ValueEncoder(new Dictionary<string, string>(), new NullProcessorRegistry()),
            new Dictionary<string, IReadOnlyList<string>> { ["Item"] = ["barcode"] });
}

public sealed class ValueEncoderTests
{
    private static readonly ValueEncoder Encoder = new(new Dictionary<string, string>(), new NullProcessorRegistry());

    [Fact]
    public void ScalarsAndNullPassStraightThrough()
    {
        Assert.Equal("a", Encoder.Encode("E", "f", "a"));
        Assert.Equal(3L, Encoder.Encode("E", "f", 3L));
        Assert.Equal(3L, Encoder.Encode("E", "f", 3));
        Assert.Equal(true, Encoder.Encode("E", "f", true));
        Assert.Null(Encoder.Encode("E", "f", null));
    }

    [Fact]
    public void DatesAreStoredInUtcWhateverTheirOffset()
    {
        var local = new DateTimeOffset(2026, 1, 2, 3, 4, 5, TimeSpan.FromHours(10));
        var utc = new DateTimeOffset(2026, 1, 1, 17, 4, 5, TimeSpan.Zero);

        Assert.Equal(Encoder.Encode("E", "f", utc), Encoder.Encode("E", "f", local));
        Assert.Equal("2026-01-01T17:04:05.0000000Z", Encoder.Encode("E", "f", local));
    }

    [Fact]
    public void AnIdStoresItsRawValue() =>
        Assert.Equal(7L, Encoder.Encode("E", "f", EntityId.Of(7)));

    [Fact]
    public void JsonFieldsAreEncoded()
    {
        var value = new Dictionary<string, object?> { ["a"] = 1, ["b"] = new List<object?> { "x", null } };

        Assert.Equal("{\"a\":1,\"b\":[\"x\",null]}", Encoder.Encode("E", "f", value));
    }

    [Fact]
    public void AnUnknownObjectSaysWhatIsMissing()
    {
        var failure = Assert.Throws<InvalidOperationException>(() => Encoder.Encode("E", "f", new object()));

        Assert.Contains("E.f", failure.Message, StringComparison.Ordinal);
        Assert.Contains("write processor", failure.Message, StringComparison.Ordinal);
    }
}

public sealed class SideEffectDispatcherTests
{
    private static CancellationToken Token => TestContext.Current.CancellationToken;

    [Fact]
    public async Task PostCommitFailureIsSwallowedAndLaterHandlersStillRun()
    {
        var ran = new List<string>();
        var effects = new Effects(
            [
                _ => throw new InvalidOperationException("boom"),
                _ =>
                {
                    ran.Add("second");

                    return ValueTask.CompletedTask;
                },
            ]);
        var dispatcher = new SideEffectDispatcher(new Dictionary<string, IEntitySideEffects> { ["Post"] = effects });
        var mutation = new Mut("Post", EntityId.Of(1));

        await dispatcher.DispatchAsync(SideEffectPhase.PostCommit, [mutation], Token);

        Assert.Equal(["second"], ran);
    }

    [Fact]
    public async Task APreCommitFailureStopsTheSequence()
    {
        var ran = new List<string>();
        var effects = new Effects(
            [
                _ => throw new InvalidOperationException("boom"),
                _ =>
                {
                    ran.Add("second");

                    return ValueTask.CompletedTask;
                },
            ]);
        var dispatcher = new SideEffectDispatcher(new Dictionary<string, IEntitySideEffects> { ["Post"] = effects });

        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            dispatcher.DispatchAsync(SideEffectPhase.PreCommit, [new Mut("Post", EntityId.Of(1))], Token));

        Assert.Empty(ran);
    }

    [Fact]
    public async Task ACancelledPostCommitHandlerPropagates()
    {
        var effects = new Effects([_ => throw new OperationCanceledException()]);
        var dispatcher = new SideEffectDispatcher(new Dictionary<string, IEntitySideEffects> { ["Post"] = effects });

        await Assert.ThrowsAsync<OperationCanceledException>(() =>
            dispatcher.DispatchAsync(SideEffectPhase.PostCommit, [new Mut("Post", EntityId.Of(1))], Token));
    }

    private sealed class Effects(Func<CancellationToken, ValueTask>[] handlers) : IEntitySideEffects
    {
        public IEnumerable<Func<CancellationToken, ValueTask>> Handlers(
            SideEffectPhase phase,
            SideEffectEvent sideEffectEvent,
            IMutableMutationContext context) => handlers;
    }
}

public sealed class MutationTests
{
    [Fact]
    public void AnUntouchedEdgeIsUnchangedAndEmpty()
    {
        var mutation = new Mut("Post", EntityId.Of(1));

        Assert.False(mutation.IsEdgeChanged("tags"));
        Assert.Empty(mutation.PendingEdge("tags"));
    }

    [Fact]
    public void AddingToAnEdgeMakesItPending()
    {
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Edge("tags").Add(EntityId.Of(2));

        Assert.True(mutation.IsEdgeChanged("tags"));
        Assert.Equal([EntityId.Of(2)], mutation.PendingEdge("tags"));
    }

    [Fact]
    public void ReplacingAnEdgeReplacesWhatIsPending()
    {
        var mutation = new Mut("Post", EntityId.Of(1));
        mutation.Edge("tags").Add(EntityId.Of(2));
        mutation.Edge("tags").Replace([EntityId.Of(3)]);

        Assert.Equal([EntityId.Of(3)], mutation.PendingEdge("tags"));
    }

    [Fact]
    public void IdFallsBackToTheTargetUntilResolved()
    {
        var target = new PendingId("Post");
        var mutation = new Mut("Post", target);

        Assert.Same(target, mutation.Id);

        mutation.ResolveId(EntityId.Of(9));

        Assert.Equal(EntityId.Of(9), mutation.Id);
    }

    [Fact]
    public void PendingFallsBackToTheOriginal()
    {
        var mutation = new Mut("Post", EntityId.Of(1), new Dictionary<string, object?> { ["title"] = "old" });

        Assert.Equal("old", mutation.Pending("title"));

        mutation.Assign("title", "new");

        Assert.Equal("new", mutation.Pending("title"));
        Assert.Equal("old", mutation.Original("title"));
    }
}

public sealed class ValueDecoderTests
{
    [Fact]
    public void NumericStringsFromTheDriverAreAcceptedAsIntegers() =>
        Assert.Equal(-42L, Decoder.Whole("-42", "f"));

    [Fact]
    public void SomethingThatIsNotAnIntegerIsRefused() =>
        Assert.Throws<InvalidOperationException>(() => Decoder.Whole("4.2", "Item.count"));

    [Fact]
    public void BooleansSurviveTheTinyintRoundTrip()
    {
        Assert.True(Decoder.Bool(1L, "f"));
        Assert.False(Decoder.Bool("0", "f"));
        Assert.Throws<InvalidOperationException>(() => Decoder.Bool("maybe", "f"));
    }

    [Fact]
    public void DatesRoundTripThroughTheFormatTheyWereWrittenIn()
    {
        var moment = new DateTimeOffset(2026, 3, 4, 5, 6, 7, TimeSpan.Zero);
        var encoder = new ValueEncoder(new Dictionary<string, string>(), new NullProcessorRegistry());

        Assert.Equal(moment, Decoder.DateTime(encoder.Encode("E", "f", moment), "f"));
        Assert.Equal(moment, Decoder.DateTime("2026-03-04 05:06:07", "f"));
    }

    [Fact]
    public void AnUnparseableDateSaysWhichFieldHoldsIt()
    {
        var failure = Assert.Throws<InvalidOperationException>(() => Decoder.DateTime("never", "Post.at"));

        Assert.Contains("Post.at", failure.Message, StringComparison.Ordinal);
    }

    [Fact]
    public void JsonDecodesAndMalformedJsonIsRefused()
    {
        Assert.Equal(1, Decoder.Json("{\"a\":1}", "f").GetProperty("a").GetInt32());
        Assert.Throws<InvalidOperationException>(() => Decoder.Json("{", "f"));
    }

    [Fact]
    public void AStoredValueTheEnumNoLongerHasIsAMissedMigration()
    {
        Assert.Equal(DayOfWeek.Friday, Decoder.Enum("fri", "f", value => value is "fri" ? DayOfWeek.Friday : (DayOfWeek?)null));
        Assert.Throws<InvalidOperationException>(() =>
            Decoder.Enum("gone", "f", _ => (DayOfWeek?)null));
    }

    [Fact]
    public void AnIdAcceptsEveryStoredForm()
    {
        Assert.Equal(EntityId.Of(3), Decoder.Id(3L, "f"));
        Assert.Equal(EntityId.Of("abc"), Decoder.Id("abc", "f"));
    }
}
