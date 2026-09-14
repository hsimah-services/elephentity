<?php

declare(strict_types=1);

namespace Eleph\Runtime\Conformance;

/**
 * How seriously a verifier means a signal.
 *
 * Only Error fails `eleph check`. A verifier that cannot check something — a shape it
 * does not recognise, a feature it does not cover — says so at Warning or Info rather
 * than staying silent, which would look identical to a surface that conforms.
 */
enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
