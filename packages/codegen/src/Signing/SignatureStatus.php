<?php

declare(strict_types=1);

namespace PheFr\Codegen\Signing;

enum SignatureStatus
{
    case Valid;

    /** The file exists and is signed, but its content no longer matches the digest. */
    case Tampered;

    /** No PheFr header, or one the current format cannot read. */
    case Unsigned;

    case Missing;
}
