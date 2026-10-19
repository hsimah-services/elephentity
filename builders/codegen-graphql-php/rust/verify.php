namespace Eleph\GraphQL\Generated;

use Closure;
use Eleph\Runtime\Conformance\Severity;
use Eleph\Runtime\Conformance\Signal;
use Eleph\Runtime\Conformance\Verifier;
use Eleph\GraphQL\Manifest\Manifest;

$manifest = require __DIR__ . '/graphql-manifest.php';
assert($manifest instanceof Manifest);

return new class($manifest) implements Verifier
{
    public function __construct(private Manifest $manifest)
    {
    }

    public function verify(Closure $classFor): array
    {
        $signals = [];

        foreach ($this->manifest->objects as $object) {
            $class = $classFor($object->entity);

            if (!class_exists($class)) {
                $signals[] = new Signal(Severity::Error, sprintf(
                    '%s is exposed as a GraphQL type but %s does not exist. Run `eleph generate`.',
                    $object->entity,
                    $class,
                ));

                continue;
            }

            foreach ($object->fields as $field) {
                if (!method_exists($class, $field->accessor)) {
                    $signals[] = new Signal(Severity::Error, sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $field->name,
                        $class,
                        $field->accessor,
                    ));
                }
            }

            foreach ($object->connections as $connection) {
                if (!method_exists($class, $connection->accessor)) {
                    $signals[] = new Signal(Severity::Error, sprintf(
                        '%s.%s resolves via %s::%s(), which does not exist.',
                        $object->name,
                        $connection->name,
                        $class,
                        $connection->accessor,
                    ));
                }
            }
        }

        return $signals;
    }
};
