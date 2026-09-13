<?php

declare(strict_types=1);

namespace Eleph\Runtime\Policy;

final readonly class AnonymousViewerProvider implements ViewerProvider
{
    public function viewer(): Viewer
    {
        return new AnonymousViewer();
    }
}
