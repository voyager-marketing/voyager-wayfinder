<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Support;

use Voyager\Wayfinder\Scanners\CodeSource;
use Voyager\Wayfinder\Scanners\TokenReader;

/**
 * An in-memory CodeSource over PHP source strings. Paths are relative
 * already (`themes/acme/functions.php`); a path under `wp-includes/` is core,
 * and anything outside themes, plugins and mu-plugins is outside every root.
 *
 * fixture() is a small site with what the code section reads: a theme route
 * whose handler records an Orbit lead and mails the address in a theme
 * constant through Postmark, a theme route that records a lead and sends no
 * mail, a plugin route, a core route, theme filters on Rank Math's og:title
 * and description and on core's archive description, and a plugin filter.
 *
 * @phpstan-import-type RestEndpoint from CodeSource
 * @phpstan-import-type HookCallback from CodeSource
 * @phpstan-import-type CodeCallback from CodeSource
 */
final class FakeCodeSource implements CodeSource
{
    public const THEME = 'acme';

    /** @var array<string, string> Path => PHP source. */
    public array $files = [];

    /** @var list<RestEndpoint> */
    public array $endpoints = [];

    /** @var list<HookCallback> */
    public array $hooks = [];

    /** @var array<string, list<string|null>> Action => listener files. */
    public array $listeners = [];

    /** The file that defines wp_mail(): core's, or a plugin that replaces it. */
    public string $wpMail = 'wp-includes/pluggable.php';

    /** Calls to restEndpoints(), so a test can see when routes were read. */
    public int $routeReads = 0;

    /** @var (callable(): void)|null Runs on each restEndpoints() call, as reading routes fires rest_api_init. */
    public $onRouteRead = null;

    public static function fixture(): self
    {
        $source = new self();
        $source->wpMail = 'plugins/postmark-approved-wordpress-plugin/wp-mail.php';

        $source->files = [
            'themes/acme/functions.php' => <<<'PHP'
                <?php
                require __DIR__ . '/inc/contact.php';

                function acme_og_title( string $title ): string {
                    if ( is_front_page() ) {
                        return 'Plumbing done right in Riverbend';
                    }
                    return $title;
                }
                add_filter( 'rank_math/opengraph/facebook/og_title', 'acme_og_title' );

                function acme_og_site_name(): string {
                    return (string) get_bloginfo( 'name' );
                }

                function acme_services_description( string $description ): string {
                    return is_post_type_archive( 'service' ) ? 'Every service we offer' : $description;
                }
                PHP,
            'themes/acme/inc/contact.php' => <<<'PHP'
                <?php
                const ACME_CONTACT_MIN_DWELL = 6;

                /** Where the notification goes. */
                const ACME_CONTACT_NOTIFY = 'owner@example.com';

                function acme_contact_handle( WP_REST_Request $request ) {
                    if ( (int) $request->get_param( 'elapsed' ) < ACME_CONTACT_MIN_DWELL ) {
                        return new WP_REST_Response( array( 'ok' => true ), 200 );
                    }
                    $lead = acme_contact_record( $request );
                    acme_contact_notify( $lead );
                    do_action( 'acme_contact_submit', $lead );
                    return new WP_REST_Response( array( 'ok' => true ), 200 );
                }

                function acme_contact_record( WP_REST_Request $request ): int {
                    $module = \Voyager\Orbit\Modules\Leads\Module::instance();
                    return (int) $module->insertLead( array( 'source' => 'contact' ) );
                }

                function acme_contact_notify( int $lead ): bool {
                    return wp_mail( ACME_CONTACT_NOTIFY, 'New contact', 'Lead ' . $lead );
                }

                function acme_signup_handle( WP_REST_Request $request ) {
                    \Voyager\Orbit\Modules\Leads\Module::instance()->insertLead( array( 'source' => 'signup' ) );
                    return new WP_REST_Response( array( 'ok' => true ), 200 );
                }
                PHP,
            'plugins/voyager-orbit/src/Modules/Leads/Module.php' => <<<'PHP'
                <?php
                namespace Voyager\Orbit\Modules\Leads;

                final class Module {
                    public static function instance(): self {
                        return new self();
                    }

                    public function insertLead( array $row ): int {
                        return $this->store( $row );
                    }

                    private function store( array $row ): int {
                        return 1;
                    }
                }
                PHP,
            'plugins/acme-seo/src/Rest.php' => <<<'PHP'
                <?php
                namespace Acme\Seo;

                final class Rest {
                    public function settings( $request ) {
                        return wp_mail( get_option( 'admin_email' ), 'Saved', 'Saved' );
                    }

                    public function title( string $title ): string {
                        return $title;
                    }
                }
                PHP,
            'plugins/postmark-approved-wordpress-plugin/wp-mail.php' => "<?php\nfunction wp_mail( \$to, \$subject, \$message ) {\n    return true;\n}\n",
            'wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php' => "<?php\nclass WP_REST_Posts_Controller {\n    public function get_items( \$request ) {\n        return wp_mail( 'a@example.com', 'x', 'y' );\n    }\n}\n",
        ];

        $source->endpoints = [
            self::endpoint('/wp/v2/posts', 'wp/v2', ['GET'], self::method('WP_REST_Posts_Controller::get_items', 'wp-includes/rest-api/endpoints/class-wp-rest-posts-controller.php'), self::CHECKED),
            self::endpoint('/acme/v1/contact', 'acme/v1', ['POST'], $source->callback('acme_contact_handle'), self::PUBLIC),
            self::endpoint('/acme/v1/signup', 'acme/v1', ['POST'], $source->callback('acme_signup_handle'), self::PUBLIC),
            self::endpoint('/acme-seo/v1/settings', 'acme-seo/v1', ['POST', 'GET'], self::method('Acme\Seo\Rest::settings', 'plugins/acme-seo/src/Rest.php'), self::CHECKED),
        ];

        $source->hooks = [
            ['hook' => 'get_the_archive_description', 'priority' => 10, 'callback' => $source->callback('acme_services_description')],
            ['hook' => 'rank_math/frontend/title', 'priority' => 10, 'callback' => self::method('Acme\Seo\Rest::title', 'plugins/acme-seo/src/Rest.php')],
            ['hook' => 'rank_math/opengraph/facebook/og_site_name', 'priority' => 10, 'callback' => $source->callback('acme_og_site_name')],
            ['hook' => 'rank_math/opengraph/facebook/og_title', 'priority' => 10, 'callback' => $source->callback('acme_og_title')],
            ['hook' => 'wp_title', 'priority' => 10, 'callback' => ['name' => 'trim', 'file' => null, 'start' => null, 'end' => null]],
        ];

        $source->listeners = ['acme_contact_submit' => []];

        return $source;
    }

    /**
     * @param list<string> $methods
     * @param CodeCallback $callback
     * @return RestEndpoint
     */
    public static function endpoint(string $route, ?string $namespace, array $methods, array $callback, string $permission): array
    {
        return ['route' => $route, 'namespace' => $namespace, 'methods' => $methods, 'callback' => $callback, 'permission' => $permission];
    }

    /**
     * A function callback, placed by the declaration in this source's files.
     *
     * @return CodeCallback
     */
    public function callback(string $name): array
    {
        foreach ($this->files as $path => $php) {
            $declaration = TokenReader::declarations($php)[strtolower($name)] ?? null;

            if ($declaration !== null) {
                return ['name' => $declaration['name'], 'file' => $path, 'start' => $declaration['start'], 'end' => $declaration['end']];
            }
        }

        return ['name' => $name, 'file' => null, 'start' => null, 'end' => null];
    }

    /**
     * A method callback in a file, without lines.
     *
     * @return CodeCallback
     */
    public static function method(string $name, string $file): array
    {
        return ['name' => $name, 'file' => $file, 'start' => 1, 'end' => null];
    }

    public function restEndpoints(): array
    {
        $this->routeReads++;

        if ($this->onRouteRead !== null) {
            ($this->onRouteRead)();
        }

        return $this->endpoints;
    }

    public function hookCallbacks(callable $matches): array
    {
        return array_values(array_filter($this->hooks, static fn (array $row): bool => $matches($row['hook'])));
    }

    public function listeners(string $hook): array
    {
        return $this->listeners[$hook] ?? [];
    }

    public function relative(string $file): ?string
    {
        return match (true) {
            str_starts_with($file, 'wp-includes/')                                                                   => 'core',
            str_starts_with($file, 'themes/'), str_starts_with($file, 'plugins/'), str_starts_with($file, 'mu-plugins/') => $file,
            default                                                                                                  => null,
        };
    }

    public function read(string $file): ?string
    {
        return $this->files[$file] ?? null;
    }

    public function functionFile(string $name): ?string
    {
        if (strtolower($name) === 'wp_mail') {
            return $this->wpMail;
        }

        return $this->callback($name)['file'];
    }

    public function methodFile(string $class, string $method): ?string
    {
        $key = strtolower(ltrim($class, '\\') . '::' . $method);

        foreach ($this->files as $path => $php) {
            if (isset(TokenReader::declarations($php)[$key])) {
                return $path;
            }
        }

        return null;
    }

    public function themeFiles(): array
    {
        $files = array_values(array_filter(array_keys($this->files), static fn (string $path): bool => str_starts_with($path, 'themes/' . self::THEME . '/')));
        sort($files, SORT_STRING);

        return $files;
    }
}
