using System.Text.Json;
using Elephentity.GraphQL.Http;
using Elephentity.GraphQL.Manifest;
using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Gateway;
using Elephentity.Runtime.Identity;
using Elephentity.Runtime.Memory;
using Elephentity.Runtime.Mutation;
using Elephentity.Runtime.Policy;
using Elephentity.Runtime.Query;
using Elephentity.Runtime.Storage;
using Elephentity.Runtime.Testing;
using Elephentity.Runtime.Type;
using GraphQL.Transport;
using Microsoft.Extensions.DependencyInjection;
using Record = Elephentity.Runtime.Storage.Record;
using RuntimeFacade = Elephentity.Runtime.Gateway.Runtime;

namespace Elephentity.GraphQL.Tests;

/// <summary>
/// The small book/author model the schema tests run on: the twin of <c>tests/fixtures/graphql/book-author.php</c>, which builds the
/// same model with the PHP package. Change one, change the other, and run <c>regenerate.sh</c>.
/// </summary>
internal static class BookModel
{
    public sealed record Book(EntityId Id, string Title, string Status, DateTimeOffset? PublishedAt, long? Pages, double? Rating, bool InPrint, JsonElement? Tags, IEdgeLoader Edges)
    {
        public IEntityQuery<object> Authors() => Edges.ToMany("Book", Id, "authors");
    }

    public sealed record Author(EntityId Id, string Name, IEdgeLoader Edges)
    {
        public IEntityQuery<object> Books() => Edges.InverseToMany("Book", "authors", Id);
    }

    public static GraphQLManifest Manifest() => new(
        objects: new Dictionary<string, ObjectTypeEntry>
        {
            ["Book"] = new(
                "Book",
                "Book",
                new Dictionary<string, FieldEntry>
                {
                    ["id"] = new("id", new("ID", NonNull: true), source => ((Book)source).Id, "The global id.", FieldEncoding.GlobalId),
                    ["databaseId"] = new("databaseId", new("ID", NonNull: true), source => ((Book)source).Id, null, FieldEncoding.Id),
                    ["title"] = new("title", new("String", NonNull: true), source => ((Book)source).Title, "What it is called."),
                    ["status"] = new("status", new("BookStatus", NonNull: true), source => ((Book)source).Status),
                    ["publishedAt"] = new("publishedAt", new("String"), source => ((Book)source).PublishedAt, null, FieldEncoding.Datetime),
                    ["pages"] = new("pages", new("Int"), source => ((Book)source).Pages),
                    ["rating"] = new("rating", new("Float"), source => ((Book)source).Rating),
                    ["inPrint"] = new("inPrint", new("Boolean", NonNull: true), source => ((Book)source).InPrint),
                    ["tags"] = new("tags", new("String"), source => ((Book)source).Tags, null, FieldEncoding.Json),
                },
                source => source is Book,
                new Dictionary<string, ConnectionEntry>
                {
                    ["authors"] = new("authors", "Book", "Author", "authors", source => ((Book)source).Authors(), "Who wrote it."),
                },
                "A book."),
            ["Author"] = new(
                "Author",
                "Author",
                new Dictionary<string, FieldEntry>
                {
                    ["id"] = new("id", new("ID", NonNull: true), source => ((Author)source).Id, null, FieldEncoding.GlobalId),
                    ["databaseId"] = new("databaseId", new("ID", NonNull: true), source => ((Author)source).Id, null, FieldEncoding.Id),
                    ["name"] = new("name", new("String", NonNull: true), source => ((Author)source).Name),
                },
                source => source is Author,
                new Dictionary<string, ConnectionEntry>
                {
                    ["books"] = new("books", "Author", "Book", "books", source => ((Author)source).Books()),
                }),
        },
        enums: new Dictionary<string, EnumTypeEntry>
        {
            ["BookStatus"] = new("BookStatus", new Dictionary<string, string> { ["DRAFT"] = "draft", ["LIVE"] = "live" }),
        },
        mutations: new Dictionary<string, MutationEntry>
        {
            ["createBook"] = new("createBook", MutationKind.Create, "Book", new Dictionary<string, GraphQLType>
            {
                ["title"] = new("String", NonNull: true),
                ["status"] = new("BookStatus", NonNull: true),
                ["publishedAt"] = new("String"),
                ["authors"] = new("ID", List: true),
            }, null, "Create a book."),
            ["updateBook"] = new("updateBook", MutationKind.Update, "Book", new Dictionary<string, GraphQLType>
            {
                ["id"] = new("ID", NonNull: true),
                ["title"] = new("String"),
                ["status"] = new("BookStatus"),
            }),
            ["publishBook"] = new("publishBook", MutationKind.Action, "Book", new Dictionary<string, GraphQLType>
            {
                ["id"] = new("ID", NonNull: true),
                ["at"] = new("String"),
            }, "publish"),
            ["createAuthor"] = new("createAuthor", MutationKind.Create, "Author", new Dictionary<string, GraphQLType>
            {
                ["name"] = new("String", NonNull: true),
            }),
        },
        roots: new Dictionary<string, RootFieldEntry>
        {
            ["Book"] = new("Book", "Books", "Book"),
            ["Author"] = new("Author", "Authors", "Author"),
        },
        queries: new Dictionary<string, QueryFieldEntry>
        {
            ["bookBySlug"] = new("bookBySlug", "Book", false, "Book", "bySlug", new Dictionary<string, GraphQLType> { ["slug"] = new("String", NonNull: true) }, "One book by its slug."),
            ["searchBooks"] = new("searchBooks", "Book", true, "Book", "search", new Dictionary<string, GraphQLType> { ["text"] = new("String"), ["status"] = new("BookStatus") }, "Books matching a search."),
        });

    /// <summary>What the model's catalogue does: stand-in for the code step 14 generates.</summary>
    public sealed class Catalogue : StubEntityCatalogue
    {
        public RuntimeFacade? Runtime { get; set; }

        public override IReadOnlyList<string> Entities() => ["Book", "Author"];

        public override IHydrator<object> Hydrator(string entity) => entity == "Book" ? new BookHydrator() : new AuthorHydrator();

        public override IReadOnlyDictionary<string, string> EdgeTargets() => new Dictionary<string, string> { ["Book.authors"] = "Author" };

        public override IReadOnlyList<string> FieldNames(string entity) =>
            entity == "Book" ? ["title", "status", "publishedAt", "pages", "rating", "inPrint", "tags"] : ["name"];

        public override IReadOnlyList<string> QueryArguments(string entity, string query) => query == "search" ? ["text", "status"] : ["slug"];

        public override IReadOnlyList<string> RequiredFields(string entity) => entity == "Book" ? ["title", "status"] : ["name"];

        public override IEntityReadPolicies ReadPolicies(string entity) => entity == "Book" ? new HideSecrets() : base.ReadPolicies(entity);

        public override void Apply(string entity, IMutationBuffer buffer, IReadOnlyDictionary<string, object?> input)
        {
            foreach (var (field, value) in input)
            {
                if (field == "authors")
                {
                    buffer.Edge("authors").Replace([.. ((IEnumerable<object?>)value!).Select(id => (IIdentifier)Elephentity.Runtime.Identity.EntityId.Of(Convert.ToString(id, System.Globalization.CultureInfo.InvariantCulture)!))]);
                }
                else
                {
                    buffer.Assign(field, value);
                }
            }
        }

        public override IReadOnlyDictionary<string, object?> ValuesOf(string entity, object instance) => instance switch
        {
            Book book => new Dictionary<string, object?> { ["title"] = book.Title, ["status"] = book.Status },
            Author author => new Dictionary<string, object?> { ["name"] = author.Name },
            _ => new Dictionary<string, object?>(),
        };

        public override ValueTask RunActionAsync(string entity, IMutableMutationContext mutation, ActionCall action, CancellationToken cancellationToken = default)
        {
            mutation.Assign("status", "live");

            return ValueTask.CompletedTask;
        }

        public override async ValueTask<object?> RunQueryAsync(string entity, string query, IReadOnlyDictionary<string, object?> args, CancellationToken cancellationToken = default)
        {
            var criteria = Criteria.For("Book");

            if (query == "search" && args.TryGetValue("status", out var status) && status is string stored)
            {
                criteria = criteria.Where(Filter.Equal("status", stored));
            }

            if (query == "bySlug")
            {
                criteria = criteria.Where(Filter.Equal("title", (string)args["slug"]!));
            }

            var rows = Runtime!.Queries().Of(Hydrator("Book"), criteria).AsObjects();

            return query == "bySlug" ? await rows.FirstAsync(cancellationToken) : rows;
        }
    }

    private sealed class BookHydrator : IHydrator<object>
    {
        public object Hydrate(Record record, IEdgeLoader edges) => new Book(
            record.Id,
            (string)record.Value("title")!,
            (string)record.Value("status")!,
            record.Value("publishedAt") is string at ? DateTimeOffset.Parse(at, System.Globalization.CultureInfo.InvariantCulture) : null,
            record.Value("pages") is { } pages ? Convert.ToInt64(pages, System.Globalization.CultureInfo.InvariantCulture) : null,
            record.Value("rating") is { } rating ? Convert.ToDouble(rating, System.Globalization.CultureInfo.InvariantCulture) : null,
            record.Value("inPrint") is true,
            record.Value("tags") is string tags ? JsonDocument.Parse(tags).RootElement.Clone() : null,
            edges);
    }

    private sealed class AuthorHydrator : IHydrator<object>
    {
        public object Hydrate(Record record, IEdgeLoader edges) => new Author(record.Id, (string)record.Value("name")!, edges);
    }

    private sealed class HideSecrets : IEntityReadPolicies
    {
        public bool IsEmpty() => false;

        public ValueTask<PolicyDecision> DecideAsync(object entity, IViewer viewer, CancellationToken cancellationToken = default) =>
            ValueTask.FromResult(entity is Book { Title: "secret" } ? PolicyDecision.Deny("Secret books are hidden.").WithPolicy("hideSecrets") : PolicyDecision.Allow());
    }
}

/// <summary>The model, assembled on the memory driver and executed through the same executor the endpoint uses.</summary>
internal sealed class Host
{
    public Host(ElephentityGraphQLOptions? options = null, GraphQLManifest? manifest = null, Action<IServiceCollection>? services = null)
    {
        var storage = new MemoryAdaptor();
        var catalogue = new BookModel.Catalogue();
        var viewers = new AnonymousViewerProvider();
        var reads = new ReadGate(catalogue, viewers);
        var runtime = new RuntimeFacade(storage, catalogue, new UnitOfWorkFactory(storage, catalogue, new NullProcessorRegistry()), reads, new WriteGate(catalogue, viewers));

        catalogue.Runtime = runtime;
        Storage = storage;

        var collection = new ServiceCollection();

        collection.AddSingleton<IStorageAdaptor>(storage);
        collection.AddSingleton<IEntityGateway>(runtime);
        services?.Invoke(collection);
        collection.AddElephentityGraphQL(manifest ?? BookModel.Manifest(), configured =>
        {
            configured.DefaultPageSize = options?.DefaultPageSize ?? 10;
            configured.MaxPageSize = options?.MaxPageSize ?? 100;
            configured.MaxDepth = options?.MaxDepth;
            configured.MaxComplexity = options?.MaxComplexity;
            configured.EnableIntrospection = options?.EnableIntrospection ?? true;
        });

        Services = collection.BuildServiceProvider();
        Executor = Services.GetRequiredService<ElephentityGraphQLExecutor>();
    }

    public MemoryAdaptor Storage { get; }

    public ServiceProvider Services { get; }

    public ElephentityGraphQLExecutor Executor { get; }

    public async Task<JsonElement> Run(string query, object? variables = null)
    {
        var request = new GraphQLRequest
        {
            Query = query,
            Variables = variables is null ? null : Executor.Serializer.Deserialize<global::GraphQL.Inputs>(JsonSerializer.Serialize(variables)),
        };

        var json = await Executor.ExecuteToJsonAsync(request, Services, TestContext.Current.CancellationToken);

        return JsonDocument.Parse(json).RootElement.Clone();
    }
}
