<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;

/**
 * Templates and template parts of a classic (PHP) theme, read from its files
 * without running any of them.
 *
 * Templates are the PHP files in the theme root that core's template
 * hierarchy can load (`index.php`, `single.php`, `single-service.php`,
 * `page-contact.php`, ...) and the files at most one folder deep that declare
 * a `Template Name:` header, which WP_Theme::get_post_templates() finds the
 * same way. A child theme's file wins over its parent's, name by name.
 *
 * Parts are what a template includes through get_header(), get_footer() and
 * get_template_part(), found in the file's tokens. A part is followed into
 * the parts it includes in turn. An argument counts only as a string literal:
 * a dynamic name falls back to the base file (`content.php` for
 * `get_template_part('content', get_post_type())`), as core does when the
 * named file is missing. Calls with a dynamic slug are skipped.
 *
 * Files are found with core's locate_template() on a live site, so a name is
 * tried in the child theme, then the parent, before the next name. Paths
 * leave this class relative to wp-content (SourceFile), never absolute.
 *
 * The same token pass records what each file reads and the links it hard
 * codes, from calls with a literal first argument: get_field(), the_field()
 * and have_rows() read an ACF field (`acf:<key>`, or `acf_option:<key>` when
 * the second argument is 'option' or 'options'), get_option() reads an
 * option (`option:<name>`), and home_url('/path/') links to a path.
 *
 * It also records what a template prints of the post it is served for (see
 * showsPost()), followed through every part it includes, so a landing page
 * template that builds the page from fields and never calls the_content()
 * says so.
 *
 * @phpstan-import-type TemplateRow from LayoutSource
 * @phpstan-type Theme array{slug: string, dir: string}
 * @phpstan-type Found array{slug: string, theme: string, path: string}
 * @phpstan-type Call array{function: string, args: list<string|null>}
 * @phpstan-type Inclusion array{slug: string, function: string, found: Found|null}
 */
final class ClassicTheme
{
    /** Root files core's template hierarchy loads, by name. */
    private const HIERARCHY = '/^(?:index|404|search|front-page|home|privacy-policy|singular|date|attachment|(?:single|page|archive|category|tag|taxonomy|author|embed)(?:-.+)?)\.php$/';

    /** The calls that include a part. */
    private const INCLUDES = ['get_header', 'get_footer', 'get_template_part'];

    /** Calls whose first argument names the ACF field they read. */
    private const FIELD_READS = ['get_field', 'the_field', 'have_rows'];

    /** The second argument that makes an ACF read an options-page field. */
    private const OPTION_CONTEXTS = ['option', 'options'];

    /** The call whose first argument is a path the file links to. */
    private const LINK = 'home_url';

    /** Calls that print part of the current post, whatever their arguments, by what they print. */
    private const POST_OUTPUT = [
        'the_content'       => 'content',
        'the_excerpt'       => 'excerpt',
        'the_title'         => 'title',
        'single_post_title' => 'title',
    ];

    /** Calls that return part of the current post when called with no arguments; an argument names another post. */
    private const POST_GETTERS = [
        'get_the_content' => 'content',
        'get_the_excerpt' => 'excerpt',
        'get_the_title'   => 'title',
    ];

    /** Folders WP_Theme::scandir() skips. */
    private const SKIPPED_FOLDERS = ['CVS', 'bower_components', 'node_modules', 'vendor'];

    /** @var list<Theme> Child theme first. */
    private array $themes;

    /** @var Closure(list<string>): ?string */
    private Closure $locate;

    /** @var list<array{0: string, 1: string}>|null */
    private ?array $roots;

    /** @var list<array{found: Found, title: string, includes: list<Inclusion>, reads: list<string>, links: list<string>}>|null */
    private ?array $templateFiles = null;

    /** @var array<string, list<string>> File path => what it and its parts print of the post, filled by postOutput(). */
    private array $postOutput = [];

    /**
     * @param list<Theme>                              $themes The active theme first, then its parent when it is a child theme.
     * @param (callable(list<string>): ?string)|null   $locate Absolute path of the file WordPress loads for these names, or null.
     *                                                         Defaults to locateFile().
     * @param list<array{0: string, 1: string}>|null   $roots  For SourceFile::relative(). Defaults to the running site's.
     */
    public function __construct(array $themes, ?callable $locate = null, ?array $roots = null)
    {
        $this->themes = $themes;
        $this->locate = $locate !== null ? Closure::fromCallable($locate) : $this->locateFile(...);
        $this->roots = $roots;
    }

    /**
     * Classic support switches on when the active theme is not a block
     * theme. Block themes keep the block template path.
     */
    public static function isActive(): bool
    {
        return function_exists('wp_is_block_theme') && !wp_is_block_theme();
    }

    /**
     * A hybrid theme: a classic theme that supports block templates. Its PHP
     * templates are read as on any classic theme, and its Site Editor
     * templates and parts beside them (see blockKey()).
     */
    public static function isHybrid(): bool
    {
        return self::isActive() && function_exists('current_theme_supports') && current_theme_supports('block-templates');
    }

    /**
     * The slug a hybrid theme's block template or part takes in its id, the
     * `<theme>//<slug>` id core gives it (`tpl:acme//page`), so it never
     * meets a PHP file's `tpl:page`.
     */
    public static function blockKey(string $theme, string $slug): string
    {
        return $theme . '//' . $slug;
    }

    /**
     * The active theme, and its parent when it is a child theme, with core's
     * locate_template() finding files.
     */
    public static function active(): self
    {
        $themes = [['slug' => get_stylesheet(), 'dir' => get_stylesheet_directory()]];

        if (get_template() !== get_stylesheet()) {
            $themes[] = ['slug' => get_template(), 'dir' => get_template_directory()];
        }

        return new self($themes, static function (array $names): ?string {
            $path = locate_template($names);

            return $path !== '' ? $path : null;
        });
    }

    /**
     * The template WordPress loads for these candidates, most specific first,
     * as a slug (`single`, `templates/full-width`), or null when the theme
     * has none of them.
     *
     * @param list<string> $candidates Template slugs without `.php`.
     */
    public function resolve(array $candidates): ?string
    {
        return $this->find(array_map(static fn (string $slug): string => $slug . '.php', $candidates))['slug'] ?? null;
    }

    /**
     * The template a hybrid theme serves for these candidates, the way core's
     * locate_block_template() picks it: the PHP file locate_template() finds
     * cuts the candidates after its own name, and a block template among
     * what is left, as specific or more, wins over the file. Returns what
     * `$block` returns for the block template, else the PHP file's slug,
     * else null.
     *
     * @param list<string>                                    $candidates Template slugs without `.php`, most specific first.
     * @param callable(list<string>, string): ?string        $block      Given the remaining candidates and the PHP file's
     *                                                                    absolute path ('' for none), the block template's key or null.
     */
    public function resolveWithBlockTemplates(array $candidates, callable $block): ?string
    {
        $found = $this->find(array_map(static fn (string $slug): string => $slug . '.php', $candidates));

        if ($found !== null) {
            $index = array_search($found['slug'], $candidates, true);
            $candidates = $index !== false ? array_slice($candidates, 0, $index + 1) : $candidates;
        }

        return $block($candidates, $found['path'] ?? '') ?? $found['slug'] ?? null;
    }

    /**
     * Core's locate_template() without loading the file and without the
     * theme-compat fallback: each name in turn, in the child theme, then the
     * parent.
     *
     * @param list<string> $names File names relative to the theme folder.
     */
    public function locateFile(array $names): ?string
    {
        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            foreach ($this->themes as $theme) {
                $path = rtrim($theme['dir'], '/\\') . '/' . $name;

                if (is_file($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * One row per template, sorted by slug. Rows carry no content: their
     * `includes` are the slugs of the parts they include.
     *
     * @return list<TemplateRow>
     */
    public function templates(): array
    {
        return array_map(
            fn (array $file): array => $this->row($file['found'], $file['title'], '', $file['includes'], $file['reads'], $file['links'])
                + ['shows_post' => $this->postOutput($file['found'])],
            $this->templateFiles()
        );
    }

    /**
     * What PHP source prints of the current post, sorted: `title` for
     * the_title(), single_post_title() or get_the_title() with no argument,
     * `content` for the_content() or get_the_content() with none, `excerpt`
     * for the_excerpt() or get_the_excerpt() with none. A getter given an
     * argument reads another post (a related project's title) and does not
     * count, so a theme that passes the current post's id to it reads as not
     * printing it.
     *
     * @return list<string>
     */
    public static function showsPost(string $php): array
    {
        $shows = [];

        foreach (self::calls($php, [...array_keys(self::POST_OUTPUT), ...array_keys(self::POST_GETTERS)]) as $call) {
            if (isset(self::POST_OUTPUT[$call['function']])) {
                $shows[] = self::POST_OUTPUT[$call['function']];
            } elseif ($call['args'] === []) {
                $shows[] = self::POST_GETTERS[$call['function']];
            }
        }

        return self::sortedUnique($shows);
    }

    /**
     * What a file and every part it includes, directly or through other
     * parts, print of the current post. A part already being followed adds
     * nothing a second time, so an include loop ends.
     *
     * @param Found              $found
     * @param array<string, true> $visiting
     * @return list<string>
     */
    private function postOutput(array $found, array $visiting = []): array
    {
        $path = $found['path'];

        if (isset($this->postOutput[$path])) {
            return $this->postOutput[$path];
        }

        $php = self::read($path);
        $shows = self::showsPost($php);
        $visiting[$path] = true;

        foreach ($this->inclusions($php) as $inclusion) {
            if ($inclusion['found'] !== null && !isset($visiting[$inclusion['found']['path']])) {
                $shows = [...$shows, ...$this->postOutput($inclusion['found'], $visiting)];
            }
        }

        return $this->postOutput[$path] = self::sortedUnique($shows);
    }

    /**
     * One row per part reached from a template, directly or through another
     * part, sorted by slug. The area is `header` for a part get_header()
     * includes, `footer` for get_footer(), else `uncategorized`.
     *
     * @return list<TemplateRow>
     */
    public function parts(): array
    {
        /** @var array<string, array{found: Found, functions: array<string, true>, includes: list<Inclusion>, reads: list<string>, links: list<string>}> $parts */
        $parts = [];
        $queue = [];

        foreach ($this->templateFiles() as $file) {
            array_push($queue, ...$file['includes']);
        }

        while ($queue !== []) {
            $inclusion = array_shift($queue);
            $found = $inclusion['found'];

            if ($found === null) {
                continue;
            }

            if (!isset($parts[$found['slug']])) {
                $php = self::read($found['path']);
                $includes = $this->inclusions($php);
                $parts[$found['slug']] = ['found' => $found, 'functions' => [], 'includes' => $includes, 'reads' => self::reads($php), 'links' => self::links($php)];
                array_push($queue, ...$includes);
            }

            $parts[$found['slug']]['functions'][$inclusion['function']] = true;
        }

        ksort($parts, SORT_STRING);

        $rows = [];

        foreach ($parts as $slug => $part) {
            $area = match (true) {
                isset($part['functions']['get_header']) => 'header',
                isset($part['functions']['get_footer']) => 'footer',
                default                                 => 'uncategorized',
            };

            $rows[] = $this->row($part['found'], $slug . '.php', $area, $part['includes'], $part['reads'], $part['links']);
        }

        return $rows;
    }

    /**
     * Calls to the named functions in PHP source, in order: by default
     * get_header(), get_footer() and get_template_part(). Each argument is its
     * string when it is a literal, '' for a literal null, and null when it is
     * anything else. Method calls, comments and strings that merely mention a
     * name do not count.
     *
     * @param list<string> $functions Lowercase function names.
     * @return list<Call>
     */
    public static function calls(string $php, array $functions = self::INCLUDES): array
    {
        if (!function_exists('token_get_all')) {
            return [];
        }

        $tokens = array_values(array_filter(
            token_get_all($php),
            static fn (array|string $token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        $calls = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $function = strtolower(ltrim($token[1], '\\'));
            $before = $tokens[$i - 1] ?? null;

            if (
                !in_array($function, $functions, true)
                || ($tokens[$i + 1] ?? null) !== '('
                || (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))
            ) {
                continue;
            }

            $calls[] = ['function' => $function, 'args' => self::args($tokens, $i + 2)];
        }

        return $calls;
    }

    /**
     * What PHP source reads, from calls with a literal first argument, sorted:
     * `acf_option:<key>` for an ACF options-page field (a second argument of
     * 'option' or 'options'), `acf:<key>` for any other ACF field, and
     * `option:<name>` for get_option(). A read whose name is not a literal is
     * left out, since nothing static can say what it reads.
     *
     * @return list<string>
     */
    public static function reads(string $php): array
    {
        $reads = [];

        foreach (self::calls($php, [...self::FIELD_READS, 'get_option']) as $call) {
            $name = $call['args'][0] ?? null;

            if ($name === null || preg_match('/^[^\s|,]+$/', $name) !== 1) {
                continue;
            }

            $reads[] = match (true) {
                $call['function'] === 'get_option'                              => 'option:' . $name,
                in_array($call['args'][1] ?? null, self::OPTION_CONTEXTS, true) => 'acf_option:' . $name,
                default                                                         => 'acf:' . $name,
            };
        }

        return self::sortedUnique($reads);
    }

    /**
     * Paths PHP source links to through home_url() with a literal path,
     * sorted: `home_url('/contact/')` links to `/contact/`.
     *
     * @return list<string>
     */
    public static function links(string $php): array
    {
        $links = [];

        foreach (self::calls($php, [self::LINK]) as $call) {
            $path = $call['args'][0] ?? null;

            if ($path !== null && str_starts_with($path, '/') && preg_match('/\s/', $path) !== 1) {
                $links[] = $path;
            }
        }

        return self::sortedUnique($links);
    }

    /**
     * The file names core tries for one call, in order: get_header('shop')
     * tries `header-shop.php`, then `header.php`. Empty when the slug is not
     * a literal.
     *
     * @param Call $call
     * @return list<string>
     */
    public static function candidates(array $call): array
    {
        $args = $call['args'];

        // `??` turns a dynamic name (null) into '', so it tries the base file alone.
        if ($call['function'] === 'get_template_part') {
            $base = $args[0] ?? null;
            $name = $args[1] ?? '';
        } else {
            $base = substr($call['function'], 4);
            $name = $args[0] ?? '';
        }

        if ($base === null || $base === '') {
            return [];
        }

        return $name !== '' ? [$base . '-' . $name . '.php', $base . '.php'] : [$base . '.php'];
    }

    /**
     * @return list<array{found: Found, title: string, includes: list<Inclusion>, reads: list<string>, links: list<string>}>
     */
    private function templateFiles(): array
    {
        if ($this->templateFiles !== null) {
            return $this->templateFiles;
        }

        $names = [];

        foreach ($this->themes as $theme) {
            foreach (self::phpFiles($theme['dir']) as $name) {
                $names[$name] = true;
            }
        }

        ksort($names, SORT_STRING);

        $files = [];

        foreach (array_keys($names) as $name) {
            $found = $this->find([$name]);

            if ($found === null) {
                continue;
            }

            $php = self::read($found['path']);
            $title = self::templateName($php);

            if ($title === null && (str_contains($name, '/') || preg_match(self::HIERARCHY, $name) !== 1)) {
                continue;
            }

            $files[] = [
                'found'    => $found,
                'title'    => $title ?? $name,
                'includes' => $this->inclusions($php),
                'reads'    => self::reads($php),
                'links'    => self::links($php),
            ];
        }

        return $this->templateFiles = $files;
    }

    /**
     * The parts a file includes, each with the file it resolves to. One that
     * resolves to nothing keeps its first candidate as its slug, so the ref
     * still names what is missing.
     *
     * @return list<Inclusion>
     */
    private function inclusions(string $php): array
    {
        $inclusions = [];

        foreach (self::calls($php) as $call) {
            $names = self::candidates($call);

            if ($names === []) {
                continue;
            }

            $found = $this->find($names);
            $inclusions[] = [
                'slug'     => $found['slug'] ?? substr($names[0], 0, -4),
                'function' => $call['function'],
                'found'    => $found,
            ];
        }

        return $inclusions;
    }

    /**
     * @param list<string> $names
     * @return Found|null Null when nothing is found, or the file found lies outside both themes.
     */
    private function find(array $names): ?array
    {
        $path = ($this->locate)($names);

        if ($path === null) {
            return null;
        }

        $path = SourceFile::normalize($path);

        foreach ($this->themes as $theme) {
            $dir = rtrim(SourceFile::normalize($theme['dir']), '/') . '/';

            if (str_starts_with($path, $dir) && str_ends_with($path, '.php')) {
                return ['slug' => substr($path, strlen($dir), -4), 'theme' => $theme['slug'], 'path' => $path];
            }
        }

        return null;
    }

    /**
     * A row in the shape LayoutSource gives block templates, with the same
     * `file`, `file_theme` and `overrides` rules, plus the file's reads and
     * links.
     *
     * @param Found            $found
     * @param list<Inclusion>  $includes
     * @param list<string>     $reads
     * @param list<string>     $links
     * @return TemplateRow
     */
    private function row(array $found, string $title, string $area, array $includes, array $reads, array $links): array
    {
        $parent = $this->themes[1] ?? null;
        $overrides = null;

        if ($parent !== null && $found['theme'] !== $parent['slug'] && is_file(rtrim($parent['dir'], '/\\') . '/' . $found['slug'] . '.php')) {
            $overrides = $parent['slug'];
        }

        $slugs = array_values(array_unique(array_column($includes, 'slug')));
        sort($slugs, SORT_STRING);

        return [
            'slug'          => $found['slug'],
            'title'         => $title,
            'theme'         => $this->themes[0]['slug'] ?? $found['theme'],
            'source'        => 'theme',
            'user_modified' => false,
            'area'          => $area,
            'content'       => '',
            'file'          => SourceFile::relative($found['path'], $this->roots),
            'file_theme'    => $found['theme'],
            'overrides'     => $overrides,
            'includes'      => $slugs,
            'reads'         => $reads,
            'links'         => $links,
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function sortedUnique(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * Arguments of a call, from the token after its `(` to the matching `)`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens Without whitespace and comments.
     * @return list<string|null>
     */
    private static function args(array $tokens, int $start): array
    {
        $args = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === ')' || $text === ']' || $text === '}') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($text === '(' || $text === '[' || $text === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($text === ',' && $depth === 0) {
                $args[] = self::literal($current);
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $args[] = self::literal($current);
        }

        return $args;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens One argument's tokens.
     */
    private static function literal(array $tokens): ?string
    {
        $token = count($tokens) === 1 ? $tokens[0] : null;

        if (!is_array($token)) {
            return null;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            $body = substr($token[1], 1, -1);

            return $token[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
        }

        return $token[0] === T_STRING && strtolower($token[1]) === 'null' ? '' : null;
    }

    /**
     * The `Template Name:` header, cleaned up the way core's
     * _cleanup_header_comment() does it, or null when there is none.
     */
    private static function templateName(string $php): ?string
    {
        if (preg_match('|Template Name:(.*)$|mi', $php, $match) !== 1) {
            return null;
        }

        $name = trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));

        return $name !== '' ? $name : null;
    }

    /**
     * PHP files in a theme folder and one folder down, as names relative to
     * it, sorted.
     *
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        $names = [];

        foreach (self::entries($dir) as $entry) {
            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                if (in_array($entry, self::SKIPPED_FOLDERS, true)) {
                    continue;
                }

                foreach (self::entries($path) as $inner) {
                    if (str_ends_with($inner, '.php') && is_file($path . '/' . $inner)) {
                        $names[] = $entry . '/' . $inner;
                    }
                }
            } elseif (str_ends_with($entry, '.php')) {
                $names[] = $entry;
            }
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return list<string> Entries of a folder, without dot files.
     */
    private static function entries(string $dir): array
    {
        $entries = is_dir($dir) ? scandir($dir) : false;

        return $entries === false ? [] : array_values(array_filter($entries, static fn (string $entry): bool => $entry !== '' && $entry[0] !== '.'));
    }

    private static function read(string $path): string
    {
        $php = is_file($path) ? file_get_contents($path) : false;

        return $php === false ? '' : $php;
    }
}
