namespace Elephentity.Runtime.Storage;

/// <summary>
/// What happens to the rows that depend on one being deleted.
/// </summary>
/// <remarks>
/// "Depend" means holding the foreign key, which is the same rule SQL uses. For
/// <c>Post.comments</c> the comments hold the post's id, so deleting a Post applies this policy to
/// the comments. Mirrors the spec's <c>onDelete</c> rather than importing it: the runtime does not
/// depend on the compiler, and generated code maps between them.
/// </remarks>
public enum DeletionPolicy
{
    /// <summary>Refuse the delete while anything still depends on the row.</summary>
    Restrict,

    /// <summary>Delete the dependents too, applying their own policies in turn.</summary>
    Cascade,

    /// <summary>Keep the dependents and clear their reference.</summary>
    Nullify,
}

/// <summary>The wire spelling of a <see cref="DeletionPolicy" />.</summary>
public static class DeletionPolicyExtensions
{
    /// <summary>The spelling used in the spec: <c>restrict</c>, <c>cascade</c> or <c>nullify</c>.</summary>
    /// <param name="policy">The policy.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this DeletionPolicy policy) => policy switch
    {
        DeletionPolicy.Restrict => "restrict",
        DeletionPolicy.Cascade => "cascade",
        DeletionPolicy.Nullify => "nullify",
        _ => throw new ArgumentOutOfRangeException(nameof(policy)),
    };

    /// <summary>The policy a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The policy.</returns>
    /// <exception cref="ArgumentException">The name is not a policy.</exception>
    public static DeletionPolicy FromWire(string value) => value switch
    {
        "restrict" => DeletionPolicy.Restrict,
        "cascade" => DeletionPolicy.Cascade,
        "nullify" => DeletionPolicy.Nullify,
        _ => throw new ArgumentException($"\"{value}\" is not a deletion policy.", nameof(value)),
    };
}
