//! Every `Elephentity.Runtime.*` name the emitted code writes, as constants.
//!
//! **These strings are on the contract surface** (`.llms/cross-repo.md`). A builder names runtime types as text, so
//! renaming a type in `elephentity-dotnet` fails nothing here: it fails when a generated project is compiled. The
//! `tests/dotnet` harness compiles every golden tree against the pinned runtime, and that, not a type checker, is
//! what catches a rename.
//!
//! Each is spelled `global::...` so a project that has an entity called `Record` or `Task` cannot capture it.

pub const ACTION_CALL: &str = "global::Elephentity.Runtime.Mutation.ActionCall";
pub const ANONYMOUS_VIEWER_PROVIDER: &str =
    "global::Elephentity.Runtime.Policy.AnonymousViewerProvider";
pub const CANCELLATION_TOKEN: &str = "global::System.Threading.CancellationToken";
pub const DATE_TIME_OFFSET: &str = "global::System.DateTimeOffset";
pub const DELETION: &str = "global::Elephentity.Runtime.Mutation.Deletion";
pub const DELETION_POLICY: &str = "global::Elephentity.Runtime.Storage.DeletionPolicy";
pub const DELETION_RULE: &str = "global::Elephentity.Runtime.Storage.DeletionRule";
pub const EDGE_LOADER: &str = "global::Elephentity.Runtime.Query.IEdgeLoader";
pub const EDGE_MUTATION: &str = "global::Elephentity.Runtime.Mutation.IEdgeMutation";
pub const ENTITY_CATALOGUE: &str = "global::Elephentity.Runtime.Catalogue.IEntityCatalogue";
pub const ENTITY_GATEWAY: &str = "global::Elephentity.Runtime.Gateway.IEntityGateway";
pub const ENTITY_ID: &str = "global::Elephentity.Runtime.Identity.EntityId";
pub const ENTITY_QUERIES: &str = "global::Elephentity.Runtime.Query.EntityQueries";
pub const ENTITY_QUERY: &str = "global::Elephentity.Runtime.Query.IEntityQuery";
pub const ENTITY_READ_POLICIES: &str = "global::Elephentity.Runtime.Policy.IEntityReadPolicies";
pub const ENTITY_SIDE_EFFECTS: &str = "global::Elephentity.Runtime.Mutation.IEntitySideEffects";
pub const ENTITY_VERIFIERS: &str = "global::Elephentity.Runtime.Verification.IEntityVerifiers";
pub const ENTITY_WRITE_POLICIES: &str = "global::Elephentity.Runtime.Policy.IEntityWritePolicies";
pub const HYDRATOR: &str = "global::Elephentity.Runtime.Query.IHydrator";
pub const HYDRATOR_REGISTRY: &str = "global::Elephentity.Runtime.Query.IHydratorRegistry";
pub const IDENTIFIER: &str = "global::Elephentity.Runtime.Identity.IIdentifier";
pub const JSON_ELEMENT: &str = "global::System.Text.Json.JsonElement";
pub const MANAGED: &str = "global::Elephentity.Runtime.Mutation.Managed";
pub const MUTABLE_MUTATION_CONTEXT: &str =
    "global::Elephentity.Runtime.Mutation.IMutableMutationContext";
pub const MUTATION_BUFFER: &str = "global::Elephentity.Runtime.Mutation.IMutationBuffer";
pub const MUTATION_CONTEXT: &str = "global::Elephentity.Runtime.Mutation.IMutationContext";
pub const NO_POLICIES: &str = "global::Elephentity.Runtime.Policy.NoPolicies";
pub const POLICY_DECISION: &str = "global::Elephentity.Runtime.Policy.PolicyDecision";
pub const POLICY_OUTCOME: &str = "global::Elephentity.Runtime.Policy.PolicyOutcome";
pub const PROCESSOR_REGISTRY: &str = "global::Elephentity.Runtime.Type.IProcessorRegistry";
pub const READ_GATE: &str = "global::Elephentity.Runtime.Policy.ReadGate";
pub const READ_PROCESSOR: &str = "global::Elephentity.Runtime.Type.IReadProcessor";
pub const RECORD: &str = "global::Elephentity.Runtime.Storage.Record";
pub const RUNTIME: &str = "global::Elephentity.Runtime.Gateway.Runtime";
pub const SIDE_EFFECT_EVENT: &str = "global::Elephentity.Runtime.SideEffect.SideEffectEvent";
pub const SIDE_EFFECT_PHASE: &str = "global::Elephentity.Runtime.SideEffect.SideEffectPhase";
pub const UNIT_OF_WORK: &str = "global::Elephentity.Runtime.UnitOfWork.UnitOfWork";
pub const UNIT_OF_WORK_FACTORY: &str = "global::Elephentity.Runtime.Gateway.UnitOfWorkFactory";
pub const VALUE_DECODER: &str = "global::Elephentity.Runtime.Query.ValueDecoder";
pub const VALUE_TASK: &str = "global::System.Threading.Tasks.ValueTask";
pub const VERIFICATION: &str = "global::Elephentity.Runtime.Verification.Verification";
pub const VIEWER: &str = "global::Elephentity.Runtime.Policy.IViewer";
pub const VIEWER_PROVIDER: &str = "global::Elephentity.Runtime.Policy.IViewerProvider";
pub const VIOLATION: &str = "global::Elephentity.Runtime.Verification.Violation";
pub const WRITE_CONTEXT: &str = "global::Elephentity.Runtime.Policy.IWriteContext";
pub const WRITE_GATE: &str = "global::Elephentity.Runtime.Policy.WriteGate";
pub const WRITE_OPERATION: &str = "global::Elephentity.Runtime.Policy.WriteOperation";
pub const WRITE_PROCESSOR: &str = "global::Elephentity.Runtime.Type.IWriteProcessor";

pub const READ_ONLY_LIST: &str = "global::System.Collections.Generic.IReadOnlyList";
pub const READ_ONLY_DICTIONARY: &str = "global::System.Collections.Generic.IReadOnlyDictionary";
pub const DICTIONARY: &str = "global::System.Collections.Generic.Dictionary";
pub const INVALID_OPERATION: &str = "global::System.InvalidOperationException";
pub const ARGUMENT_EXCEPTION: &str = "global::System.ArgumentException";
pub const SERVICE_PROVIDER: &str = "global::System.IServiceProvider";

pub const SERVICE_PROVIDER_EXTENSIONS: &str =
    "global::Microsoft.Extensions.DependencyInjection.ServiceProviderServiceExtensions";
