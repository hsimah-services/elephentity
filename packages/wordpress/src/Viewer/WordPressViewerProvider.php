<?php

declare(strict_types=1);

namespace Eleph\WordPress\Viewer;

use Eleph\Runtime\Policy\Viewer;
use Eleph\Runtime\Policy\ViewerProvider;

final readonly class WordPressViewerProvider implements ViewerProvider
{
    public function viewer(): Viewer
    {
        return new WordPressViewer(wp_get_current_user());
    }
}
