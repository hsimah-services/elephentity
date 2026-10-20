namespace Elephentity.Runtime.SideEffect;

/// <summary>What happened to a row that a side effect reacts to.</summary>
public enum SideEffectEvent
{
    /// <summary>A row was created.</summary>
    Create,

    /// <summary>A row was changed.</summary>
    Update,

    /// <summary>A row was removed.</summary>
    Delete,
}

/// <summary>The wire spelling of a <see cref="SideEffectEvent" />.</summary>
public static class SideEffectEventExtensions
{
    /// <summary>The spelling used in the spec: <c>create</c>, <c>update</c> or <c>delete</c>.</summary>
    /// <param name="sideEffectEvent">The event.</param>
    /// <returns>Its wire name.</returns>
    public static string ToWire(this SideEffectEvent sideEffectEvent) => sideEffectEvent switch
    {
        SideEffectEvent.Create => "create",
        SideEffectEvent.Update => "update",
        SideEffectEvent.Delete => "delete",
        _ => throw new ArgumentOutOfRangeException(nameof(sideEffectEvent)),
    };

    /// <summary>The event a wire name spells.</summary>
    /// <param name="value">The wire name.</param>
    /// <returns>The event.</returns>
    /// <exception cref="ArgumentException">The name is not an event.</exception>
    public static SideEffectEvent FromWire(string value) => value switch
    {
        "create" => SideEffectEvent.Create,
        "update" => SideEffectEvent.Update,
        "delete" => SideEffectEvent.Delete,
        _ => throw new ArgumentException($"\"{value}\" is not a side-effect event.", nameof(value)),
    };
}
