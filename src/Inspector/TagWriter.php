<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Inspector;

/**
 * Sets attributes on the first tag of a block's markup. Kept apart from
 * Annotator so the annotator runs without WordPress: in WordPress the
 * writer is TagProcessorWriter, and the unit suite passes its own.
 */
interface TagWriter
{
    /**
     * Sets each attribute on the first tag of `$html`, unless that tag
     * already carries `$unless` (a block nested inside it got there first
     * and says more). Markup with no tag comes back unchanged.
     *
     * @param array<string, string> $attributes Name => raw value; the writer escapes.
     */
    public function write(string $html, array $attributes, string $unless): string;
}
