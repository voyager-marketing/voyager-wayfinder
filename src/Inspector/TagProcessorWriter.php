<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Inspector;

use WP_HTML_Tag_Processor;

/**
 * The TagWriter WordPress uses: core's HTML tag processor, which finds the
 * first real tag (past comments and text) and escapes the values it sets.
 */
final class TagProcessorWriter implements TagWriter
{
    public function write(string $html, array $attributes, string $unless): string
    {
        $tags = new WP_HTML_Tag_Processor($html);

        if (!$tags->next_tag() || $tags->get_attribute($unless) !== null) {
            return $html;
        }

        foreach ($attributes as $name => $value) {
            $tags->set_attribute($name, $value);
        }

        return $tags->get_updated_html();
    }
}
