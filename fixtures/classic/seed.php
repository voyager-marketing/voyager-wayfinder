<?php

/**
 * Seeds the Wayfinder classic fixture site (Ashford Remodeling) and writes
 * fixtures/classic/truth.json, the classic benchmark's ground truth.
 *
 *     npx @wordpress/env run cli wp eval-file wp-content/plugins/voyager-wayfinder/fixtures/classic/seed.php
 *
 * The classic fixture shares the fixture site with the block fixture
 * (fixtures/seed.php). This seed makes the site classic: Secure Custom Fields
 * in place of ACF free, because the repeater, the flexible content field and
 * the options page need it, and the vwf-classic child theme of Twenty
 * Twenty-One. Each fixture's mu-plugin registers its content model only while
 * its own theme is active, and Secure Custom Fields cannot load in a process
 * that has loaded ACF. So after switching the plugins and the theme, the seed
 * reruns itself in a fresh WP-CLI process, where `init` registers the classic
 * model and the theme registers its menu locations and sidebars. The block
 * seed switches the site back the same way.
 *
 * A full reset, so every run leaves the same site. Each run deletes every page,
 * post and project, every project type and every menu, whatever created them,
 * plus every row either fixture tagged `_vwf_fixture`. It then recreates the
 * site with every post row at a reserved id (90100 to 90149) and both menus at
 * reserved term ids (90190 and 90191), so edit paths never move. Because it
 * deletes content it did not create, it refuses to run unless the fixture
 * mu-plugin has defined VWF_FIXTURE.
 *
 * truth.json is built from the constants in this file and from
 * vwf_classic_model() in fixtures/mu-plugins/vwf-classic-core.php, never by
 * reading the site back, so benchmark answers never come from the scanner
 * under test. What constants cannot generate is checked instead, and the seed
 * stops on any difference: the theme files' tokens (the parts they include,
 * the menu locations and sidebars they show, the fields they read, the links
 * they hard code), the menu locations, sidebars and page templates WordPress
 * registered, and every field value read back after it is saved.
 *
 * Also creates the `vwf-bench` Application Password for admin and writes
 * fixtures/.credentials, the same file the block seed writes.
 *
 * No `declare(strict_types=1)` and no namespace: `wp eval-file` runs the file
 * through eval() after a `?>`, and both must be the first statement.
 *
 * @package Voyager\Wayfinder
 */

if (!defined('ABSPATH') || !class_exists('WP_CLI')) {
    exit(1);
}

// Only the fixture mu-plugin defines this, so the reset below can never wipe a real site.
if (!defined('VWF_FIXTURE')) {
    WP_CLI::error('VWF_FIXTURE is not defined, so this is not the Wayfinder fixture site. The seed deletes every page, post and project, and runs only where fixtures/mu-plugins/vwf-fixture-core.php is loaded (see the mappings in .wp-env.json).');
}

final class VwfClassicSeed
{
    /** The argument the seed passes to its own rerun. */
    public const RELAUNCHED = 'relaunched';

    private const TAG = '_vwf_fixture';

    private const SITE_TITLE = 'Ashford Remodeling';

    private const PERMALINKS = '/%postname%/';

    private const THEME = 'vwf-classic';

    private const PARENT_THEME = 'twentytwentyone';

    private const ADMIN_LOGIN = 'admin';

    private const APP_PASSWORD_NAME = 'vwf-bench';

    /**
     * Reserved ids. Each kind owns its count of ids from its base, in the order
     * of its constant: the about page is 90101 and the main menu 90190. Post
     * rows and menu terms live in different tables, so the menus reuse the
     * top of the range.
     */
    private const ID_BASE = [
        'page' => 90100,
        'project' => 90110,
        'post' => 90120,
        'nav_menu_item' => 90130,
        'nav_menu' => 90190,
    ];

    private const IDS_PER_KIND = [
        'page' => 10,
        'project' => 10,
        'post' => 10,
        'nav_menu_item' => 20,
        'nav_menu' => 10,
    ];

    private const COMPANY = [
        'company_phone' => '(434) 555-0187',
        'company_address' => '410 Preston Ave, Charlottesville, VA',
        'company_email' => 'office@example.com',
        'facebook_url' => 'https://example.com/facebook/ashford-remodeling',
        'instagram_url' => 'https://example.com/instagram/ashford-remodeling',
    ];

    /** ACF stores an options page value in the option `options_<field name>`. */
    private const OPTION_PREFIX = 'options_';

    private const PROJECT_TYPES = [
        'additions' => 'Additions',
        'basements' => 'Basements',
        'bathrooms' => 'Bathrooms',
        'kitchens' => 'Kitchens',
    ];

    private const PROJECTS = [
        'brennan-kitchen' => [
            'title' => 'Brennan kitchen',
            'client_name' => 'Brennan family',
            'completion_year' => 2024,
            'types' => ['kitchens'],
            'phases' => [['Design', 3], ['Demolition', 1], ['Cabinets and counters', 4]],
            'internal_job_code' => 'AR-2401',
            'text' => 'A galley kitchen opened into the dining room, with a new island and pantry wall.',
        ],
        'hillside-primary-bath' => [
            'title' => 'Hillside primary bath',
            'client_name' => 'Okafor family',
            'completion_year' => 2025,
            'types' => ['bathrooms'],
            'phases' => [['Design', 2], ['Tile and fixtures', 3]],
            'internal_job_code' => 'AR-2507',
            'text' => 'A curbless shower, heated floors and a double vanity in the same footprint.',
        ],
        'maple-street-addition' => [
            'title' => 'Maple Street addition',
            'client_name' => 'Delgado family',
            'completion_year' => 2023,
            'types' => ['additions'],
            'phases' => [['Permits', 4], ['Framing', 6], ['Finishes', 5]],
            'internal_job_code' => 'AR-2312',
            'text' => 'A two-story addition with a family room below and a guest suite above.',
        ],
        'farmhouse-kitchen-and-bath' => [
            'title' => 'Farmhouse kitchen and bath',
            'client_name' => 'Whitaker family',
            'completion_year' => 2025,
            'types' => ['bathrooms', 'kitchens'],
            'phases' => [['Design', 3], ['Kitchen build', 5], ['Bath build', 3]],
            'internal_job_code' => 'AR-2503',
            'text' => 'A 1920s farmhouse with a new kitchen and a hall bath, keeping the original floors.',
        ],
        'ridge-road-sunroom' => [
            'title' => 'Ridge Road sunroom',
            'client_name' => 'Lindqvist family',
            'completion_year' => 2024,
            'types' => ['additions'],
            'phases' => [['Permits', 3], ['Build', 7]],
            'internal_job_code' => 'AR-2409',
            'text' => 'A four-season sunroom off the kitchen, with a vaulted ceiling and radiant heat.',
        ],
    ];

    private const LANDING = 'page-templates/landing.php';

    /**
     * `sections` are rows of the Page sections flexible content field, in
     * order. The landing template never shows a page's own text.
     */
    private const PAGES = [
        'home' => [
            'title' => 'Home',
            'template' => self::LANDING,
            'text' => ['Ashford Remodeling builds kitchens, baths and additions across Charlottesville.'],
            'sections' => [
                ['layout' => 'hero', 'heading' => 'Remodeling done right in Charlottesville', 'intro' => 'Kitchens, baths and additions, designed and built by one local team.'],
                ['layout' => 'cta_band', 'heading' => 'Planning a remodel?', 'button_label' => 'Book a free estimate'],
            ],
            'featured' => ['maple-street-addition', 'farmhouse-kitchen-and-bath', 'brennan-kitchen'],
        ],
        'about' => [
            'title' => 'About',
            'template' => 'default',
            'text' => [
                'Ashford Remodeling has remodeled homes around Charlottesville since 2011.',
                'One crew designs and builds every project, and every estimate is free and in writing.',
            ],
        ],
        'contact' => [
            'title' => 'Contact',
            'template' => 'default',
            'text' => ['Call us or send a message and we will set up a free in-home estimate.'],
        ],
        'kitchen-remodeling' => [
            'title' => 'Kitchen remodeling',
            'template' => self::LANDING,
            'text' => [],
            'sections' => [
                ['layout' => 'hero', 'heading' => 'Kitchens built around how you cook', 'intro' => 'Layouts, cabinets and counters, from the first sketch to the last outlet cover.'],
            ],
            'featured' => ['brennan-kitchen', 'farmhouse-kitchen-and-bath'],
        ],
        'news' => [
            'title' => 'News',
            'template' => 'default',
            'text' => [],
        ],
    ];

    private const FRONT_PAGE = 'home';

    private const POSTS_PAGE = 'news';

    private const POSTS = [
        'how-long-does-a-kitchen-remodel-take' => [
            'title' => 'How long does a kitchen remodel take?',
            'date' => '2026-05-12 09:00:00',
            'text' => 'Most of our kitchens take eight to ten weeks from demolition to the final walkthrough.',
        ],
        'planning-a-home-addition' => [
            'title' => 'Planning a home addition',
            'date' => '2026-07-21 09:00:00',
            'text' => 'Start with the permit timeline, because the county review often takes longer than the framing.',
        ],
        'choosing-tile-for-a-small-bathroom' => [
            'title' => 'Choosing tile for a small bathroom',
            'date' => '2026-09-08 09:00:00',
            'text' => 'Large format tile means fewer grout lines, which makes a small floor read as bigger.',
        ],
    ];

    /** Items point at a page slug or at a post type archive. */
    private const MENUS = [
        'main-navigation' => [
            'title' => 'Main navigation',
            'location' => 'main-menu',
            'items' => [
                ['label' => 'Home', 'page' => 'home'],
                ['label' => 'About', 'page' => 'about'],
                ['label' => 'Projects', 'archive' => 'project'],
                ['label' => 'Kitchen remodeling', 'page' => 'kitchen-remodeling'],
                ['label' => 'News', 'page' => 'news'],
                ['label' => 'Contact', 'page' => 'contact'],
            ],
        ],
        'footer-links' => [
            'title' => 'Footer links',
            'location' => 'footer',
            'items' => [
                ['label' => 'About', 'page' => 'about'],
                ['label' => 'Contact', 'page' => 'contact'],
            ],
        ],
    ];

    /** Menu locations as the themes register them. `primary` is the parent's and stays empty. */
    private const LOCATIONS = [
        'footer' => ['label' => 'Secondary menu', 'theme' => self::PARENT_THEME],
        'main-menu' => ['label' => 'Main menu', 'theme' => self::THEME],
        'primary' => ['label' => 'Primary menu', 'theme' => self::PARENT_THEME],
    ];

    /** Sidebars as the themes register them, with the paragraph of each block widget the seed puts in them. */
    private const SIDEBARS = [
        'project-sidebar' => [
            'name' => 'Project sidebar',
            'theme' => self::THEME,
            'widgets' => ['Planning a remodel? Call for a free in-home estimate.'],
        ],
        'sidebar-1' => [
            'name' => 'Footer',
            'theme' => self::PARENT_THEME,
            'widgets' => [],
        ],
    ];

    /** The block widget ids start at 2, as WordPress numbers them. */
    private const FIRST_WIDGET = 2;

    /**
     * The templates the fixture's routes use, by slug, and what each file
     * includes (`part:`), shows (`loc:`, `sidebar:`), reads (field names, with
     * get_field(), the_field() or have_rows()) and links to (home_url()). `post`
     * is whether the page's own content reaches the output, through the file or
     * a part it includes. checkThemeFiles() reads the files' tokens and stops on
     * any difference. `page` is the parent's: the child has no page.php.
     */
    private const TEMPLATES = [
        'archive-project' => [
            'theme' => self::THEME,
            'refs' => ['part:footer', 'part:header', 'part:template-parts/content-project'],
            'post' => true,
        ],
        'page' => [
            'theme' => self::PARENT_THEME,
            'refs' => ['part:footer', 'part:header', 'part:template-parts/content/content-page'],
            'post' => true,
        ],
        'page-templates/landing' => [
            'theme' => self::THEME,
            'name' => 'Landing page',
            'refs' => ['part:footer', 'part:header'],
            'reads' => ['featured_projects', 'page_sections'],
            'sub_reads' => ['button_label', 'heading', 'intro'],
            'links' => ['/contact/'],
            'post' => false,
        ],
        'single-project' => [
            'theme' => self::THEME,
            'refs' => ['part:footer', 'part:header', 'part:template-parts/content-project', 'sidebar:project-sidebar'],
            'post' => true,
        ],
    ];

    /** The child theme's parts. `option_reads` are get_field( ..., 'option' ) calls. */
    private const PARTS = [
        'footer' => [
            'area' => 'footer',
            'overrides' => self::PARENT_THEME,
            'refs' => ['loc:footer'],
            'option_reads' => ['company_address', 'company_phone', 'facebook_url', 'instagram_url'],
        ],
        'header' => [
            'area' => 'header',
            'overrides' => self::PARENT_THEME,
            'refs' => ['loc:main-menu'],
            'links' => ['/'],
        ],
        'template-parts/content-project' => [
            'area' => 'uncategorized',
            'overrides' => null,
            'refs' => [],
            'reads' => ['client_name', 'completion_year', 'project_phases'],
            'sub_reads' => ['phase_name', 'phase_weeks'],
        ],
    ];

    /**
     * The active plugins, by folder slug. The seed swaps ACF free for Secure
     * Custom Fields, and checkActivePlugins() stops if the site then runs any
     * other set. The MCP Adapter is active in every benchmark condition.
     */
    private const PLUGINS = [
        'mcp-adapter' => ['name' => 'MCP Adapter', 'role' => 'ai'],
        'secure-custom-fields' => ['name' => 'Secure Custom Fields', 'role' => 'fields'],
        'voyager-wayfinder' => ['name' => 'Voyager Wayfinder', 'role' => 'other'],
    ];

    /** The block fixture's fields plugin, which cannot be active beside Secure Custom Fields. */
    private const SWAPPED_PLUGINS = ['advanced-custom-fields'];

    private const FIELDS_PLUGIN = 'secure-custom-fields';

    private const MU_PLUGIN_FILE = 'vwf-classic-core.php';

    /** @var array<string, mixed> */
    private array $model;

    private int $adminId = 0;

    /** @var array<string, int> page slug => post id */
    private array $pageIds = [];

    /** @var array<string, int> project slug => post id */
    private array $projectIds = [];

    public function __construct(private bool $relaunched)
    {
    }

    public function run(): void
    {
        if ($this->switchSite()) {
            return;
        }

        $this->preflight();
        $this->reset();
        $this->seedPages();
        $this->configureSite();
        $this->seedProjects($this->seedProjectTypes());
        $this->seedLandingFields();
        $this->seedPosts();
        $this->seedCompanySettings();
        $this->seedMenus();
        $this->seedWidgets();

        // The project permastruct was built during init, before the permalink
        // structure was set on a first run. Dropping the stored rules makes the
        // next request rebuild them with every rewrite in place.
        delete_option('rewrite_rules');

        $this->writeCredentials();
        $this->writeTruth();

        WP_CLI::success(sprintf(
            'Seeded %s: %d projects, %d project types, %d pages, %d posts, %d menus, %d sidebars, 1 options page. Wrote fixtures/classic/truth.json and fixtures/.credentials.',
            self::SITE_TITLE,
            count(self::PROJECTS),
            count(self::PROJECT_TYPES),
            count(self::PAGES),
            count(self::POSTS),
            count(self::MENUS),
            count(self::SIDEBARS)
        ));
    }

    /**
     * Makes this the classic fixture's site: Secure Custom Fields in place of
     * ACF free, and the vwf-classic theme. Registration happens during `init`,
     * and Secure Custom Fields cannot load beside ACF, so after a switch the
     * seed reruns itself in a fresh WP-CLI process and this one stops.
     *
     * @return bool True when it switched and the rerun seeded the site.
     */
    private function switchSite(): bool
    {
        $active = self::activePlugins();
        $out = array_values(array_intersect(self::SWAPPED_PLUGINS, $active));
        $in = in_array(self::FIELDS_PLUGIN, $active, true) ? [] : [self::FIELDS_PLUGIN];

        if (!$out && !$in && self::THEME === get_stylesheet()) {
            return false;
        }

        if ($this->relaunched) {
            WP_CLI::error(sprintf(
                'Still not the classic fixture site after switching: theme %s, active plugins [%s].',
                get_stylesheet(),
                implode(', ', $active)
            ));
        }

        if ($in && !file_exists(WP_PLUGIN_DIR . '/' . self::FIELDS_PLUGIN . '/' . self::FIELDS_PLUGIN . '.php')) {
            WP_CLI::error('Secure Custom Fields is not installed. .wp-env.json maps it into wp-content/plugins; restart the site after pulling.');
        }

        $theme = wp_get_theme(self::THEME);
        if (!$theme->exists() || self::PARENT_THEME !== $theme->get_template() || !wp_get_theme(self::PARENT_THEME)->exists()) {
            WP_CLI::error(sprintf('Theme %s (child of %s) is not installed. Check the themes and mappings in .wp-env.json.', self::THEME, self::PARENT_THEME));
        }

        // Each in its own process, so Secure Custom Fields never loads beside ACF.
        foreach ($out as $slug) {
            WP_CLI::runcommand('plugin deactivate ' . $slug);
        }
        foreach ($in as $slug) {
            WP_CLI::runcommand('plugin activate ' . $slug);
        }

        if (self::THEME !== get_stylesheet()) {
            switch_theme(self::THEME);
            WP_CLI::log('Switched the theme to ' . self::THEME . '.');
        }

        WP_CLI::runcommand('eval-file ' . escapeshellarg(__FILE__) . ' ' . self::RELAUNCHED);

        return true;
    }

    private function preflight(): void
    {
        if (!function_exists('vwf_classic_model')) {
            WP_CLI::error('The vwf-classic-core mu-plugin is not loaded. Check the mappings in .wp-env.json.');
        }

        $this->checkActivePlugins();
        $this->model = vwf_classic_model();

        $missing = [];
        foreach (['repeater', 'flexible_content', 'relationship'] as $type) {
            if (!function_exists('acf_get_field_type') || !acf_get_field_type($type)) {
                $missing[] = $type;
            }
        }
        if (!function_exists('acf_add_options_page') || !function_exists('acf_get_options_page') || !acf_get_options_page($this->model['options_page']['menu_slug'])) {
            $missing[] = 'options page ' . $this->model['options_page']['menu_slug'];
        }
        if ($missing) {
            WP_CLI::error('Secure Custom Fields is active but these are missing: ' . implode(', ', $missing) . '.');
        }

        foreach (array_keys($this->model['post_types']) as $postType) {
            if (!post_type_exists($postType)) {
                WP_CLI::error("Post type {$postType} is not registered. The mu-plugin registers it only while " . self::THEME . ' is active.');
            }
        }

        $admin = get_user_by('login', self::ADMIN_LOGIN);
        if (false === $admin) {
            WP_CLI::error(sprintf('User %s not found.', self::ADMIN_LOGIN));
        }

        $this->adminId = $admin->ID;
        wp_set_current_user($this->adminId);

        $this->checkRegistrations();
        $this->checkThemeFiles();
    }

    /** @return list<string> Active plugins by folder slug, sorted. */
    private static function activePlugins(): array
    {
        $active = array_map('dirname', (array) get_option('active_plugins', []));
        sort($active);

        return $active;
    }

    /**
     * Fails when the active plugins differ from PLUGINS, so the plugin facts
     * in truth.json cannot drift from the site.
     */
    private function checkActivePlugins(): void
    {
        $active = self::activePlugins();
        $declared = array_keys(self::PLUGINS);
        sort($declared);

        if ($active !== $declared) {
            WP_CLI::error(sprintf(
                'Active plugins are [%s], but the seed declares [%s]. Check the plugins in .wp-env.json or the PLUGINS constant.',
                implode(', ', $active),
                implode(', ', $declared)
            ));
        }
    }

    /**
     * Fails when the menu locations, sidebars or page templates WordPress
     * registered differ from LOCATIONS, SIDEBARS and TEMPLATES, or when a
     * theme's functions.php does not name the ones truth.json says it
     * registers.
     */
    private function checkRegistrations(): void
    {
        $locations = get_registered_nav_menus();
        ksort($locations);
        $declared = array_map(static fn(array $location): string => $location['label'], self::LOCATIONS);
        if ($locations !== $declared) {
            WP_CLI::error('Registered menu locations are ' . wp_json_encode($locations) . ', but the seed declares ' . wp_json_encode($declared) . '.');
        }

        global $wp_registered_sidebars;
        $sidebars = array_map(static fn(array $sidebar): string => (string) $sidebar['name'], (array) $wp_registered_sidebars);
        ksort($sidebars);
        $declared = array_map(static fn(array $sidebar): string => $sidebar['name'], self::SIDEBARS);
        if ($sidebars !== $declared) {
            WP_CLI::error('Registered sidebars are ' . wp_json_encode($sidebars) . ', but the seed declares ' . wp_json_encode($declared) . '.');
        }

        // Which theme registers each one: its functions.php names it, the other's does not.
        $literals = [];
        foreach ([self::THEME, self::PARENT_THEME] as $theme) {
            $literals[$theme] = self::stringLiterals(wp_get_theme($theme)->get_stylesheet_directory() . '/functions.php');
        }
        $registeredBy = [];
        foreach (self::LOCATIONS as $slug => $location) {
            $registeredBy[$slug] = $location['theme'];
        }
        foreach (self::SIDEBARS as $id => $sidebar) {
            $registeredBy[$id] = $sidebar['theme'];
        }
        foreach ($registeredBy as $slug => $theme) {
            $other = self::THEME === $theme ? self::PARENT_THEME : self::THEME;
            if (!in_array($slug, $literals[$theme], true) || in_array($slug, $literals[$other], true)) {
                WP_CLI::error("The seed says {$theme} registers {$slug}, but its functions.php does not name it, or {$other}'s does too.");
            }
        }

        $pageTemplates = wp_get_theme()->get_page_templates();
        $declared = [];
        foreach (self::TEMPLATES as $slug => $template) {
            if (isset($template['name'])) {
                $declared[$slug . '.php'] = $template['name'];
            }
        }
        if ($pageTemplates !== $declared) {
            WP_CLI::error('Page templates are ' . wp_json_encode($pageTemplates) . ', but the seed declares ' . wp_json_encode($declared) . '.');
        }
    }

    /**
     * Fails when a theme file sits in a different theme, or includes, shows,
     * reads or links something other than TEMPLATES and PARTS declare, so
     * truth.json cannot drift from the files.
     */
    private function checkThemeFiles(): void
    {
        $files = [];
        foreach (self::TEMPLATES as $slug => $template) {
            $files[$slug] = $template + ['overrides' => false];
        }
        foreach (self::PARTS as $slug => $part) {
            $files[$slug] = $part + ['theme' => self::THEME];
        }

        $dirs = [
            self::THEME => get_stylesheet_directory(),
            self::PARENT_THEME => get_template_directory(),
        ];

        foreach ($files as $slug => $file) {
            $name = $slug . '.php';
            $other = self::THEME === $file['theme'] ? self::PARENT_THEME : self::THEME;

            if (!is_file($dirs[$file['theme']] . '/' . $name)) {
                WP_CLI::error("{$name} is not in {$file['theme']}.");
            }

            // A parent file the child also had would never load. A child file
            // overrides the parent's exactly when the parent has one.
            $inOther = is_file($dirs[$other] . '/' . $name);
            if (self::PARENT_THEME === $file['theme'] ? $inOther : ($inOther !== (self::PARENT_THEME === $file['overrides']))) {
                WP_CLI::error("{$name}: the seed's override facts do not match the files in " . self::THEME . ' and ' . self::PARENT_THEME . '.');
            }

            $found = $this->scanFile($dirs[$file['theme']] . '/' . $name);
            $expected = [
                'refs' => $file['refs'],
                'reads' => $file['reads'] ?? [],
                'sub_reads' => $file['sub_reads'] ?? [],
                'option_reads' => $file['option_reads'] ?? [],
                'links' => $file['links'] ?? [],
            ];
            $got = array_intersect_key($found, $expected);
            if ($got !== $expected) {
                WP_CLI::error(sprintf(
                    '%s %s has %s, but the seed declares %s. Update the file or the TEMPLATES and PARTS constants.',
                    $file['theme'],
                    $name,
                    wp_json_encode($got),
                    wp_json_encode($expected)
                ));
            }

            if (isset($file['post']) && $file['post'] !== $this->showsPostContent($dirs[$file['theme']] . '/' . $name, [])) {
                WP_CLI::error("{$name}: the seed says it " . ($file['post'] ? 'shows' : 'never shows') . ' the post content, and the file and its parts say otherwise.');
            }
        }
    }

    /**
     * What a theme file includes, shows, reads and links, from its tokens.
     * A call counts only with string literal arguments, the way Wayfinder
     * reads a classic theme. Theme PHP is never run.
     *
     * @return array{refs: list<string>, reads: list<string>, sub_reads: list<string>, option_reads: list<string>, links: list<string>, parts: list<string>, content: bool}
     */
    private function scanFile(string $path): array
    {
        $tokens = self::tokens($path);
        $found = ['refs' => [], 'reads' => [], 'sub_reads' => [], 'option_reads' => [], 'links' => [], 'parts' => [], 'content' => false];

        foreach ($tokens as $i => $token) {
            // 'theme_location' => 'main-menu'
            if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 'theme_location' === self::unquote($token[1])) {
                $arrow = $tokens[$i + 1] ?? null;
                $value = $tokens[$i + 2] ?? null;
                if (is_array($arrow) && T_DOUBLE_ARROW === $arrow[0] && is_array($value) && T_CONSTANT_ENCAPSED_STRING === $value[0]) {
                    $found['refs'][] = 'loc:' . self::unquote($value[1]);
                }
                continue;
            }

            $before = $tokens[$i - 1] ?? null;
            if (!is_array($token) || T_STRING !== $token[0] || '(' !== ($tokens[$i + 1] ?? null)
                || (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true))) {
                continue;
            }

            $args = self::literalArgs($tokens, $i + 2);
            $first = $args[0] ?? null;

            switch (strtolower($token[1])) {
                case 'get_header':
                case 'get_footer':
                    $base = 'get_header' === strtolower($token[1]) ? 'header' : 'footer';
                    $names = null !== $first ? ["{$base}-{$first}.php", "{$base}.php"] : ["{$base}.php"];
                    $this->addPart($found, $names);
                    break;
                case 'get_template_part':
                    if (null === $first) {
                        break;
                    }
                    $second = $args[1] ?? null;
                    $names = null !== $second ? ["{$first}-{$second}.php", "{$first}.php"] : ["{$first}.php"];
                    $this->addPart($found, $names);
                    break;
                case 'dynamic_sidebar':
                case 'is_active_sidebar':
                    if (null !== $first) {
                        $found['refs'][] = 'sidebar:' . $first;
                    }
                    break;
                case 'get_field':
                case 'the_field':
                case 'have_rows':
                case 'get_field_object':
                    if (null !== $first) {
                        $isOption = in_array($args[1] ?? null, ['option', 'options'], true);
                        $found[$isOption ? 'option_reads' : 'reads'][] = $first;
                    }
                    break;
                case 'get_sub_field':
                case 'the_sub_field':
                    if (null !== $first) {
                        $found['sub_reads'][] = $first;
                    }
                    break;
                case 'home_url':
                    if (null !== $first) {
                        $found['links'][] = $first;
                    }
                    break;
                case 'the_content':
                    $found['content'] = true;
                    break;
            }
        }

        foreach (['refs', 'reads', 'sub_reads', 'option_reads', 'links', 'parts'] as $key) {
            $found[$key] = array_values(array_unique($found[$key]));
            sort($found[$key], SORT_STRING);
        }

        return $found;
    }

    /**
     * Adds the part WordPress loads for these names: the first one found, in
     * the child theme, then the parent. Its id is its path in that theme.
     *
     * @param array<string, mixed> $found
     * @param list<string>         $names
     */
    private function addPart(array &$found, array $names): void
    {
        $path = locate_template($names);
        if ('' === $path) {
            WP_CLI::error('No theme file for ' . implode(' or ', $names) . '.');
        }

        foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
            $dir = wp_normalize_path($dir) . '/';
            $path = wp_normalize_path($path);
            if (str_starts_with($path, $dir)) {
                $found['refs'][] = 'part:' . substr($path, strlen($dir), -strlen('.php'));
                $found['parts'][] = $path;

                return;
            }
        }
    }

    /**
     * True when the file, or a part it includes, calls the_content().
     *
     * @param list<string> $seen
     */
    private function showsPostContent(string $path, array $seen): bool
    {
        $found = $this->scanFile($path);
        if ($found['content']) {
            return true;
        }

        foreach (array_diff($found['parts'], $seen, [$path]) as $part) {
            if ($this->showsPostContent($part, [...$seen, $path])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: string, 2: int}|string> The file's tokens without whitespace and comments.
     */
    private static function tokens(string $path): array
    {
        $source = file_get_contents($path);
        if (false === $source) {
            WP_CLI::error("Cannot read {$path}.");
        }

        return array_values(array_filter(
            token_get_all($source),
            static fn($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
    }

    /**
     * The top-level arguments of a call starting at `$start` (just after its
     * opening parenthesis): each one's value when it is a single string
     * literal, else null.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string|null>
     */
    private static function literalArgs(array $tokens, int $start): array
    {
        $args = [];
        $current = [];
        $depth = 0;

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if (0 === $depth && (')' === $text || ',' === $text)) {
                if ($current) {
                    $args[] = 1 === count($current) && is_array($current[0]) && T_CONSTANT_ENCAPSED_STRING === $current[0][0]
                        ? self::unquote($current[0][1])
                        : null;
                }
                if (')' === $text) {
                    break;
                }
                $current = [];
                continue;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            $current[] = $token;
        }

        return $args;
    }

    /** @return list<string> Every string literal in a PHP file. */
    private static function stringLiterals(string $path): array
    {
        $literals = [];
        foreach (self::tokens($path) as $token) {
            if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                $literals[] = self::unquote($token[1]);
            }
        }

        return $literals;
    }

    private static function unquote(string $literal): string
    {
        return stripslashes(substr($literal, 1, -1));
    }

    private function reset(): void
    {
        global $wpdb;

        // Menus first: deleting one deletes its items.
        foreach (wp_get_nav_menus() as $menu) {
            if (!wp_delete_nav_menu($menu->term_id)) {
                WP_CLI::error("Could not delete menu {$menu->slug}.");
            }
        }

        // Every row of the content types the fixture fills, whatever created
        // it, so a probe left by an earlier check cannot survive a reseed. The
        // WordPress sample post and pages go with them.
        $owned = get_posts([
            'post_type' => array_merge(['page', 'post'], array_keys($this->model['post_types'])),
            'post_status' => array_keys(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        update_option('wp_page_for_privacy_policy', 0);

        // Every row either fixture tagged, whatever its post type, including
        // the block fixture's, whose types are not registered here.
        $tagged = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::TAG));

        foreach (array_unique(array_map('intval', array_merge($owned, $tagged))) as $id) {
            $type = get_post_type($id);
            if (false === $type) {
                continue;
            }

            $deleted = 'attachment' === $type ? wp_delete_attachment($id, true) : wp_delete_post($id, true);
            if (!$deleted) {
                WP_CLI::error("Could not delete post {$id}.");
            }
        }

        // Every term, so one added by hand cannot survive a reseed either.
        foreach (array_keys($this->model['taxonomies']) as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'ids']);
            foreach (is_array($terms) ? $terms : [] as $termId) {
                wp_delete_term((int) $termId, $taxonomy);
            }
        }
    }

    private function configureSite(): void
    {
        update_option('blogname', self::SITE_TITLE);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->pageIds[self::FRONT_PAGE]);
        update_option('page_for_posts', $this->pageIds[self::POSTS_PAGE]);

        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure(self::PERMALINKS);
    }

    private function seedPages(): void
    {
        foreach (self::PAGES as $slug => $page) {
            $meta = 'default' === $page['template'] ? [] : ['_wp_page_template' => $page['template']];

            $this->pageIds[$slug] = $this->insertPost([
                'post_type' => 'page',
                'post_name' => $slug,
                'post_title' => $page['title'],
                'post_content' => implode("\n\n", array_map([self::class, 'paragraph'], $page['text'])),
                'meta_input' => $meta,
            ], self::reservedId('page', self::PAGES, $slug));
        }
    }

    /**
     * @return array<string, int> project type slug => term id
     */
    private function seedProjectTypes(): array
    {
        $ids = [];
        foreach (self::PROJECT_TYPES as $slug => $name) {
            $term = wp_insert_term($name, 'project_type', ['slug' => $slug]);
            if (is_wp_error($term)) {
                WP_CLI::error("Project type {$slug}: " . $term->get_error_message());
            }

            $ids[$slug] = (int) $term['term_id'];
        }

        return $ids;
    }

    /**
     * @param array<string, int> $typeIds
     */
    private function seedProjects(array $typeIds): void
    {
        $keys = $this->fieldKeys('group_vwf_project_details');

        foreach (self::PROJECTS as $slug => $project) {
            $id = $this->insertPost([
                'post_type' => 'project',
                'post_name' => $slug,
                'post_title' => $project['title'],
                'post_content' => self::paragraph($project['text']),
                'post_excerpt' => $project['text'],
            ], self::reservedId('project', self::PROJECTS, $slug));
            $this->projectIds[$slug] = $id;

            $types = array_map(static fn(string $type): int => $typeIds[$type], $project['types']);
            $result = wp_set_object_terms($id, $types, 'project_type');
            if (is_wp_error($result)) {
                WP_CLI::error("Project {$slug}: " . $result->get_error_message());
            }

            $phases = array_map(
                static fn(array $phase): array => [$keys['project_phases.phase_name'] => $phase[0], $keys['project_phases.phase_weeks'] => $phase[1]],
                $project['phases']
            );

            $this->saveField($keys['client_name'], 'client_name', $project['client_name'], $id);
            $this->saveField($keys['completion_year'], 'completion_year', $project['completion_year'], $id);
            $this->saveField($keys['internal_job_code'], 'internal_job_code', $project['internal_job_code'], $id);
            $this->saveField(
                $keys['project_phases'],
                'project_phases',
                $phases,
                $id,
                array_map(static fn(array $phase): array => ['phase_name' => $phase[0], 'phase_weeks' => $phase[1]], $project['phases'])
            );
        }
    }

    private function seedLandingFields(): void
    {
        $keys = $this->fieldKeys('group_vwf_landing_page');

        foreach (self::PAGES as $slug => $page) {
            if (self::LANDING !== $page['template']) {
                continue;
            }

            $rows = [];
            $expected = [];
            foreach ($page['sections'] as $section) {
                $layout = $section['layout'];
                $row = ['acf_fc_layout' => $layout];
                $readBack = ['acf_fc_layout' => $layout];
                foreach (array_diff_key($section, ['layout' => true]) as $name => $value) {
                    $row[$keys["page_sections.{$layout}.{$name}"]] = $value;
                    $readBack[$name] = $value;
                }
                $rows[] = $row;
                $expected[] = $readBack;
            }

            $featured = array_map(fn(string $project): int => $this->projectIds[$project], $page['featured']);

            $this->saveField($keys['page_sections'], 'page_sections', $rows, $this->pageIds[$slug], $expected);
            $this->saveField($keys['featured_projects'], 'featured_projects', $featured, $this->pageIds[$slug]);
        }
    }

    private function seedPosts(): void
    {
        foreach (self::POSTS as $slug => $post) {
            $this->insertPost([
                'post_type' => 'post',
                'post_name' => $slug,
                'post_title' => $post['title'],
                'post_content' => self::paragraph($post['text']),
                'post_date' => $post['date'],
                'post_date_gmt' => get_gmt_from_date($post['date']),
            ], self::reservedId('post', self::POSTS, $slug));
        }
    }

    /**
     * Saves the Company Settings values and checks that each one landed in the
     * option truth.json names.
     */
    private function seedCompanySettings(): void
    {
        $keys = $this->fieldKeys('group_vwf_company_details');

        foreach (self::COMPANY as $name => $value) {
            $this->saveField($keys[$name], $name, $value, 'option');

            if ($value !== get_option(self::OPTION_PREFIX . $name)) {
                WP_CLI::error("Company Settings {$name} is not stored in the option " . self::OPTION_PREFIX . $name . '.');
            }
        }
    }

    private function seedMenus(): void
    {
        $locations = [];
        $itemIndex = 0;

        foreach (self::MENUS as $slug => $menu) {
            $reservedId = self::reservedId('nav_menu', self::MENUS, $slug);
            if (term_exists($reservedId)) {
                WP_CLI::error("Term id {$reservedId} is reserved for the {$slug} menu, but another term holds it. Delete that term, or destroy the site and start a fresh one.");
            }

            // wp_insert_term() has no import_id, so the reserved id goes into its row data.
            $reserve = static fn(array $data): array => ['term_id' => $reservedId] + $data;
            add_filter('wp_insert_term_data', $reserve);
            $menuId = wp_create_nav_menu($menu['title']);
            remove_filter('wp_insert_term_data', $reserve);

            if (is_wp_error($menuId)) {
                WP_CLI::error("Menu {$slug}: " . $menuId->get_error_message());
            }
            $term = get_term($menuId, 'nav_menu');
            if ($reservedId !== $menuId || !$term instanceof WP_Term || $slug !== $term->slug) {
                WP_CLI::error("WordPress gave the {$slug} menu term id {$menuId}, not its reserved id {$reservedId}, or another slug.");
            }

            foreach ($menu['items'] as $position => $item) {
                // A draft item at the reserved id, which wp_update_nav_menu_item() then fills in.
                $itemId = $this->insertPost([
                    'post_type' => 'nav_menu_item',
                    'post_name' => "{$slug}-" . ($position + 1),
                    'post_title' => $item['label'],
                    'post_status' => 'draft',
                ], self::ID_BASE['nav_menu_item'] + $itemIndex++);

                $args = [
                    'menu-item-title' => $item['label'],
                    'menu-item-position' => $position + 1,
                    'menu-item-status' => 'publish',
                ];
                $args += isset($item['page'])
                    ? ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $this->pageIds[$item['page']]]
                    : ['menu-item-type' => 'post_type_archive', 'menu-item-object' => $item['archive']];

                $updated = wp_update_nav_menu_item($menuId, $itemId, $args);
                if (is_wp_error($updated) || $itemId !== $updated) {
                    WP_CLI::error("Menu {$slug} item {$item['label']} was not saved at its reserved id {$itemId}.");
                }
            }

            $locations[$menu['location']] = $menuId;
        }

        if ($itemIndex > self::IDS_PER_KIND['nav_menu_item']) {
            WP_CLI::error('More menu items than reserved ids.');
        }

        set_theme_mod('nav_menu_locations', $locations);
    }

    /** Replaces every widget with the block widgets SIDEBARS lists. */
    private function seedWidgets(): void
    {
        $widgets = ['_multiwidget' => 1];
        $sidebars = ['wp_inactive_widgets' => []];
        $number = self::FIRST_WIDGET;

        foreach (self::SIDEBARS as $id => $sidebar) {
            $sidebars[$id] = [];
            foreach ($sidebar['widgets'] as $text) {
                $widgets[$number] = ['content' => "<!-- wp:paragraph -->\n" . self::paragraph($text) . "\n<!-- /wp:paragraph -->"];
                $sidebars[$id][] = 'block-' . $number++;
            }
        }

        update_option('widget_block', $widgets);
        wp_set_sidebars_widgets($sidebars);
    }

    /**
     * Field keys by name in a field group of the model. Sub fields are
     * `<field>.<sub field>`, layout sub fields `<field>.<layout>.<sub field>`.
     *
     * @return array<string, string>
     */
    private function fieldKeys(string $groupKey): array
    {
        $keys = [];
        foreach ($this->group($groupKey)['fields'] as $field) {
            $keys[$field['name']] = $field['key'];
            foreach ($field['sub_fields'] ?? [] as $sub) {
                $keys["{$field['name']}.{$sub['name']}"] = $sub['key'];
            }
            foreach ($field['layouts'] ?? [] as $layout) {
                foreach ($layout['sub_fields'] as $sub) {
                    $keys["{$field['name']}.{$layout['name']}.{$sub['name']}"] = $sub['key'];
                }
            }
        }

        return $keys;
    }

    /** @return array<string, mixed> */
    private function group(string $key): array
    {
        foreach ($this->model['field_groups'] as $group) {
            if ($key === $group['key']) {
                return $group;
            }
        }

        WP_CLI::error("Field group {$key} is not in vwf_classic_model().");
    }

    /**
     * Saves a field by its key and stops unless get_field() reads back what
     * truth.json will claim. Values compare as strings, since a number field
     * may come back as either.
     *
     * @param int|string $postId A post id, or 'option' for the options page.
     */
    private function saveField(string $key, string $name, mixed $value, int|string $postId, mixed $readBack = null): void
    {
        update_field($key, $value, $postId);

        $got = get_field($name, $postId);
        $want = $readBack ?? $value;
        if (self::stringify($got) !== self::stringify($want)) {
            WP_CLI::error(sprintf('Field %s on %s reads back %s, not %s.', $name, (string) $postId, wp_json_encode($got), wp_json_encode($want)));
        }
    }

    private static function stringify(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map([self::class, 'stringify'], $value);
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    private function writeCredentials(): void
    {
        foreach (WP_Application_Passwords::get_user_application_passwords($this->adminId) as $item) {
            if (self::APP_PASSWORD_NAME === $item['name']) {
                WP_Application_Passwords::delete_application_password($this->adminId, $item['uuid']);
            }
        }

        $created = WP_Application_Passwords::create_new_application_password($this->adminId, ['name' => self::APP_PASSWORD_NAME]);
        if (is_wp_error($created)) {
            WP_CLI::error('Application Password: ' . $created->get_error_message());
        }

        if (false === file_put_contents(dirname(__DIR__) . '/.credentials', self::ADMIN_LOGIN . ':' . $created[0])) {
            WP_CLI::error('Could not write fixtures/.credentials.');
        }
    }

    private function writeTruth(): void
    {
        $json = json_encode(
            self::sortKeys($this->truth()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        if (false === file_put_contents(__DIR__ . '/truth.json', $json . "\n")) {
            WP_CLI::error('Could not write fixtures/classic/truth.json.');
        }
    }

    /**
     * Every fact the seed creates. Lists are sorted by id, slug or key; object
     * keys are sorted when written. Row ids appear only in edit paths, where
     * they are reserved, and there are no timestamps, so the file only changes
     * when the fixture does.
     *
     * @return array<string, mixed>
     */
    private function truth(): array
    {
        $model = $this->model;
        $registeredBy = 'mu-plugin:' . self::MU_PLUGIN_FILE;

        // Where each field, location and sidebar is used, from the file facts.
        $files = [];
        foreach (self::TEMPLATES as $slug => $template) {
            $files['tpl:' . $slug] = $template;
        }
        foreach (self::PARTS as $slug => $part) {
            $files['part:' . $slug] = $part;
        }
        $usedIn = static function (string $key, string $value) use ($files): array {
            $ids = array_keys(array_filter($files, static fn(array $file): bool => in_array($value, $file[$key] ?? [], true)));
            sort($ids, SORT_STRING);

            return $ids;
        };

        $projectPaths = array_map([self::class, 'projectPath'], array_keys(self::PROJECTS));
        $landingPages = array_keys(array_filter(self::PAGES, static fn(array $page): bool => self::LANDING === $page['template']));
        $defaultPages = array_keys(array_filter(
            self::PAGES,
            static fn(array $page, string $slug): bool => 'default' === $page['template'] && self::POSTS_PAGE !== $slug,
            ARRAY_FILTER_USE_BOTH
        ));
        $routes = [
            'archive-project' => ['/' . $model['post_types']['project']['args']['rewrite']['slug'] . '/'],
            'page' => array_map([self::class, 'path'], $defaultPages),
            'page-templates/landing' => array_map([self::class, 'path'], $landingPages),
            'single-project' => $projectPaths,
        ];

        $fieldDefs = static function (array $fields): array {
            $out = [];
            foreach ($fields as $field) {
                $def = ['key' => $field['name'], 'label' => $field['label'], 'type' => $field['type']];
                if (isset($field['sub_fields'])) {
                    $def['sub_fields'] = array_map(static fn(array $sub): array => ['key' => $sub['name'], 'label' => $sub['label'], 'type' => $sub['type']], $field['sub_fields']);
                }
                if (isset($field['layouts'])) {
                    $def['layouts'] = array_values(array_map(static fn(array $layout): array => [
                        'name' => $layout['name'],
                        'label' => $layout['label'],
                        'sub_fields' => array_map(static fn(array $sub): array => ['key' => $sub['name'], 'label' => $sub['label'], 'type' => $sub['type']], $layout['sub_fields']),
                    ], $field['layouts']));
                }
                if (isset($field['post_type'])) {
                    $def['post_types'] = array_map(static fn(string $type): string => 'pt:' . $type, $field['post_type']);
                }
                $out[] = $def;
            }

            return $out;
        };

        $fieldGroups = [];
        foreach ($model['field_groups'] as $group) {
            $rule = $group['location'][0][0];
            $fieldGroups[] = [
                'key' => $group['key'],
                'title' => $group['title'],
                'location' => "{$rule['param']} {$rule['operator']} {$rule['value']}",
                'fields' => $fieldDefs($group['fields']),
                'show_in_rest' => (bool) $group['show_in_rest'],
                'edit_path' => 'defined in code',
                'registered_by' => $registeredBy,
            ];
        }

        $postTypes = [];
        foreach ($model['post_types'] as $slug => $definition) {
            $args = $definition['args'];
            $fields = [];
            foreach ($model['field_groups'] as $group) {
                if ('post_type' !== $group['location'][0][0]['param'] || $slug !== $group['location'][0][0]['value']) {
                    continue;
                }
                foreach ($fieldDefs($group['fields']) as $def) {
                    $shownIn = $usedIn('reads', $def['key']);
                    $fields[] = $def + [
                        'id' => "field:{$slug}.{$def['key']}",
                        'source' => 'acf',
                        'group' => $group['title'],
                        'group_edit_path' => 'defined in code',
                        'show_in_rest' => (bool) $group['show_in_rest'],
                        'shown_in' => $shownIn,
                        'on_front_end' => [] !== $shownIn,
                    ];
                }
            }

            $taxonomies = [];
            foreach ($model['taxonomies'] as $taxonomy => $tax) {
                if (in_array($slug, $tax['object_types'], true)) {
                    $taxonomies[] = 'tax:' . $taxonomy;
                }
            }

            $postTypes[] = [
                'id' => 'pt:' . $slug,
                'slug' => $slug,
                'label' => $args['labels']['name'],
                'menu_name' => $args['labels']['menu_name'] ?? $args['labels']['name'],
                'public' => (bool) $args['public'],
                'has_public_url' => (bool) $args['public'],
                'has_archive' => (bool) $args['has_archive'],
                'archive_path' => '/' . $args['rewrite']['slug'] . '/',
                'rewrite_slug' => $args['rewrite']['slug'],
                'show_ui' => (bool) ($args['show_ui'] ?? $args['public']),
                'show_in_rest' => (bool) $args['show_in_rest'],
                'supports' => $args['supports'],
                'taxonomies' => $taxonomies,
                'fields' => self::sortList($fields),
                'published' => count(self::PROJECTS),
                'single_template' => 'tpl:single-' . $slug,
                'archive_template' => 'tpl:archive-' . $slug,
                'edit_path' => 'edit.php?post_type=' . $slug,
            ];
        }

        $taxonomies = [];
        foreach ($model['taxonomies'] as $slug => $tax) {
            $parent = $model['post_types'][$tax['object_types'][0]]['args']['labels'];
            $terms = [];
            foreach (self::PROJECT_TYPES as $termSlug => $name) {
                $projects = array_keys(array_filter(
                    self::PROJECTS,
                    static fn(array $project): bool => in_array($termSlug, $project['types'], true)
                ));
                $terms[] = ['slug' => $termSlug, 'name' => $name, 'projects' => $projects, 'project_count' => count($projects)];
            }

            $taxonomies[] = [
                'id' => 'tax:' . $slug,
                'slug' => $slug,
                'label' => $tax['args']['labels']['name'],
                'object_types' => array_map(static fn(string $type): string => 'pt:' . $type, $tax['object_types']),
                'public' => $tax['args']['public'],
                'hierarchical' => $tax['args']['hierarchical'],
                'show_in_rest' => $tax['args']['show_in_rest'],
                'terms' => $terms,
                'edit_path' => "edit-tags.php?taxonomy={$slug}&post_type={$tax['object_types'][0]}",
                // The same screen by its admin menu: the taxonomy's submenu under its post type.
                'menu_path' => ($parent['menu_name'] ?? $parent['name']) . ' > ' . ($tax['args']['labels']['menu_name'] ?? $tax['args']['labels']['name']),
            ];
        }

        $optionsPage = $model['options_page'];
        $companyGroup = $this->group('group_vwf_company_details');
        $companyFields = [];
        foreach ($companyGroup['fields'] as $field) {
            $shownIn = $usedIn('option_reads', $field['name']);
            $companyFields[] = [
                'key' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
                'option' => self::OPTION_PREFIX . $field['name'],
                'value' => self::COMPANY[$field['name']],
                'shown_in' => $shownIn,
                'on_front_end' => [] !== $shownIn,
            ];
        }

        $templates = [];
        foreach (self::TEMPLATES as $slug => $template) {
            $assigned = array_map(static fn(string $page): string => 'page:' . $page, 'page-templates/landing' === $slug ? $landingPages : []);
            $templates[] = [
                'id' => 'tpl:' . $slug,
                'slug' => $slug,
                'template_name' => $template['name'] ?? null,
                'theme' => $template['theme'],
                'file' => $slug . '.php',
                'refs' => $template['refs'],
                'reads' => $template['reads'] ?? [],
                'links' => $template['links'] ?? [],
                'shows_post_content' => $template['post'],
                'used_by_paths' => $routes[$slug],
                'assigned_to' => $assigned,
            ];
        }

        $parts = [];
        foreach (self::PARTS as $slug => $part) {
            $parts[] = [
                'id' => 'part:' . $slug,
                'slug' => $slug,
                'area' => $part['area'],
                'theme' => self::THEME,
                'overrides' => $part['overrides'],
                'file' => $slug . '.php',
                'refs' => $part['refs'],
                'reads' => $part['reads'] ?? [],
                'reads_options' => $part['option_reads'] ?? [],
                'links' => $part['links'] ?? [],
                'used_in' => $usedIn('refs', 'part:' . $slug),
            ];
        }

        $menuOf = [];
        foreach (self::MENUS as $slug => $menu) {
            $menuOf[$menu['location']] = 'nav:' . $slug;
        }

        $locations = [];
        foreach (self::LOCATIONS as $slug => $location) {
            $locations[] = [
                'id' => 'loc:' . $slug,
                'slug' => $slug,
                'label' => $location['label'],
                'registered_by' => 'theme:' . $location['theme'],
                'menu' => $menuOf[$slug] ?? null,
                'used_in' => $usedIn('refs', 'loc:' . $slug),
                'edit_path' => 'nav-menus.php?action=locations',
                'menu_path' => 'Appearance > Menus > Manage Locations',
            ];
        }

        $navigation = [];
        foreach (self::MENUS as $slug => $menu) {
            $navigation[] = [
                'id' => 'nav:' . $slug,
                'slug' => $slug,
                'title' => $menu['title'],
                'location' => 'loc:' . $menu['location'],
                'items' => array_map(fn(array $item): array => ['label' => $item['label'], 'path' => $this->itemPath($item)], $menu['items']),
                'item_count' => count($menu['items']),
                'edit_path' => 'nav-menus.php?action=edit&menu=' . self::reservedId('nav_menu', self::MENUS, $slug),
                // Every menu shares this screen, so a grader reading it also needs the menu's title.
                'menu_path' => 'Appearance > Menus',
            ];
        }

        $sidebars = [];
        foreach (self::SIDEBARS as $id => $sidebar) {
            $sidebars[] = [
                'id' => 'sidebar:' . $id,
                'slug' => $id,
                'name' => $sidebar['name'],
                'registered_by' => 'theme:' . $sidebar['theme'],
                'widgets' => $sidebar['widgets'],
                'widget_count' => count($sidebar['widgets']),
                'used_in' => $usedIn('refs', 'sidebar:' . $id),
                'edit_path' => 'widgets.php',
                'menu_path' => 'Appearance > Widgets',
            ];
        }

        $featuredOn = [];
        foreach (self::PAGES as $slug => $page) {
            foreach ($page['featured'] ?? [] as $project) {
                $featuredOn[$project][] = self::path($slug);
            }
        }

        $pages = [];
        foreach (self::PAGES as $slug => $page) {
            $pages[] = [
                'slug' => $slug,
                'title' => $page['title'],
                'path' => self::path($slug),
                'template' => 'default' === $page['template'] ? 'default' : substr($page['template'], 0, -strlen('.php')),
                'sections' => array_map(static fn(array $section): string => $section['layout'], $page['sections'] ?? []),
                'featured_projects' => $page['featured'] ?? [],
                'edit_path' => 'post.php?post=' . self::reservedId('page', self::PAGES, $slug) . '&action=edit',
            ];
        }

        $projects = [];
        foreach (self::PROJECTS as $slug => $project) {
            $projects[] = [
                'slug' => $slug,
                'title' => $project['title'],
                'path' => self::projectPath($slug),
                'client_name' => $project['client_name'],
                'completion_year' => $project['completion_year'],
                'project_types' => $project['types'],
                'phases' => array_map(static fn(array $phase): array => ['name' => $phase[0], 'weeks' => $phase[1]], $project['phases']),
                'phase_count' => count($project['phases']),
                'internal_job_code' => $project['internal_job_code'],
                'featured_on' => $featuredOn[$slug] ?? [],
                'template' => 'tpl:single-project',
                'edit_path' => 'post.php?post=' . self::reservedId('project', self::PROJECTS, $slug) . '&action=edit',
            ];
        }

        $posts = [];
        foreach (self::POSTS as $slug => $post) {
            $posts[] = [
                'slug' => $slug,
                'title' => $post['title'],
                'path' => '/' . $slug . '/',
                'date' => substr($post['date'], 0, 10),
            ];
        }

        $plugins = [];
        foreach (self::PLUGINS as $slug => $plugin) {
            $plugins[] = ['id' => 'plugin:' . $slug, 'slug' => $slug, 'name' => $plugin['name'], 'role' => $plugin['role']];
        }

        return [
            'format' => 'wayfinder-fixture-truth/1',
            'fixture' => 'classic',
            'site' => [
                'title' => self::SITE_TITLE,
                'theme' => self::THEME,
                'parent_theme' => self::PARENT_THEME,
                'theme_type' => 'classic',
                'permalink_structure' => self::PERMALINKS,
            ],
            'counts' => [
                'pt:page' => count(self::PAGES),
                'pt:post' => count(self::POSTS),
                'pt:project' => count(self::PROJECTS),
                'tax:project_type' => count(self::PROJECT_TYPES),
            ],
            'post_types' => self::sortList($postTypes),
            'taxonomies' => self::sortList($taxonomies),
            'field_groups' => self::sortList($fieldGroups, 'key'),
            'options_pages' => [[
                'id' => 'optpage:' . $optionsPage['menu_slug'],
                'slug' => $optionsPage['menu_slug'],
                'title' => $optionsPage['page_title'],
                'parent' => null,
                'field_groups' => [$companyGroup['title']],
                'fields' => self::sortList($companyFields, 'key'),
                'edit_path' => 'admin.php?page=' . $optionsPage['menu_slug'],
                // A top-level menu item, so its label is the page's menu title alone.
                'menu_path' => $optionsPage['menu_title'],
                'registered_by' => $registeredBy,
            ]],
            'templates' => self::sortList($templates),
            'template_parts' => self::sortList($parts),
            'menu_locations' => self::sortList($locations),
            'navigation' => self::sortList($navigation),
            'sidebars' => self::sortList($sidebars),
            'front_page' => ['page' => self::FRONT_PAGE, 'path' => '/'],
            'posts_page' => ['page' => self::POSTS_PAGE, 'path' => self::path(self::POSTS_PAGE)],
            'pages' => self::sortList($pages, 'slug'),
            'projects' => self::sortList($projects, 'slug'),
            'posts' => self::sortList($posts, 'slug'),
            'options' => self::sortList([
                ['id' => 'opt:blogname', 'value' => self::SITE_TITLE],
                ['id' => 'opt:page_for_posts', 'value' => self::POSTS_PAGE],
                ['id' => 'opt:page_on_front', 'value' => self::FRONT_PAGE],
                ['id' => 'opt:permalink_structure', 'value' => self::PERMALINKS],
                ['id' => 'opt:show_on_front', 'value' => 'page'],
                ['id' => 'opt:stylesheet', 'value' => self::THEME],
                ['id' => 'opt:template', 'value' => self::PARENT_THEME],
            ]),
            'plugins' => self::sortList($plugins),
            'mu_plugins' => [[
                'file' => self::MU_PLUGIN_FILE,
                'name' => 'Wayfinder classic fixture core',
                'registers' => ['optpage:' . $optionsPage['menu_slug'], 'pt:project', 'tax:project_type'],
            ]],
        ];
    }

    /**
     * @param array<string, mixed> $postarr
     */
    private function insertPost(array $postarr, int $reservedId): int
    {
        $for = "{$postarr['post_type']} {$postarr['post_name']}";
        $this->assertFree($reservedId, $for);

        $postarr += ['post_status' => 'publish', 'post_author' => $this->adminId];
        $postarr['meta_input'] = ($postarr['meta_input'] ?? []) + [self::TAG => 1];
        $postarr['import_id'] = $reservedId;

        $id = wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($id)) {
            WP_CLI::error("{$for}: " . $id->get_error_message());
        }

        if ($id !== $reservedId) {
            WP_CLI::error("WordPress gave {$for} id {$id}, not its reserved id {$reservedId}.");
        }

        return $id;
    }

    /**
     * Stops when a reserved id is held. The reset has already deleted every
     * row the seed creates, so whatever holds the id now is not the seed's.
     */
    private function assertFree(int $id, string $for): void
    {
        $holder = get_post($id);
        if (null === $holder) {
            return;
        }

        WP_CLI::error(sprintf(
            'Post id %d is reserved for %s, but %s %d ("%s") holds it, and the seed did not create it. Delete that post, or destroy the site and start a fresh one.',
            $id,
            $for,
            $holder->post_type,
            $holder->ID,
            $holder->post_title
        ));
    }

    /**
     * The reserved id of the row at `$slug` in a constant keyed by slug.
     *
     * @param array<string, mixed> $rows
     */
    private static function reservedId(string $kind, array $rows, string $slug): int
    {
        $index = array_search($slug, array_keys($rows), true);
        if (false === $index || $index >= self::IDS_PER_KIND[$kind]) {
            WP_CLI::error(sprintf('No reserved id for %s %s: it has %d ids from ID_BASE.', $kind, $slug, self::IDS_PER_KIND[$kind]));
        }

        return self::ID_BASE[$kind] + $index;
    }

    /**
     * @param array<string, string> $item
     */
    private function itemPath(array $item): string
    {
        if (isset($item['page'])) {
            return self::path($item['page']);
        }

        return '/' . $this->model['post_types'][$item['archive']]['args']['rewrite']['slug'] . '/';
    }

    private static function path(string $pageSlug): string
    {
        return self::FRONT_PAGE === $pageSlug ? '/' : '/' . $pageSlug . '/';
    }

    private static function projectPath(string $slug): string
    {
        return '/projects/' . $slug . '/';
    }

    /** Classic editor content: a plain paragraph, no block comments. */
    private static function paragraph(string $text): string
    {
        return '<p>' . esc_html($text) . '</p>';
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private static function sortList(array $list, string $key = 'id'): array
    {
        usort($list, static fn(array $a, array $b): int => strcmp((string) $a[$key], (string) $b[$key]));

        return $list;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map([self::class, 'sortKeys'], $value);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}

(new VwfClassicSeed(in_array(VwfClassicSeed::RELAUNCHED, $args ?? [], true)))->run();
