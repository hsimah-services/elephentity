using System.Buffers;
using System.Text;
using System.Text.Json;
using Elephentity.Runtime.Catalogue;
using Elephentity.Runtime.Conformance;

namespace Elephentity.Runtime.Testing;

/// <summary>
/// Gate four for C#: proves the generated code and the application agree.
/// </summary>
/// <remarks>
/// <para>
/// <c>generate --check</c> asks whether a tree matches the spec; this asks whether the tree is <em>coherent</em>
/// once the application has supplied what the spec left it. It resolves every contract the generated catalogue
/// lists (<see cref="IEntityCatalogue.Contracts" />) from the application's <see cref="IServiceProvider" />,
/// then runs every <see cref="IVerifier" /> registered there (integrations ship them), and reports
/// <see cref="Signal" />s. A contract nothing implements is an error that names the interface, because that is
/// the line a developer has to write next.
/// </para>
/// <para>
/// Run it as a test (<see cref="AssertConforms" />) or as the checker <c>eleph check</c> invokes: a test project
/// whose entry point calls <see cref="TryRunConsole" /> and answers <c>-- eleph-check</c> with the JSON lines
/// <c>eleph</c> reads, one <c>{"severity", "message"}</c> per signal, and exit code 1 if any is an error.
/// </para>
/// </remarks>
public sealed class ContractCheck
{
    /// <summary>The argument that makes an application's entry point run the check and exit.</summary>
    public const string Argument = "eleph-check";

    private readonly IEntityCatalogue catalogue;
    private readonly IServiceProvider services;
    private readonly Func<string, string> classFor;

    /// <summary>A check of <paramref name="catalogue" /> against what <paramref name="services" /> provides.</summary>
    /// <param name="catalogue">The generated catalogue.</param>
    /// <param name="services">The application's container, built as it builds it at run time.</param>
    /// <param name="classFor">Maps a spec entity name to its class name for verifiers; the name itself when omitted.</param>
    public ContractCheck(IEntityCatalogue catalogue, IServiceProvider services, Func<string, string>? classFor = null)
    {
        this.catalogue = catalogue;
        this.services = services;
        this.classFor = classFor ?? (entity => entity);
    }

    /// <summary>Runs the check.</summary>
    /// <remarks>
    /// Always runs to the end. A contract whose implementation is registered but cannot be built (a constructor
    /// dependency nobody registered is the usual way) throws when it is resolved, and a verifier can throw too;
    /// each becomes an error signal naming it, so one problem never hides the rest. Only cancellation escapes.
    /// </remarks>
    /// <returns>Every signal found, contracts first, then the verifiers' in registration order.</returns>
    public IReadOnlyList<Signal> Run()
    {
        var signals = new List<Signal>();
        var contracts = catalogue.Contracts();

        foreach (var contract in contracts)
        {
            try
            {
                if (services.GetService(contract) is null)
                {
                    signals.Add(new Signal(
                        Severity.Error,
                        $"{contract.FullName} has no implementation registered. The generated code requires one; implement it and register it."));
                }
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                signals.Add(new Signal(
                    Severity.Error,
                    $"{contract.FullName} is registered but its implementation cannot be built: {Describe(exception)}"));
            }
        }

        if (signals.Count == 0)
        {
            signals.Add(new Signal(Severity.Info, $"{contracts.Count} generated contract(s) resolve."));
        }

        foreach (var verifier in Verifiers(signals))
        {
            try
            {
                signals.AddRange(verifier.Verify(classFor));
            }
            catch (Exception exception) when (exception is not OperationCanceledException)
            {
                signals.Add(new Signal(
                    Severity.Error,
                    $"The verifier {verifier.GetType().FullName} failed instead of reporting: {Describe(exception)}"));
            }
        }

        return signals;
    }

    private List<IVerifier> Verifiers(List<Signal> signals)
    {
        try
        {
            return [.. services.GetService(typeof(IEnumerable<IVerifier>)) as IEnumerable<IVerifier> ?? []];
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            signals.Add(new Signal(
                Severity.Error,
                $"The registered verifiers cannot be built, so none were run: {Describe(exception)}"));

            return [];
        }
    }

    /// <summary>The exception's own message, and the first inner one, which for a failed construction is the cause.</summary>
    private static string Describe(Exception exception) =>
        exception.InnerException is { } inner && inner.Message != exception.Message
            ? $"{exception.Message} ({inner.Message})"
            : exception.Message;

    /// <summary>The check as an assertion for a test.</summary>
    /// <exception cref="ContractCheckException">Any signal is an error; its message lists them all.</exception>
    public void AssertConforms()
    {
        var errors = Run().Where(signal => signal.Severity == Severity.Error).ToList();

        if (errors.Count > 0)
        {
            throw new ContractCheckException(errors);
        }
    }

    /// <summary>
    /// If <paramref name="arguments" /> ask for the check, run it and say how to exit.
    /// </summary>
    /// <remarks>
    /// Call it first in the entry point of the application's test project:
    /// <c>if (ContractCheck.TryRunConsole(args, catalogue, services, Console.Out, out var exit)) return exit;</c>.
    /// </remarks>
    /// <param name="arguments">The program's arguments.</param>
    /// <param name="catalogue">The generated catalogue.</param>
    /// <param name="services">The application's container.</param>
    /// <param name="output">Where the JSON lines go.</param>
    /// <param name="exitCode">0, or 1 if any signal is an error; set only when the check ran.</param>
    /// <returns><see langword="true" /> when <paramref name="arguments" /> asked for the check and it ran.</returns>
    public static bool TryRunConsole(
        string[] arguments,
        IEntityCatalogue catalogue,
        IServiceProvider services,
        TextWriter output,
        out int exitCode)
    {
        if (!arguments.Contains(Argument, StringComparer.Ordinal))
        {
            exitCode = 0;

            return false;
        }

        var signals = new ContractCheck(catalogue, services).Run();

        foreach (var signal in signals)
        {
            output.WriteLine(ToJsonLine(signal));
        }

        exitCode = signals.Any(signal => signal.Severity == Severity.Error) ? 1 : 0;

        return true;
    }

    /// <summary>One signal in the format <c>eleph check</c> reads: <c>{"severity":"error","message":"..."}</c>.</summary>
    /// <param name="signal">The signal.</param>
    /// <returns>A single line of JSON.</returns>
    public static string ToJsonLine(Signal signal)
    {
        var buffer = new ArrayBufferWriter<byte>();

        using (var writer = new Utf8JsonWriter(buffer))
        {
            writer.WriteStartObject();
            writer.WriteString("severity", signal.Severity.ToWire());
            writer.WriteString("message", signal.Message);
            writer.WriteEndObject();
        }

        return Encoding.UTF8.GetString(buffer.WrittenSpan);
    }
}
