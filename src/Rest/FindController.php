<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Rest;

use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\FindPayload;
use Voyager\Wayfinder\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET wayfinder/v1/find?url=&text=&class=&detail=`: the edit locator over
 * REST, compact unless `detail` is `full` (see FindPayload).
 *
 * Read access needs edit_pages, like the Wayfinder menu. Core answers a
 * request that fails the permission check with 401 when nobody is logged in
 * and 403 when the user lacks the capability.
 */
final class FindController
{
    public const NAMESPACE = 'wayfinder/v1';

    public const ROUTE = '/find';

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'find'],
            'permission_callback' => [$this, 'canRead'],
            'args'                => [
                'url'    => [
                    'type'        => 'string',
                    'required'    => true,
                    'description' => 'Page path or URL on this site, such as / or /services/drain-cleaning/.',
                ],
                'text'   => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'Text visible on that page.',
                ],
                'class'  => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'A CSS class a block carries, or a block name. With text, only blocks matching both.',
                ],
                'detail' => [
                    'type'        => 'string',
                    'required'    => false,
                    'description' => 'compact (the default) keeps the first three hits and chain entries and leaves out the class and lock lists of the page; full returns everything.',
                ],
            ],
        ]);
    }

    public function canRead(): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function find(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $url = $request->get_param('url');
        $text = $request->get_param('text');
        $class = $request->get_param('class');
        $detail = FindPayload::detail($request->get_param('detail'));

        // An empty detail is the default; anything else that is not a detail is a mistake worth saying.
        if ($detail === null) {
            return new WP_Error('vwf_bad_request', 'detail must be compact or full.', ['status' => 400]);
        }

        $result = (new EditLocator())->find(
            is_string($url) ? $url : '',
            is_string($text) ? $text : null,
            is_string($class) ? $class : null
        );

        if ($result['route'] === null) {
            return new WP_Error('vwf_unknown_url', (string) $result['message'], ['status' => 404]);
        }

        return new WP_REST_Response(FindPayload::shape($result, $detail), 200);
    }
}
