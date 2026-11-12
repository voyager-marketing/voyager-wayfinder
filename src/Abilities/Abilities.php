<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Abilities;

use InvalidArgumentException;
use JsonException;
use Voyager\Wayfinder\Blocks\Locks;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\FindPayload;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Changes;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use Voyager\Wayfinder\Scanners\Builders;
use WP_Error;

/**
 * The AI tiers as WordPress abilities, so any MCP client can read the manual
 * through the official MCP Adapter.
 *
 * Five read-only abilities in the `voyager-wayfinder` category. Each one runs
 * the code its REST route runs: Context for the brief, the index and the
 * section tier, EditLocator for find-edit-location (shaped by FindPayload),
 * Changes over the stored history for changes-since. Nothing here renders or
 * scans on its own.
 *
 * Every ability needs edit_pages, like the REST routes and the menu. MCP
 * exposure is off unless the `vwf_mcp_enabled` option is truthy: the manual
 * is a map of the site. `meta.mcp.public` is always set, so the adapter never
 * falls back to the core `public` flag. Registration follows voyager-orbit
 * src/Abilities.php @ abe7d26 (category on `wp_abilities_api_categories_init`,
 * abilities on `wp_abilities_api_init`, `meta.mcp.public`).
 */
final class Abilities
{
    public const CATEGORY = 'voyager-wayfinder';

    public const MCP_OPTION = 'vwf_mcp_enabled';

    public const GET_BRIEF = 'voyager-wayfinder/get-brief';

    public const GET_INDEX = 'voyager-wayfinder/get-index';

    public const GET_SECTION = 'voyager-wayfinder/get-section';

    public const FIND_EDIT_LOCATION = 'voyager-wayfinder/find-edit-location';

    public const GET_CHANGES_SINCE = 'voyager-wayfinder/get-changes-since';

    /** @var (callable(): Context)|null */
    private $contextFactory;

    /** @var (callable(): EditLocator)|null */
    private $locatorFactory;

    /** @var (callable(): mixed)|null */
    private $history;

    /**
     * @param (callable(): Context)|null     $contextFactory Defaults to a fresh Context per call.
     * @param (callable(): EditLocator)|null $locatorFactory Defaults to a fresh EditLocator per call.
     * @param (callable(): mixed)|null       $history        Defaults to the stored `vwf_manifest_history`.
     */
    public function __construct(?callable $contextFactory = null, ?callable $locatorFactory = null, ?callable $history = null)
    {
        $this->contextFactory = $contextFactory;
        $this->locatorFactory = $locatorFactory;
        $this->history = $history;
    }

    public function register(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    public function registerCategory(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Wayfinder', 'voyager-wayfinder'),
            'description' => __('Read the Wayfinder manual of this site: how it is built and where each thing is edited.', 'voyager-wayfinder'),
        ]);
    }

    public function registerAbilities(): void
    {
        foreach ($this->definitions(self::mcpEnabled()) as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * Whether the abilities are offered to MCP clients. Off unless the
     * option holds a truthy value; the string "false" counts as off.
     */
    public static function mcpEnabled(): bool
    {
        return wp_validate_boolean(get_option(self::MCP_OPTION, 0));
    }

    /**
     * Read access matches the REST routes and the menu.
     */
    public static function canRead(): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    /**
     * The five abilities' arguments, by name, in the order an agent should
     * meet them. Labels are translated for wp-admin. Descriptions are written
     * for agents and stay in English, so a tool list reads the same on any
     * site; their sizes come from `wp wayfinder stats` on the fixture.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(bool $mcpPublic): array
    {
        $meta = [
            'annotations'  => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
            'show_in_rest' => true,
            'mcp'          => ['public' => $mcpPublic],
        ];

        $common = [
            'category'            => self::CATEGORY,
            'permission_callback' => [self::class, 'canRead'],
            'meta'                => $meta,
        ];

        return [
            self::GET_BRIEF          => $common + [
                'label'            => __('Get the site brief', 'voyager-wayfinder'),
                'description'      => 'Call this first. Returns the Wayfinder brief of this WordPress site as compact text: '
                    . 'site identity, content types with key fields and their counts as bands (0, 1-5, 6-20, 21-100, 100+), '
                    . 'layout counts, plugins by role, and the top places where things are edited (wp-admin paths). About '
                    . '1,700 characters (roughly 400 tokens) on a small site, never more than 2,400. The text is identical '
                    . 'while the site structure is unchanged and counts stay in their bands, so it caches well. For an exact '
                    . 'count, call get-section with the item id. '
                    . 'Also returns the manifest hash: keep it and pass it to get-changes-since later instead of reading '
                    . 'everything again. If the brief does not answer the question, call get-index next.',
                'input_schema'     => self::noInput(),
                'output_schema'    => self::tierOutput('The brief, as compact text.'),
                'execute_callback' => [$this, 'brief'],
            ],
            self::GET_INDEX          => $common + [
                'label'            => __('Get the site index', 'voyager-wayfinder'),
                'description'      => 'Returns every item Wayfinder knows on this site, one line each, sorted by id: post types (pt:), '
                    . 'fields (field:), taxonomies (tax:), templates (tpl:), template parts (part:), patterns (pat:), menus (nav:), '
                    . 'block bindings (bind:), routes (route:), plugins (plugin:) and settings (opt:), each with key facts, the '
                    . 'wp-admin edit path and what it references. About 8,200 characters (roughly 2,000 tokens) on a small site, '
                    . 'never more than 16,000. Call get-brief first, and this one when the brief is not enough. Pass an id from '
                    . 'here to get-section for every fact about that item.',
                'input_schema'     => self::noInput(),
                'output_schema'    => self::tierOutput('The index, as compact text.'),
                'execute_callback' => [$this, 'index'],
            ],
            self::GET_SECTION        => $common + [
                'label'            => __('Get one section or item', 'voyager-wayfinder'),
                'description'      => 'Returns the full JSON for one manifest section (site, content_model, layout, routes, code or '
                    . 'integrations) or for one item by id (for example pt:service, field:service.price_from or pat:cta-banner): '
                    . 'every fact, its edit path, its refs, exact counts, the content that uses it and a route\'s example URL (stats), and any agency '
                    . 'notes on the item. Call it when a line in the brief or '
                    . 'the index needs detail. One item is usually under 1,000 characters; a whole section can run to tens of '
                    . 'thousands, so ask for an item when you can. Call get-brief first.',
                'input_schema'     => [
                    'type'                 => 'object',
                    'properties'           => [
                        'id' => [
                            'type'        => 'string',
                            'minLength'   => 1,
                            'description' => 'A section name (site, content_model, layout, routes, code, integrations) or an item id from the index, such as pat:cta-banner.',
                        ],
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'output_schema'    => self::sectionOutput(),
                'execute_callback' => [$this, 'section'],
            ],
            self::FIND_EDIT_LOCATION => $common + [
                'label'            => __('Find where something is edited', 'voyager-wayfinder'),
                'description'      => 'Answers "where do I change this thing I see on the page?". Give a page path or URL on this '
                    . 'site and, if you have it, some text visible on that page. Returns the chain of things the page is built '
                    . 'from, most specific first, each with an item id, a plain label, a wp-admin edit path and a one-line '
                    . 'instruction. With text, the chain starts with what contains it: a block binding (such as a phone number '
                    . 'from a settings page), the post content, a synced pattern (with every page that shares it) or a template '
                    . 'part. Give class instead of (or with) text to find the blocks that carry a CSS class such as hide-mobile, '
                    . 'or a block name, which clients cannot see in the editor; each hit then counts its blocks in class_count. '
                    . 'hits lists every place the match lives, on this page or elsewhere on the site, best first; when '
                    . 'ambiguous is true the top two are equally good answers, so say both rather than picking one. '
                    . 'Each hit on a block carries an anchor (entity, post, path, fingerprint fp, expect_hash): a writer '
                    . 'edits only a block that still has that fp, and after the edit the block at verify.recheck must have a '
                    . 'new fp while get-changes-since from verify.changes_since lists no more than verify.expect_changed. '
                    . 'Nothing is rendered over HTTP, so text that only PHP outputs is not found, and the message says so. '
                    . 'When coverage is partial with reason builder_document, the page is built in a page builder such as '
                    . 'Elementor whose content Wayfinder does not read: a miss is not an answer, so send the edit to the '
                    . 'content entry\'s edit URL, the builder\'s editor, instead of searching further. '
                    . 'The answer keeps the first three hits and chain entries and counts the rest in hits_more and '
                    . 'chain_more, so it usually runs to about 3,000 characters; give detail full for every hit, the whole chain '
                    . 'and the class and lock lists of the page. Call get-brief first if you have not read it.',
                'input_schema'     => [
                    'type'                 => 'object',
                    'properties'           => [
                        'url'    => [
                            'type'        => 'string',
                            'minLength'   => 1,
                            'description' => 'Page path or full URL on this site, such as / or /services/drain-cleaning/.',
                        ],
                        'text'   => [
                            'type'        => 'string',
                            'description' => 'Optional. Text visible on that page, such as a phone number or a heading.',
                        ],
                        'class'  => [
                            'type'        => 'string',
                            'description' => 'Optional. A CSS class a block carries, such as hide-mobile, or a block name. With text, only blocks matching both.',
                        ],
                        'detail' => [
                            'type'        => 'string',
                            'enum'        => FindPayload::DETAILS,
                            'description' => 'Optional. compact, the default, keeps the first three hits and chain entries; full returns every one, with the class and lock lists of the page.',
                        ],
                    ],
                    'required'             => ['url'],
                    'additionalProperties' => false,
                ],
                'output_schema'    => self::findOutput(),
                'execute_callback' => [$this, 'find'],
            ],
            self::GET_CHANGES_SINCE  => $common + [
                'label'            => __('Get changes since a hash', 'voyager-wayfinder'),
                'description'      => 'Returns the ids of every item added, removed or changed since a manifest hash you already '
                    . 'hold from get-brief or get-index, so you can refresh only those with get-section. When it cannot tell, '
                    . 'it answers full: true, and you should call get-brief and get-index again. The hash it returns is the '
                    . 'current one. Small: a list of ids. Call get-brief first; this is for coming back later.',
                'input_schema'     => [
                    'type'                 => 'object',
                    'properties'           => [
                        'since' => [
                            'type'        => 'string',
                            'minLength'   => 1,
                            'description' => 'The full manifest hash from an earlier get-brief or get-index result.',
                        ],
                    ],
                    'required'             => ['since'],
                    'additionalProperties' => false,
                ],
                'output_schema'    => self::changesOutput(),
                'execute_callback' => [$this, 'changes'],
            ],
        ];
    }

    /**
     * @param mixed $input Unused: the brief takes no input.
     * @return array{hash: string, text: string}
     */
    public function brief(mixed $input = null): array
    {
        return $this->tier(BriefRenderer::TIER);
    }

    /**
     * @param mixed $input Unused: the index takes no input.
     * @return array{hash: string, text: string}
     */
    public function index(mixed $input = null): array
    {
        return $this->tier(IndexRenderer::TIER);
    }

    /**
     * The section tier, decoded. Inner objects stay objects, so an empty
     * `facts` still reads `{}` once the response is encoded again.
     *
     * @param mixed $input `{id}`, already checked against the input schema.
     * @return array<string, mixed>|WP_Error
     */
    public function section(mixed $input): array|WP_Error
    {
        $id = trim(self::field($input, 'id'));

        try {
            $json = $this->newContext()->render(SectionRenderer::TIER, $id);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('vwf_bad_request', $e->getMessage(), ['status' => 400]);
        }

        if ($json === null) {
            return new WP_Error(
                'vwf_not_found',
                sprintf(
                    /* translators: %s: section name or item id. */
                    __('No section or item has the id %s.', 'voyager-wayfinder'),
                    $id
                ),
                ['status' => 404]
            );
        }

        try {
            return (array) json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return new WP_Error('vwf_bad_json', $e->getMessage(), ['status' => 500]);
        }
    }

    /**
     * @param mixed $input `{url, text?, class?, detail?}`, already checked against the input schema.
     * @return array<string, mixed>|WP_Error
     */
    public function find(mixed $input): array|WP_Error
    {
        $text = self::field($input, 'text');
        $class = self::field($input, 'class');
        $detail = FindPayload::detail(self::field($input, 'detail'));

        // The schema's enum stops a bad detail first; a direct call gets the same answer as REST.
        if ($detail === null) {
            return new WP_Error('vwf_bad_request', 'detail must be compact or full.', ['status' => 400]);
        }

        $result = $this->newLocator()->find(self::field($input, 'url'), $text !== '' ? $text : null, $class !== '' ? $class : null);

        if ($result['route'] === null) {
            return new WP_Error('vwf_unknown_url', (string) $result['message'], ['status' => 404]);
        }

        return FindPayload::shape($result, $detail);
    }

    /**
     * @param mixed $input `{since}`, already checked against the input schema.
     * @return array{changed?: list<string>, full: bool, hash: string}
     */
    public function changes(mixed $input): array
    {
        return Changes::since(
            trim(self::field($input, 'since')),
            $this->newContext()->manifest()->hash,
            $this->history !== null ? ($this->history)() : (new SnapshotStore())->history()
        );
    }

    /**
     * @return array{hash: string, text: string}
     */
    private function tier(string $tier): array
    {
        $context = $this->newContext();

        return [
            'hash' => $context->manifest()->hash,
            'text' => (string) $context->render($tier),
        ];
    }

    private static function field(mixed $input, string $key): string
    {
        $value = is_array($input) ? ($input[$key] ?? '') : '';

        return is_string($value) ? $value : '';
    }

    private function newContext(): Context
    {
        return $this->contextFactory !== null ? ($this->contextFactory)() : new Context();
    }

    private function newLocator(): EditLocator
    {
        return $this->locatorFactory !== null ? ($this->locatorFactory)() : new EditLocator();
    }

    /**
     * No input. The default lets a call with no input at all, over REST or
     * from PHP, pass validation as an empty object. McpResources uses it too.
     *
     * @return array<string, mixed>
     */
    public static function noInput(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [],
            'additionalProperties' => false,
            'default'              => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tierOutput(string $text): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'hash' => [
                    'type'        => 'string',
                    'description' => 'The manifest hash: sha256 of the site manifest. Pass it to get-changes-since later.',
                ],
                'text' => [
                    'type'        => 'string',
                    'description' => $text,
                ],
            ],
            'required'             => ['hash', 'text'],
            'additionalProperties' => false,
        ];
    }

    /**
     * A section (`name`, `hash`, `items`, and `stats` by item id when any
     * item has some) or one item (`id`, `kind`, `label`, `edit`, `facts`,
     * `refs`, `hash`, `section`, and `stats` and `notes` when it has any).
     * Further keys are allowed: the manifest may grow fields, and a new one
     * must not turn into a failed call.
     *
     * @return array<string, mixed>
     */
    private static function sectionOutput(): array
    {
        $item = [
            'type'       => 'object',
            'properties' => [
                'id'    => ['type' => 'string'],
                'kind'  => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'edit'  => ['type' => ['string', 'null']],
                'facts' => ['type' => 'object'],
                'refs'  => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                ],
                'hash'  => ['type' => 'string'],
            ],
            'required'   => ['id', 'kind', 'label', 'edit', 'facts', 'refs', 'hash'],
        ];

        $oneItem = $item;
        $oneItem['properties']['section'] = ['type' => 'string'];
        $oneItem['properties']['stats'] = ['type' => 'object'];
        $oneItem['properties']['notes'] = [
            'type'  => 'array',
            'items' => [
                'type'       => 'object',
                'properties' => [
                    'body'   => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                ],
                'required'   => ['body', 'status'],
            ],
        ];
        $oneItem['required'][] = 'section';

        return [
            'type'  => 'object',
            'oneOf' => [
                [
                    'type'       => 'object',
                    'title'      => 'section',
                    'properties' => [
                        'name'  => ['type' => 'string'],
                        'hash'  => ['type' => 'string'],
                        'items' => [
                            'type'  => 'array',
                            'items' => $item,
                        ],
                        'stats' => ['type' => 'object'],
                    ],
                    'required'   => ['name', 'hash', 'items'],
                ],
                $oneItem + ['title' => 'item'],
            ],
        ];
    }

    /**
     * EditLocator's result as FindPayload shapes it, as the REST route returns
     * it. Chain entries stay open to new keys, like manifest items.
     *
     * @return array<string, mixed>
     */
    private static function findOutput(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $anchor = [
            'type'       => ['object', 'null'],
            'properties' => [
                'entity'      => ['type' => 'string'],
                'post'        => ['type' => ['integer', 'null']],
                'path'        => ['type' => 'string'],
                'fp'          => ['type' => 'string'],
                'expect_hash' => $nullableString,
            ],
            'required'   => ['entity', 'post', 'path', 'fp', 'expect_hash'],
        ];

        return [
            'type'                 => 'object',
            'properties'           => [
                'detail'     => ['type' => 'string', 'enum' => FindPayload::DETAILS],
                'chain_more' => ['type' => 'integer', 'minimum' => 1],
                'hits_more'  => ['type' => 'integer', 'minimum' => 1],
                'url'      => ['type' => 'string'],
                'route'    => ['type' => 'string'],
                'template' => $nullableString,
                'text'     => $nullableString,
                'class'    => $nullableString,
                'matched'  => ['type' => ['boolean', 'null']],
                'ambiguous' => ['type' => 'boolean'],
                'hits'      => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'rank'       => ['type' => 'integer'],
                            'id'         => ['type' => 'string'],
                            'block_path' => $nullableString,
                            'snippet'    => ['type' => 'string'],
                            'why'        => ['type' => 'array', 'items' => ['type' => 'string']],
                            'on_route'   => ['type' => 'boolean'],
                            'distance'   => ['type' => ['integer', 'null']],
                            'routes'     => ['type' => ['integer', 'null']],
                            'anchor'     => $anchor,
                        ],
                        'required'   => ['rank', 'id', 'block_path', 'snippet', 'why', 'on_route', 'distance', 'routes', 'anchor'],
                    ],
                ],
                'verify'   => [
                    'type'       => 'object',
                    'properties' => [
                        'changes_since'  => $nullableString,
                        'expect_changed' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'recheck'        => $anchor,
                    ],
                    'required'   => ['changes_since', 'expect_changed', 'recheck'],
                ],
                'message'  => $nullableString,
                'coverage' => ['type' => 'string', 'enum' => [Builders::PARTIAL]],
                'reason'   => ['type' => 'string', 'enum' => [Builders::REASON]],
                'chain'    => [
                    'type'  => 'array',
                    'items' => [
                        'type'                 => 'object',
                        'properties'           => [
                            'id'          => ['type' => 'string'],
                            'label'       => ['type' => 'string'],
                            'edit'        => $nullableString,
                            'instruction' => ['type' => 'string'],
                            'match'       => ['type' => 'boolean'],
                            'used_in'     => [
                                'type'  => 'array',
                                'items' => ['type' => 'string'],
                            ],
                            'usage_count' => ['type' => 'integer'],
                            'lock'        => [
                                'type'       => 'object',
                                'properties' => [
                                    'effective'     => ['type' => 'string', 'enum' => Locks::EFFECTIVE],
                                    'src'           => ['type' => 'string', 'enum' => ['none', 'block', 'template_lock', 'post_type', 'wp7_default']],
                                    'text_editable' => ['type' => 'boolean'],
                                ],
                                'required'   => ['effective', 'src', 'text_editable'],
                            ],
                            'client_can'     => ['type' => 'string', 'enum' => ['yes', 'partial', 'no']],
                            'client_can_why' => ['type' => 'string'],
                            'class_count'    => ['type' => 'integer'],
                            'anchor'         => $anchor,
                            'classes'        => ['type' => 'array', 'items' => ['type' => 'object']],
                            'block_names'    => ['type' => 'array', 'items' => ['type' => 'object']],
                            'hidden_classes' => ['type' => 'boolean'],
                            'builder'        => ['type' => 'string'],
                        ],
                        'required'   => ['id', 'label', 'edit', 'instruction', 'match', 'lock', 'client_can', 'client_can_why'],
                    ],
                ],
            ],
            'required'             => ['detail', 'url', 'route', 'template', 'text', 'class', 'matched', 'ambiguous', 'message', 'chain', 'hits', 'verify'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function changesOutput(): array
    {
        return [
            'type'                 => 'object',
            'properties'           => [
                'changed' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'Item ids added, removed or changed since the given hash, sorted. Absent when full is true.',
                ],
                'full'    => [
                    'type'        => 'boolean',
                    'description' => 'True when the change list cannot be worked out: read the brief and the index again.',
                ],
                'hash'    => [
                    'type'        => 'string',
                    'description' => 'The current manifest hash.',
                ],
            ],
            'required'             => ['full', 'hash'],
            'additionalProperties' => false,
        ];
    }
}
