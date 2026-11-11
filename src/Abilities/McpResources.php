<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Abilities;

use Voyager\Wayfinder\Render\Ai\BriefRenderer;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Render\Ai\IndexRenderer;

/**
 * The brief and the index as MCP resources, beside the get-brief and
 * get-index tools.
 *
 * An agent reads the brief and the index once, at the start, which is what
 * MCP resources are for. The MCP Adapter sends a tool result as
 * `wp_json_encode($result)`, so over a tool the text arrives JSON-escaped; a
 * resource read carries it as `text/plain`, byte for byte what the REST tier
 * sends.
 *
 * The adapter turns an ability into a resource when `meta.mcp.type` is
 * `resource` and `meta.mcp.uri` is set, and lists it in `resources/list`
 * while `meta.mcp.public` is true (mcp-adapter 0.7.0
 * includes/Servers/DefaultServerFactory.php `discover_abilities_by_type()`,
 * includes/Domain/Resources/RegisterAbilityAsMcpResource.php @ 54ed266).
 * One ability is one MCP type, so the resources are two more abilities and
 * the tools stay as they are. Its discover tool lists only abilities of type
 * `tool`, so these two do not show up there twice.
 *
 * The rules are the tools' rules: `edit_pages`, and `mcp.public` follows
 * `vwf_mcp_enabled`, off by default.
 */
final class McpResources
{
    public const BRIEF = 'voyager-wayfinder/brief-resource';

    public const INDEX = 'voyager-wayfinder/index-resource';

    public const BRIEF_URI = 'wayfinder://brief';

    public const INDEX_URI = 'wayfinder://index';

    public const MIME_TYPE = 'text/plain';

    /** The `_meta` key that carries the full manifest hash on each read. */
    public const HASH_META = 'wayfinder/manifest_hash';

    /** @var (callable(): Context)|null */
    private $contextFactory;

    /**
     * @param (callable(): Context)|null $contextFactory Defaults to a fresh Context per read.
     */
    public function __construct(?callable $contextFactory = null)
    {
        $this->contextFactory = $contextFactory;
    }

    public function register(): void
    {
        add_action('wp_abilities_api_init', [$this, 'registerResources']);
    }

    public function registerResources(): void
    {
        foreach ($this->definitions(Abilities::mcpEnabled()) as $name => $args) {
            wp_register_ability($name, $args);
        }
    }

    /**
     * The two resource abilities' arguments, by name. The category is the
     * one Abilities registers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitions(bool $mcpPublic): array
    {
        $common = [
            'category'            => Abilities::CATEGORY,
            'permission_callback' => [Abilities::class, 'canRead'],
            'input_schema'        => Abilities::noInput(),
            'output_schema'       => self::contentsOutput(),
        ];

        return [
            self::BRIEF => $common + [
                'label'            => __('Site brief (resource)', 'voyager-wayfinder'),
                'description'      => 'The Wayfinder brief of this WordPress site as plain text: site identity, content types '
                    . 'with key fields and count bands, layout counts, plugins by role, and the top places where things are '
                    . 'edited (wp-admin paths). Read it first. About 1,700 characters on a small site, never more than 2,400. '
                    . 'The same text as the get-brief tool, without JSON escaping. The full manifest hash for '
                    . 'get-changes-since is in the content _meta under ' . self::HASH_META . '.',
                'meta'             => self::meta($mcpPublic, self::BRIEF_URI, 1.0),
                'execute_callback' => [$this, 'brief'],
            ],
            self::INDEX => $common + [
                'label'            => __('Site index (resource)', 'voyager-wayfinder'),
                'description'      => 'Every item Wayfinder knows on this WordPress site as plain text, one line each, sorted '
                    . 'by id, with key facts, the wp-admin edit path and what it references. Read the brief first, and this '
                    . 'when the brief is not enough. About 8,200 characters on a small site, never more than 16,000. The same '
                    . 'text as the get-index tool, without JSON escaping. Pass an id from here to the get-section tool for '
                    . 'every fact about that item.',
                'meta'             => self::meta($mcpPublic, self::INDEX_URI, 0.8),
                'execute_callback' => [$this, 'index'],
            ],
        ];
    }

    /**
     * @param mixed $input Unused: a resource read takes no input.
     * @return list<array{uri: string, mimeType: string, text: string, _meta: array<string, string>}>
     */
    public function brief(mixed $input = null): array
    {
        return $this->read(BriefRenderer::TIER, self::BRIEF_URI);
    }

    /**
     * @param mixed $input Unused: a resource read takes no input.
     * @return list<array{uri: string, mimeType: string, text: string, _meta: array<string, string>}>
     */
    public function index(mixed $input = null): array
    {
        return $this->read(IndexRenderer::TIER, self::INDEX_URI);
    }

    /**
     * One text content item, in the shape the adapter's resources/read
     * passes through as is: an array whose first item has `text`.
     *
     * @return list<array{uri: string, mimeType: string, text: string, _meta: array<string, string>}>
     */
    private function read(string $tier, string $uri): array
    {
        $context = $this->contextFactory !== null ? ($this->contextFactory)() : new Context();

        return [
            [
                'uri'      => $uri,
                'mimeType' => self::MIME_TYPE,
                'text'     => (string) $context->render($tier),
                '_meta'    => [self::HASH_META => $context->manifest()->hash],
            ],
        ];
    }

    /**
     * Core reads `annotations` for wp-admin and REST; the adapter maps only
     * the resource annotations (audience, priority) from `mcp.annotations`.
     *
     * @return array<string, mixed>
     */
    private static function meta(bool $mcpPublic, string $uri, float $priority): array
    {
        return [
            'annotations'  => [
                'readonly'    => true,
                'destructive' => false,
                'idempotent'  => true,
            ],
            'show_in_rest' => true,
            'mcp'          => [
                'public'      => $mcpPublic,
                'type'        => 'resource',
                'uri'         => $uri,
                'mimeType'    => self::MIME_TYPE,
                'annotations' => [
                    'audience' => ['assistant'],
                    'priority' => $priority,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function contentsOutput(): array
    {
        return [
            'type'     => 'array',
            'minItems' => 1,
            'maxItems' => 1,
            'items'    => [
                'type'                 => 'object',
                'properties'           => [
                    'uri'      => ['type' => 'string'],
                    'mimeType' => ['type' => 'string', 'enum' => [self::MIME_TYPE]],
                    'text'     => ['type' => 'string'],
                    '_meta'    => [
                        'type'       => 'object',
                        'properties' => [
                            self::HASH_META => [
                                'type'        => 'string',
                                'description' => 'The manifest hash: pass it to get-changes-since later.',
                            ],
                        ],
                    ],
                ],
                'required'             => ['uri', 'mimeType', 'text', '_meta'],
                'additionalProperties' => false,
            ],
        ];
    }
}
