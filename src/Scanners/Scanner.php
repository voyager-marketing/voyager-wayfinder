<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Manifest\Section;

/**
 * Reads one part of the site into one manifest section.
 *
 * A scanner must be deterministic (same site state, same section) and must
 * not call an LLM. Take WordPress lookups as injectable callables so the unit
 * suite can run without a database.
 */
interface Scanner
{
    /**
     * The section this scanner produces, e.g. `site`. Unique across scanners.
     */
    public function section(): string;

    public function scan(): Section;
}
