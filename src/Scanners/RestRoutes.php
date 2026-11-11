<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;

/**
 * `rest:` items in the code section: one per REST route a theme, plugin or
 * mu-plugin registers. Core's routes and Wayfinder's own are left out.
 *
 * Every route names its handler, the handler's file (relative, rule 3), the
 * methods it answers and whether it is public. A route the active theme or
 * an mu-plugin registers is also read: TokenReader follows its handler two
 * levels into user code, and PhpSinks says what the calls it finds do. So a
 * route says it records an Orbit lead, sends mail and to whom by name (a
 * constant with the file that declares it, an option, a filter), and which
 * actions it fires with the files that listen. A recipient's value is never
 * read: a constant is named, never resolved.
 *
 * A plugin's routes are named but not read (`traced: false`): their code is
 * the plugin's, and its effects belong to the plugin. What a traced handler
 * does is what the reader saw two levels deep. Work a listener, a cron job or
 * a queue does later is outside it, so "sends no mail" means none in the
 * code it follows.
 *
 * Line numbers move with every edit above them, so they stay out of the
 * hash: they sit in `stats.lines` (a design note: hash the file and the
 * function, not the line).
 *
 * @phpstan-import-type RestEndpoint from CodeSource
 * @phpstan-import-type Step from TokenReader
 * @phpstan-import-type Arg from TokenReader
 */
final class RestRoutes
{
    public const PREFIX = 'rest:';

    public const KIND = 'rest_route';

    /** Where get_bloginfo() keys read their value from. */
    public const BLOGINFO_OPTIONS = [
        'admin_email' => 'admin_email',
        'description' => 'blogdescription',
        'name'        => 'blogname',
        'url'         => 'home',
        'wpurl'       => 'siteurl',
    ];

    private CodeSource $source;

    private TokenReader $reader;

    private PhpSinks $sinks;

    public function __construct(CodeSource $source, TokenReader $reader, PhpSinks $sinks)
    {
        $this->source = $source;
        $this->reader = $reader;
        $this->sinks = $sinks;
    }

    /**
     * @return list<Item> Sorted by id.
     */
    public function items(): array
    {
        /** @var array<string, array{route: string, namespace: string|null, owner: array{kind: string, slug: string}, endpoints: list<array<string, mixed>>, lines: list<array<string, mixed>>}> $routes */
        $routes = [];

        foreach ($this->source->restEndpoints() as $endpoint) {
            $file = $endpoint['callback']['file'];
            $relative = $file !== null ? $this->source->relative($file) : null;
            $owner = CodeScanner::owner($relative);

            // Core's routes, and anything reflection cannot place, are not the site's code.
            if ($owner === null) {
                continue;
            }

            $id = self::id($endpoint['route']);
            $routes[$id] ??= ['route' => $endpoint['route'], 'namespace' => $endpoint['namespace'], 'owner' => $owner, 'endpoints' => [], 'lines' => []];

            [$facts, $lines] = $this->endpoint($endpoint, $relative, $owner);
            $routes[$id]['endpoints'][] = $facts;
            $routes[$id]['lines'][] = $lines;
        }

        ksort($routes, SORT_STRING);
        $items = [];

        foreach ($routes as $id => $route) {
            $refs = $route['owner']['kind'] === 'plugin' ? ['plugin:' . $route['owner']['slug']] : [];

            foreach ($route['endpoints'] as $endpoint) {
                $through = $endpoint['mail_through'] ?? null;

                if (is_string($through) && str_starts_with($through, 'plugin:')) {
                    $refs[] = $through;
                }
            }

            $items[] = new Item(
                $id,
                self::KIND,
                $route['route'],
                [
                    'endpoints' => $route['endpoints'],
                    'namespace' => $route['namespace'],
                    'owner'     => $route['owner'],
                ],
                null,
                $refs,
                ['lines' => $route['lines']]
            );
        }

        return $items;
    }

    /**
     * `rest:voyagermark/v1/contact`: the route without its leading slash,
     * with whitespace, `|` and `,` (a regex quantifier) made `-`.
     */
    public static function id(string $route): string
    {
        $slug = (string) preg_replace('/[\s|,]+/', '-', trim($route, '/'));

        return self::PREFIX . ($slug !== '' ? $slug : 'index');
    }

    /**
     * One endpoint's facts, and the lines that go to stats.
     *
     * @param RestEndpoint                     $endpoint
     * @param array{kind: string, slug: string} $owner
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function endpoint(array $endpoint, ?string $relative, array $owner): array
    {
        $callback = $endpoint['callback'];
        $methods = $endpoint['methods'];
        sort($methods, SORT_STRING);

        $facts = [
            'file'       => $relative,
            'handler'    => $callback['name'],
            'methods'    => $methods,
            'permission' => $endpoint['permission'],
        ];
        $lines = ['handler' => CodeScanner::span($relative, $callback['start'], $callback['end'])];

        $steps = CodeScanner::follows($owner) ? CodeScanner::follow($this->source, $this->reader, $callback) : null;

        if ($steps === null) {
            return [$facts + ['traced' => false], $lines];
        }

        $mails = [];
        $records = [];
        $posts = [];
        $redirects = false;
        $fires = [];
        $sites = ['mail' => [], 'record' => [], 'post' => [], 'redirect' => [], 'constants' => []];

        foreach ($steps as $step) {
            $stepFile = $this->source->relative($step['file']);

            foreach ($step['body']['calls'] as $call) {
                $at = CodeScanner::span($stepFile, $call['line'], null);

                if (in_array($call['function'], ['do_action', 'do_action_ref_array'], true) && ($call['args'][0]['kind'] ?? null) === 'string') {
                    $fires[(string) $call['args'][0]['value']] = true;
                    continue;
                }

                $sink = $this->sinks->sink($call['function']);

                if ($sink === null) {
                    continue;
                }

                $sites[$sink['effect']][] = $at;

                if ($sink['effect'] === 'mail') {
                    [$recipient, $declared] = $this->recipient($call['args'][0] ?? null, $steps);
                    $mails[CanonicalJson::encode($recipient)] = $recipient;

                    if ($declared !== null) {
                        $sites['constants'][(string) $recipient['name']] = $declared;
                    }
                } elseif ($sink['effect'] === 'record') {
                    $records[$sink['label']] = true;
                } elseif ($sink['effect'] === 'post') {
                    $posts[self::postType($call['args'][0] ?? null) ?? 'not traced'] = true;
                } else {
                    $redirects = true;
                }
            }
        }

        ksort($mails, SORT_STRING);
        ksort($records, SORT_STRING);
        ksort($posts, SORT_STRING);
        ksort($fires, SORT_STRING);
        ksort($sites['constants'], SORT_STRING);

        $facts += [
            'traced'  => true,
            'mails'   => array_values($mails),
            'records' => array_keys($records),
            'fires'   => array_map(fn (string $action): array => ['action' => $action, 'listeners' => $this->listenerFiles($action)], array_keys($fires)),
        ];

        if ($mails !== []) {
            $facts['mail_through'] = $this->mailThrough();
        }

        if ($posts !== []) {
            $facts['creates_posts'] = array_keys($posts);
        }

        if ($redirects) {
            $facts['redirects'] = true;
        }

        $lines['calls'] = array_filter($sites, static fn (array $at): bool => $at !== []);

        return [$facts, $lines];
    }

    /**
     * Who a mail goes to, by name, and where a constant recipient is declared.
     * Never a value.
     *
     * @param Arg|null   $arg
     * @param list<Step> $steps
     * @return array{0: array<string, string|null>, 1: string|null} The recipient, and the constant's `file:line` for stats.
     */
    private function recipient(?array $arg, array $steps, int $hops = 0): array
    {
        $kind = $arg['kind'] ?? null;
        $value = $arg['value'] ?? null;

        if ($kind === 'var' && $hops === 0 && is_array($arg['from'] ?? null)) {
            /** @var Arg $from */
            $from = $arg['from'];

            return $this->recipient($from, $steps, 1);
        }

        if ($kind === 'const' && is_string($value)) {
            $declared = $this->reader->declaredAt($value, $steps);
            $file = $declared !== null ? $this->source->relative($declared['file']) : null;

            return [
                ['declared_in' => $file, 'name' => $value, 'to' => 'constant'],
                $declared !== null ? CodeScanner::span($file, $declared['line'], null) : null,
            ];
        }

        if ($kind === 'string') {
            return [['to' => 'address in code'], null];
        }

        $first = is_array($arg['arg'] ?? null) && ($arg['arg']['kind'] ?? null) === 'string' ? (string) $arg['arg']['value'] : null;

        if ($kind === 'call' && $first !== null) {
            if ($value === 'get_option') {
                return [['name' => $first, 'to' => 'option'], null];
            }

            if ($value === 'get_bloginfo' && isset(self::BLOGINFO_OPTIONS[$first])) {
                return [['name' => self::BLOGINFO_OPTIONS[$first], 'to' => 'option'], null];
            }

            if ($value === 'apply_filters') {
                return [['name' => $first, 'to' => 'filter'], null];
            }
        }

        return [['to' => 'not traced'], null];
    }

    /**
     * The post type a wp_insert_post() call creates, when its array argument
     * names it with a literal.
     *
     * @param Arg|null $arg
     */
    private static function postType(?array $arg): ?string
    {
        if (($arg['kind'] ?? null) === 'var' && is_array($arg['from'] ?? null)) {
            $arg = $arg['from'];
        }

        $type = is_array($arg['keys'] ?? null) ? ($arg['keys']['post_type'] ?? null) : null;

        return is_string($type) && preg_match('/^[a-z0-9_-]+$/', $type) === 1 ? $type : null;
    }

    /**
     * `plugin:<slug>` for a plugin that replaces wp_mail(), `core` for
     * WordPress's own, null when reflection cannot place it.
     */
    private function mailThrough(): ?string
    {
        $file = $this->source->functionFile('wp_mail');
        $relative = $file !== null ? $this->source->relative($file) : null;
        $owner = CodeScanner::owner($relative);

        return match (true) {
            $relative === SourceFile::CORE => SourceFile::CORE,
            $owner === null                => null,
            $owner['kind'] === 'plugin'    => 'plugin:' . $owner['slug'],
            default                        => $relative,
        };
    }

    /**
     * @return list<string> Relative files of an action's listeners, `unknown` for one reflection cannot place.
     */
    private function listenerFiles(string $action): array
    {
        $files = [];

        foreach ($this->source->listeners($action) as $file) {
            $files[] = $file !== null ? ($this->source->relative($file) ?? 'unknown') : 'unknown';
        }

        return $files;
    }
}
