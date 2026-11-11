<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Rest;

use InvalidArgumentException;
use Voyager\Wayfinder\Manifest\SnapshotStore;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Render\Ai\Changes;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Render\Ai\SectionRenderer;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET wayfinder/v1/context?tier=brief|index|section&id=` and
 * `GET wayfinder/v1/changes?since=<hash>`, for users who can edit pages.
 *
 * The brief and the index go out as text/plain and the section as
 * application/json, byte for byte as the renderer made them: WordPress would
 * otherwise JSON-encode the string a second time. Every context response
 * carries `ETag: "<manifest hash>"`, and a matching If-None-Match gets a 304
 * with no body.
 */
final class ContextController
{
    public const NAMESPACE = 'wayfinder/v1';

    /** Marks a response this controller serves itself. */
    private const RAW_HEADER = 'X-Wayfinder-Tier';

    /** @var (callable(): Context)|null */
    private $contextFactory;

    /**
     * @param (callable(): Context)|null $contextFactory Defaults to a fresh Context per request.
     */
    public function __construct(?callable $contextFactory = null)
    {
        $this->contextFactory = $contextFactory;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * The routes, and the filter that sends their bodies raw. Both only
     * matter to a REST request, so both wait for `rest_api_init`.
     */
    public function registerRoutes(): void
    {
        add_filter('rest_pre_serve_request', [$this, 'serveRaw'], 10, 4);

        register_rest_route(self::NAMESPACE, '/context', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'context'],
            'permission_callback' => [self::class, 'canRead'],
            'args'                => [
                'tier' => [
                    'type'    => 'string',
                    'enum'    => Context::TIERS,
                    'default' => 'brief',
                ],
                'id'   => [
                    'type'    => 'string',
                    'default' => '',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/changes', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'changes'],
            'permission_callback' => [self::class, 'canRead'],
            'args'                => [
                'since' => [
                    'type'     => 'string',
                    'required' => true,
                ],
            ],
        ]);
    }

    /**
     * Anonymous requests get 401 and logged-in users without the capability
     * get 403: WordPress picks the code from whether anyone is logged in.
     */
    public static function canRead(): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    public function context(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $context = $this->newContext();
        $tier = (string) $request->get_param('tier');
        $id = trim((string) $request->get_param('id'));
        $etag = '"' . $context->etag() . '"';

        if (self::matches($request->get_header('if_none_match'), $etag)) {
            $response = new WP_REST_Response(null, 304);
            $response->header('ETag', $etag);
            $response->header(self::RAW_HEADER, $tier);

            return $response;
        }

        try {
            $body = $context->render($tier, $id);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('vwf_bad_request', $e->getMessage(), ['status' => 400]);
        }

        if ($body === null) {
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

        $response = new WP_REST_Response($body, 200);
        $response->header('ETag', $etag);
        $response->header(self::RAW_HEADER, $tier);
        $response->header(
            'Content-Type',
            ($tier === SectionRenderer::TIER ? 'application/json' : 'text/plain') . '; charset=utf-8'
        );

        return $response;
    }

    public function changes(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(Changes::since(
            trim((string) $request->get_param('since')),
            $this->newContext()->manifest()->hash,
            (new SnapshotStore())->history()
        ));
    }

    /**
     * Sends a context response's body as is. Runs on `rest_pre_serve_request`
     * after WordPress has sent the status and headers.
     *
     * @param bool $served
     */
    public function serveRaw($served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server): bool
    {
        if ($served || !isset($result->get_headers()[self::RAW_HEADER])) {
            return (bool) $served;
        }

        $body = $result->get_data();

        if ($request->get_method() !== 'HEAD' && is_string($body)) {
            // Plain text or JSON the renderer already encoded, sent under its own Content-Type. Never HTML.
            echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return true;
    }

    /**
     * Whether an If-None-Match header names this ETag. Weak validators and
     * lists are accepted, as is `*`.
     */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || trim($ifNoneMatch) === '') {
            return false;
        }

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }

    private function newContext(): Context
    {
        return $this->contextFactory !== null ? ($this->contextFactory)() : new Context();
    }
}
