<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Inspector\TagWriter;

/**
 * TagWriter for the unit suite, where WP_HTML_Tag_Processor is not loaded:
 * the first `<name` past comments gets the attributes, escaped, right after
 * its name. Enough for the block markup the tests render.
 */
final class FakeTagWriter implements TagWriter
{
    public function write(string $html, array $attributes, string $unless): string
    {
        $stripped = (string) preg_replace_callback('/<!--.*?-->/s', static fn (array $m): string => str_repeat(' ', strlen($m[0])), $html);

        if (preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)([^>]*)>/', $stripped, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }

        if (preg_match('/\s' . preg_quote($unless, '/') . '=/', $match[2][0]) === 1) {
            return $html;
        }

        $at = $match[1][1] + strlen($match[1][0]);
        $added = '';

        foreach ($attributes as $name => $value) {
            $added .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }

        return substr($html, 0, $at) . $added . substr($html, $at);
    }
}
