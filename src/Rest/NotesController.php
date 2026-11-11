<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Rest;

use InvalidArgumentException;
use RuntimeException;
use Voyager\Wayfinder\Notes\Note;
use Voyager\Wayfinder\Notes\NoteService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `wayfinder/v1/notes`: read with `edit_pages`, write with `manage_options`.
 *
 * Authentication is WordPress's own: a logged-in browser sends the `wp_rest`
 * nonce, anything else uses an application password.
 */
final class NotesController
{
    public const NAMESPACE = 'wayfinder/v1';

    public const READ_CAPABILITY = 'edit_pages';

    public const WRITE_CAPABILITY = 'manage_options';

    private ?NoteService $notes;

    public function __construct(?NoteService $notes = null)
    {
        $this->notes = $notes;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/notes', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list'],
                'permission_callback' => [$this, 'canRead'],
                'args'                => [
                    'item_id' => ['type' => 'string', 'description' => 'Only notes on this item.'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'canWrite'],
                'args'                => [
                    'item_id' => ['type' => 'string', 'required' => true],
                    'body'    => ['type' => 'string', 'required' => true],
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/notes/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canWrite'],
                'args'                => [
                    'body' => ['type' => 'string', 'required' => true],
                ],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => [$this, 'canWrite'],
            ],
        ]);
    }

    public function canRead(): bool
    {
        return current_user_can(self::READ_CAPABILITY);
    }

    public function canWrite(): bool
    {
        return current_user_can(self::WRITE_CAPABILITY);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $itemId = $request->get_param('item_id');
        $notes = $this->notes()->all(is_string($itemId) && $itemId !== '' ? $itemId : null);

        return new WP_REST_Response(array_map(static fn (Note $note): array => $note->toArray(), $notes), 200);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $note = $this->notes()->add(
                (string) $request->get_param('item_id'),
                (string) $request->get_param('body'),
                get_current_user_id()
            );
        } catch (InvalidArgumentException $e) {
            return new WP_Error('vwf_invalid_note', $e->getMessage(), ['status' => 400]);
        } catch (RuntimeException $e) {
            return new WP_Error('vwf_note_not_saved', $e->getMessage(), ['status' => 500]);
        }

        return new WP_REST_Response($note->toArray(), 201);
    }

    /**
     * Re-saves a note. Its status goes back to `ok` unless its item is gone.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $note = $this->notes()->resave(
                (int) $request->get_param('id'),
                (string) $request->get_param('body'),
                get_current_user_id()
            );
        } catch (InvalidArgumentException $e) {
            return new WP_Error('vwf_invalid_note', $e->getMessage(), ['status' => 400]);
        } catch (RuntimeException $e) {
            return new WP_Error('vwf_note_not_saved', $e->getMessage(), ['status' => 500]);
        }

        return $note === null ? self::notFound() : new WP_REST_Response($note->toArray(), 200);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id = (int) $request->get_param('id');

        if (!$this->notes()->delete($id)) {
            return self::notFound();
        }

        return new WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }

    private function notes(): NoteService
    {
        return $this->notes ??= new NoteService();
    }

    private static function notFound(): WP_Error
    {
        return new WP_Error('vwf_note_not_found', 'No note has that id.', ['status' => 404]);
    }
}
