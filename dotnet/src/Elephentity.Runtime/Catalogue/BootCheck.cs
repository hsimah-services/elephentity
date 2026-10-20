using Elephentity.Runtime.Boot;

namespace Elephentity.Runtime.Catalogue;

/// <summary>
/// Refuses to start while any generated interface has no implementation.
/// </summary>
/// <remarks>
/// The catalogue already knows every contract the spec produced, so this needs nothing from the application but
/// its service provider: no registration, no list to maintain, no chance of the list drifting from what was
/// generated. Boot time is the point. Later than generation, so codegen stays a pure function of the spec and
/// never scans source; earlier than the first call, so a missing handler cannot lurk until the wrong request
/// arrives.
/// </remarks>
public sealed class BootCheck
{
    private readonly IEntityCatalogue catalogue;
    private readonly IServiceProvider services;

    /// <summary>A check of <paramref name="catalogue" /> against <paramref name="services" />.</summary>
    /// <param name="catalogue">Knows every contract.</param>
    /// <param name="services">What the application registered.</param>
    public BootCheck(IEntityCatalogue catalogue, IServiceProvider services)
    {
        this.catalogue = catalogue;
        this.services = services;
    }

    /// <summary>Checks that every contract resolves.</summary>
    /// <exception cref="MissingImplementationsException">
    /// Naming every one at once, because fixing them one restart at a time is needlessly slow.
    /// </exception>
    public void Run()
    {
        var missing = catalogue.Contracts().Where(contract => services.GetService(contract) is null).ToList();

        if (missing.Count > 0)
        {
            throw new MissingImplementationsException(missing);
        }
    }
}
