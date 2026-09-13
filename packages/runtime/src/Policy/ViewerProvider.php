<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

interface ViewerProvider
{
    public function viewer(): Viewer;
}
