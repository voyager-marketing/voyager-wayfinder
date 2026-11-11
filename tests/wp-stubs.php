<?php

/**
 * Minimal WordPress function stubs for the unit suite. Calls that register
 * something are recorded in WpCalls so a test can assert on them.
 *
 * @package Voyager\Wayfinder
 */

declare(strict_types=1);

use Voyager\Wayfinder\Tests\Support\WpCalls;

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpCalls::$actions[] = [
            'hook'     => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'args'     => $acceptedArgs,
        ];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        WpCalls::$filters[] = [
            'hook'  => $hook,
            'value' => $value,
        ];

        return isset(WpCalls::$filterCallbacks[$hook])
            ? (WpCalls::$filterCallbacks[$hook])($value)
            : $value;
    }
}

if (!function_exists('_doing_it_wrong')) {
    function _doing_it_wrong(string $function, string $message, string $version): void
    {
        WpCalls::$doingItWrong[] = [
            'function' => $function,
            'message'  => $message,
            'version'  => $version,
        ];
    }
}

if (!function_exists('add_menu_page')) {
    function add_menu_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        ?callable $callback = null,
        string $iconUrl = '',
        int|float|null $position = null
    ): string {
        WpCalls::$menuPages[] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'slug'       => $menuSlug,
            'callback'   => $callback,
            'icon'       => $iconUrl,
        ];

        return 'toplevel_page_' . $menuSlug;
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_textarea')) {
    function esc_textarea(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    /**
     * Enough of core's esc_url() for tests: anything but an http(s),
     * root-relative or fragment URL comes back empty, and the rest is
     * escaped for an attribute.
     */
    function esc_url(string $url): string
    {
        if (preg_match('~^(https?://|/|#)~', $url) !== 1) {
            return '';
        }

        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_strip_all_tags')) {
    /** Core's wp_strip_all_tags(): script and style contents go too, then trim. */
    function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
    {
        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', $text);
        $text = strip_tags($text);

        if ($removeBreaks) {
            $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
        }

        return trim($text);
    }
}

if (!function_exists('wp_parse_url')) {
    /** Core's wp_parse_url(): parse_url() that also reads root-relative and protocol-relative URLs. */
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        $toUnset = [];

        if (str_starts_with($url, '//')) {
            $toUnset[] = 'scheme';
            $url = 'placeholder:' . $url;
        } elseif (str_starts_with($url, '/')) {
            $toUnset = ['scheme', 'host'];
            $url = 'placeholder://placeholder' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        foreach ($toUnset as $key) {
            unset($parts[$key]);
        }

        if ($component === -1) {
            return $parts;
        }

        $keys = [
            PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user',
            PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment',
        ];

        return $parts[$keys[$component] ?? ''] ?? null;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        WpCalls::$hooks[$hook][] = ['callback' => $callback, 'priority' => $priority];

        return true;
    }
}

if (!function_exists('remove_filter')) {
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        foreach (WpCalls::$hooks[$hook] ?? [] as $i => $entry) {
            if ($entry['callback'] === $callback && $entry['priority'] === $priority) {
                unset(WpCalls::$hooks[$hook][$i]);

                return true;
            }
        }

        return false;
    }
}

if (!function_exists('get_option')) {
    /**
     * Runs `pre_option` callbacks added with the add_filter() stub first, as
     * core does, and returns what one of them short-circuits with.
     */
    function get_option(string $option, mixed $default = false): mixed
    {
        $pre = false;

        foreach (WpCalls::$hooks['pre_option'] ?? [] as $entry) {
            $pre = ($entry['callback'])($pre, $option, $default);
        }

        if ($pre !== false) {
            return $pre;
        }

        return array_key_exists($option, WpCalls::$options) ? WpCalls::$options[$option] : $default;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        WpCalls::$deleted[] = 'option:' . $option;
        unset(WpCalls::$options[$option]);

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        WpCalls::$deleted[] = 'transient:' . $transient;

        return true;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return WpCalls::$multisite;
    }
}

if (!function_exists('get_sites')) {
    /**
     * @param array<string, mixed> $args
     * @return list<int>
     */
    function get_sites(array $args = []): array
    {
        return WpCalls::$sites;
    }
}

if (!function_exists('switch_to_blog')) {
    /** Moves `$wpdb->prefix` the way core does: `wp_` for site 1, `wp_<id>_` for the rest. */
    function switch_to_blog(int $siteId): bool
    {
        WpCalls::$blogSwitches[] = 'switch:' . $siteId;

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = $siteId === 1 ? 'wp_' : 'wp_' . $siteId . '_';
        }

        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        WpCalls::$blogSwitches[] = 'restore';

        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = 'wp_';
        }

        return true;
    }
}

if (!function_exists('get_post_meta')) {
    /**
     * Runs `get_post_metadata` callbacks added with the add_filter() stub, as
     * core does, and stores no meta of its own.
     */
    function get_post_meta(int $postId, string $key = '', bool $single = false): mixed
    {
        $value = null;

        foreach (WpCalls::$hooks['get_post_metadata'] ?? [] as $entry) {
            $value = ($entry['callback'])($value, $postId, $key, $single);
        }

        return $value ?? ($single ? '' : []);
    }
}

if (!function_exists('wp_validate_boolean')) {
    /**
     * Same rule as core: the string "false" is false, anything else is cast.
     */
    function wp_validate_boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) && strtolower($value) === 'false') {
            return false;
        }

        return (bool) $value;
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, mixed ...$args): bool
    {
        return in_array($capability, WpCalls::$capabilities, true);
    }
}

if (!function_exists('wp_register_ability_category')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability_category(string $slug, array $args): ?object
    {
        WpCalls::$abilityCategories[$slug] = $args;

        return null;
    }
}

if (!function_exists('wp_register_ability')) {
    /**
     * @param array<string, mixed> $args
     */
    function wp_register_ability(string $name, array $args): ?object
    {
        WpCalls::$abilities[$name] = $args;

        return null;
    }
}

if (!class_exists('WP_Error')) {
    /**
     * Just enough of WP_Error for code that returns one.
     */
    class WP_Error // phpcs:ignore PSR1.Classes.ClassDeclaration.MissingNamespace, Squiz.Classes.ValidClassName.NotCamelCaps
    {
        public function __construct(private string $code = '', private string $message = '', private mixed $data = '')
        {
        }

        public function get_error_code(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        {
            return $this->code;
        }

        public function get_error_message(): string // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        {
            return $this->message;
        }

        public function get_error_data(): mixed // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
        {
            return $this->data;
        }
    }
}

/**
 * Minimal parse_blocks() for the unit suite, ported from voyager-orbit
 * tests/wp-stubs.php @ abe7d26. Not a full WordPress parser: it handles JSON
 * attrs, self-closing blocks, nested blocks (same-name nesting by depth) and
 * leaf innerHTML, which is the shape the fixture theme's markup needs. One
 * change from the source: the nested-name match requires the space after the
 * name, so `wp:group` no longer counts a `wp:group-x` as its own nesting.
 */
if (!function_exists('parse_blocks')) {
    /**
     * @return list<array{blockName: string|null, attrs: array<string, mixed>, innerBlocks: list<array<string, mixed>>, innerHTML: string, innerContent: list<string>}>
     */
    function parse_blocks(string $markup): array
    {
        $blocks = [];
        $len = strlen($markup);
        $pos = 0;

        $freeform = static fn (string $html): array => [
            'blockName'    => null,
            'attrs'        => [],
            'innerBlocks'  => [],
            'innerHTML'    => $html,
            'innerContent' => [$html],
        ];

        while ($pos < $len) {
            $openStart = strpos($markup, '<!-- wp:', $pos);

            if ($openStart === false) {
                $tail = substr($markup, $pos);
                if (trim($tail) !== '') {
                    $blocks[] = $freeform($tail);
                }
                break;
            }

            if ($openStart > $pos) {
                $pre = substr($markup, $pos, $openStart - $pos);
                if (trim($pre) !== '') {
                    $blocks[] = $freeform($pre);
                }
            }

            $openEnd = strpos($markup, '-->', $openStart);
            if ($openEnd === false) {
                break;
            }

            $openTag = substr($markup, $openStart, $openEnd + 3 - $openStart);

            if (preg_match('#^<!--\s*wp:([a-z0-9/_-]+)\s*(\{.*\})?\s*(/)?-->$#s', $openTag, $m) !== 1) {
                $pos = $openEnd + 3;
                continue;
            }

            $blockName = str_contains($m[1], '/') ? $m[1] : 'core/' . $m[1];
            $decoded = isset($m[2]) && $m[2] !== '' ? json_decode($m[2], true) : null;
            $attrs = is_array($decoded) ? $decoded : [];

            if (isset($m[3]) && $m[3] !== '') {
                $blocks[] = [
                    'blockName'    => $blockName,
                    'attrs'        => $attrs,
                    'innerBlocks'  => [],
                    'innerHTML'    => '',
                    'innerContent' => [],
                ];
                $pos = $openEnd + 3;
                continue;
            }

            $shortName = $m[1];
            $depth = 1;
            $searchPos = $openEnd + 3;

            while ($depth > 0 && $searchPos < $len) {
                $nextOpen = strpos($markup, '<!-- wp:' . $shortName . ' ', $searchPos);
                $nextClose = strpos($markup, '<!-- /wp:' . $shortName . ' ', $searchPos);

                if ($nextClose === false) {
                    return $blocks;
                }

                if ($nextOpen !== false && $nextOpen < $nextClose) {
                    $depth++;
                    $advance = strpos($markup, '-->', $nextOpen);
                    $searchPos = $advance === false ? $len : $advance + 3;
                    continue;
                }

                $depth--;
                $closeEnd = strpos($markup, '-->', $nextClose);
                if ($closeEnd === false) {
                    return $blocks;
                }

                if ($depth === 0) {
                    $inner = substr($markup, $openEnd + 3, $nextClose - ($openEnd + 3));
                    $named = array_values(array_filter(
                        parse_blocks($inner),
                        static fn (array $block): bool => $block['blockName'] !== null
                    ));

                    $blocks[] = [
                        'blockName'    => $blockName,
                        'attrs'        => $attrs,
                        'innerBlocks'  => $named,
                        'innerHTML'    => $named !== [] ? '' : $inner,
                        'innerContent' => $named !== [] ? [] : [$inner],
                    ];
                    $pos = $closeEnd + 3;
                    continue 2;
                }

                $searchPos = $closeEnd + 3;
            }

            break;
        }

        return $blocks;
    }
}

if (!function_exists('rest_get_server')) {
    /**
     * Counts reads. ScannerRegistry reads the server before any scanner.
     */
    function rest_get_server(): object
    {
        \Voyager\Wayfinder\Tests\Support\WpCalls::$restServerReads++;

        return new stdClass();
    }
}

if (!function_exists('get_bloginfo')) {
    /**
     * The site name from the `blogname` option, as core reads it. The language
     * is fixed: the unit suite runs in English.
     */
    function get_bloginfo(string $show = ''): string
    {
        return match ($show) {
            'name'     => (string) get_option('blogname', ''),
            'language' => 'en-US',
            default    => '',
        };
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}
