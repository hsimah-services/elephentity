using System.Text;
using GraphQL.Types;

namespace Elephentity.GraphQL;

/// <summary>
/// Prints a schema as SDL, sorted the way <c>tsquid-schema fetch</c> normalises it.
/// </summary>
/// <remarks>
/// graphql-js <c>printSchema(lexicographicSortSchema(schema))</c>, reproduced here so the text a C# server prints is byte for byte
/// the text the PHP server's schema prints after the same normalisation: that is what a client's committed
/// <c>schema.graphql</c> is compared with. Types, fields, arguments, enum values and interfaces are sorted by name; a description
/// is a block string unless it cannot be one; a blank line separates a described field from the one before it.
/// </remarks>
public static class ElephentitySchemaPrinter
{
    private static readonly HashSet<string> Specified = ["String", "Int", "Float", "Boolean", "ID"];

    /// <summary>The SDL of <paramref name="schema" />, with one trailing newline.</summary>
    /// <param name="schema">The schema.</param>
    /// <returns>The normalised SDL.</returns>
    public static string Print(ISchema schema)
    {
        ArgumentNullException.ThrowIfNull(schema);

        schema.Initialize();

        var blocks = new List<string>();

        if (SchemaDefinition(schema) is { } definition)
        {
            blocks.Add(definition);
        }

        foreach (var type in schema.AllTypes.Where(type => !type.Name.StartsWith("__", StringComparison.Ordinal) && !Specified.Contains(type.Name)).OrderBy(type => type.Name, Natural))
        {
            blocks.Add(Type(type));
        }

        return string.Join("\n\n", blocks) + "\n";
    }

    /// <summary>Compares the schema with SDL an application committed, for a "client schema has drifted" check.</summary>
    /// <param name="schema">The schema the server builds.</param>
    /// <param name="committed">The committed SDL (line endings are ignored).</param>
    /// <returns><see langword="null" /> when they match; otherwise the first line that differs, ready to print.</returns>
    public static string? Check(ISchema schema, string committed)
    {
        ArgumentNullException.ThrowIfNull(committed);

        var expected = Print(schema).Split('\n');
        var actual = committed.ReplaceLineEndings("\n").Split('\n');

        for (var line = 0; line < Math.Max(expected.Length, actual.Length); line++)
        {
            var server = line < expected.Length ? expected[line] : "<end of schema>";
            var client = line < actual.Length ? actual[line] : "<end of file>";

            if (server != client)
            {
                return $"The schema differs from the committed one at line {line + 1}.\n  server:    {server}\n  committed: {client}";
            }
        }

        return null;
    }

    private static string? SchemaDefinition(ISchema schema)
    {
        var query = schema.Query?.Name;
        var mutation = schema.Mutation?.Name;
        var subscription = schema.Subscription?.Name;

        if (query is "Query" or null && mutation is "Mutation" or null && subscription is "Subscription" or null)
        {
            return null;
        }

        var lines = new List<string> { "schema {" };

        if (query is not null)
        {
            lines.Add($"  query: {query}");
        }

        if (mutation is not null)
        {
            lines.Add($"  mutation: {mutation}");
        }

        if (subscription is not null)
        {
            lines.Add($"  subscription: {subscription}");
        }

        lines.Add("}");

        return string.Join("\n", lines);
    }

    private static string Type(IGraphType type) => type switch
    {
        EnumerationGraphType enumeration => Description(type, string.Empty, true) + "enum " + type.Name + Block(enumeration.Values.OrderBy(value => value.Name, Natural).Select((value, index) =>
            Description(value, "  ", index == 0) + "  " + value.Name)),
        IInterfaceGraphType contract => Description(type, string.Empty, true) + "interface " + type.Name + Block(Fields(contract)),
        IObjectGraphType obj => Description(type, string.Empty, true) + "type " + type.Name + Implements(obj) + Block(Fields(obj)),
        IInputObjectGraphType input => Description(type, string.Empty, true) + "input " + type.Name + Block(Fields(input)),
        ScalarGraphType scalar => Description(type, string.Empty, true) + "scalar " + scalar.Name,
        _ => throw new InvalidOperationException($"Cannot print {type.GetType().Name} {type.Name}."),
    };

    private static string Implements(IObjectGraphType type)
    {
        var names = type.ResolvedInterfaces.Select(contract => contract.Name).OrderBy(name => name, Natural).ToList();

        return names.Count == 0 ? string.Empty : " implements " + string.Join(" & ", names);
    }

    private static string Block(IEnumerable<string> lines)
    {
        var list = lines.ToList();

        return list.Count == 0 ? string.Empty : " {\n" + string.Join("\n", list) + "\n}";
    }

    private static IEnumerable<string> Fields(IComplexGraphType type) =>
        type.Fields.OrderBy(field => field.Name, Natural).Select((field, index) =>
            Description(field, "  ", index == 0) + "  " + field.Name + Arguments(field) + ": " + Show(field.ResolvedType!));

    private static string Arguments(FieldType field)
    {
        var arguments = (field.Arguments ?? []).OrderBy(argument => argument.Name, Natural).ToList();

        if (arguments.Count == 0)
        {
            return string.Empty;
        }

        if (arguments.All(argument => string.IsNullOrEmpty(argument.Description)))
        {
            return "(" + string.Join(", ", arguments.Select(Argument)) + ")";
        }

        return "(\n" + string.Join("\n", arguments.Select((argument, index) => Description(argument, "    ", index == 0) + "    " + Argument(argument))) + "\n  )";
    }

    private static string Argument(QueryArgument argument) => argument.Name + ": " + Show(argument.ResolvedType!);

    private static string Show(IGraphType type) => type switch
    {
        NonNullGraphType required => Show(required.ResolvedType!) + "!",
        ListGraphType list => "[" + Show(list.ResolvedType!) + "]",
        _ => type.Name,
    };

    private static string Description(IProvideDescription definition, string indentation, bool firstInBlock)
    {
        if (definition.Description is not { } description)
        {
            return string.Empty;
        }

        var text = IsPrintableAsBlockString(description) ? BlockString(description) : QuotedString(description);
        var prefix = indentation.Length > 0 && !firstInBlock ? "\n" + indentation : indentation;

        return prefix + text.Replace("\n", "\n" + indentation, StringComparison.Ordinal) + "\n";
    }

    private static bool IsWhiteSpace(char c) => c is ' ' or '\t';

    // graphql-js isPrintableAsBlockString.
    private static bool IsPrintableAsBlockString(string value)
    {
        if (value.Length == 0)
        {
            return true;
        }

        var emptyLine = true;
        var hasIndent = false;
        var hasCommonIndent = true;
        var seenNonEmptyLine = false;

        foreach (var c in value)
        {
            switch (c)
            {
                case <= '\b' or '\v' or '\f' or (>= '\u000e' and <= '\u001f') or '\u007f' or '\r':
                    return false;
                case '\n':
                    if (emptyLine && !seenNonEmptyLine)
                    {
                        return false;
                    }

                    seenNonEmptyLine = true;
                    emptyLine = true;
                    hasIndent = false;

                    break;
                case '\t' or ' ':
                    hasIndent |= emptyLine;

                    break;
                default:
                    hasCommonIndent &= hasIndent;
                    emptyLine = false;

                    break;
            }
        }

        return !emptyLine && !(hasCommonIndent && seenNonEmptyLine);
    }

    // graphql-js printBlockString.
    private static string BlockString(string value)
    {
        var escaped = value.Replace("\"\"\"", "\\\"\"\"", StringComparison.Ordinal);
        var lines = escaped.ReplaceLineEndings("\n").Split('\n');
        var single = lines.Length == 1;
        var forceLeadingNewLine = lines.Length > 1 && lines.Skip(1).All(line => line.Length == 0 || IsWhiteSpace(line[0]));
        var trailingTripleQuotes = escaped.EndsWith("\\\"\"\"", StringComparison.Ordinal);
        var trailingQuote = value.EndsWith('"') && !trailingTripleQuotes;
        var trailingSlash = value.EndsWith('\\');
        var forceTrailingNewline = trailingQuote || trailingSlash;
        var multiple = !single || value.Length > 70 || forceTrailingNewline || forceLeadingNewLine || trailingTripleQuotes;
        var skipLeadingNewLine = single && value.Length > 0 && IsWhiteSpace(value[0]);
        var result = new StringBuilder();

        if ((multiple && !skipLeadingNewLine) || forceLeadingNewLine)
        {
            result.Append('\n');
        }

        result.Append(escaped);

        if (multiple || forceTrailingNewline)
        {
            result.Append('\n');
        }

        return "\"\"\"" + result + "\"\"\"";
    }

    private static string QuotedString(string value)
    {
        var result = new StringBuilder("\"");

        foreach (var c in value)
        {
            result.Append(c switch
            {
                '"' => "\\\"",
                '\\' => "\\\\",
                '\b' => "\\b",
                '\f' => "\\f",
                '\n' => "\\n",
                '\r' => "\\r",
                '\t' => "\\t",
                < ' ' or '\u007f' => $"\\u{(int)c:X4}",
                _ => c.ToString(),
            });
        }

        return result.Append('"').ToString();
    }

    private static readonly IComparer<string> Natural = Comparer<string>.Create(NaturalCompare);

    // graphql-js naturalCompare: character codes, with a run of digits compared as a number.
    private static int NaturalCompare(string? a, string? b)
    {
        a ??= string.Empty;
        b ??= string.Empty;

        int i = 0, j = 0;

        while (i < a.Length && j < b.Length)
        {
            if (char.IsAsciiDigit(a[i]) && char.IsAsciiDigit(b[j]))
            {
                var startA = i;
                var startB = j;

                while (i < a.Length && char.IsAsciiDigit(a[i]))
                {
                    i++;
                }

                while (j < b.Length && char.IsAsciiDigit(b[j]))
                {
                    j++;
                }

                var numberA = a[startA..i].TrimStart('0');
                var numberB = b[startB..j].TrimStart('0');
                var byLength = numberA.Length.CompareTo(numberB.Length);

                if (byLength != 0)
                {
                    return byLength;
                }

                var byDigits = string.CompareOrdinal(numberA, numberB);

                if (byDigits != 0)
                {
                    return byDigits;
                }
            }
            else
            {
                if (a[i] != b[j])
                {
                    return a[i].CompareTo(b[j]);
                }

                i++;
                j++;
            }
        }

        return (a.Length - i).CompareTo(b.Length - j);
    }
}
