<?php
declare(strict_types=1);

namespace Nicode\FormStudio\Domain;

final class LayoutLimits
{
    /** Number of nested containers; a leaf can sit inside the last container. */
    public const MAX_CONTAINER_DEPTH = 64;
}
