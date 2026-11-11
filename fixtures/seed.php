<?php

/**
 * Seeds the Wayfinder fixture site (Riverbend Plumbing) and writes
 * fixtures/truth.json, the benchmark's ground truth.
 *
 *     npx @wordpress/env run cli wp eval-file wp-content/plugins/voyager-wayfinder/fixtures/seed.php
 *
 * The site also hosts the classic fixture (fixtures/classic/seed.php), which
 * runs Secure Custom Fields in place of ACF and the vwf-classic theme. This seed
 * switches the site back first: ACF in place of Secure Custom Fields, then the
 * vwf-fixture theme. Each fixture's mu-plugin registers its content model only
 * while its own theme is active, and ACF cannot load in a process that has
 * loaded Secure Custom Fields, so after a switch the seed reruns itself in a
 * fresh WP-CLI process, where `init` registers this fixture's model.
 *
 * A full reset, so every run leaves the same site and the same manifest. Each
 * run deletes every page, post, service and team member and every service area
 * term, whatever created them, plus every row either fixture tagged
 * `_vwf_fixture` and any Site Editor copies of the fixture theme's templates
 * and parts. It then recreates the site with every row at a reserved id, so
 * edit paths never move. Because it deletes content it did not create, it
 * refuses to run unless the fixture mu-plugin has defined VWF_FIXTURE.
 *
 * truth.json is built from the constants in this file and from
 * vwf_fixture_model() in fixtures/mu-plugins/vwf-fixture-core.php, never by
 * reading the site back, so benchmark answers never come from the scanner under
 * test. The theme files cannot be generated from constants, so the seed parses
 * them and stops if what they reference differs from what truth.json claims.
 *
 * Also creates an Application Password named `vwf-bench` for admin and writes
 * fixtures/.credentials (gitignored) as `user:password` for the benchmark.
 *
 * No `declare(strict_types=1)` and no namespace: `wp eval-file` runs the file
 * through eval() after a `?>`, and both must be the first statement, so either
 * one is a fatal error there. The class name carries the fixture prefix instead.
 *
 * @package Voyager\Wayfinder
 */

if (!defined('ABSPATH') || !class_exists('WP_CLI')) {
    exit(1);
}

// Only the fixture mu-plugin defines this, so the reset below can never wipe a real site.
if (!defined('VWF_FIXTURE')) {
    WP_CLI::error('VWF_FIXTURE is not defined, so this is not the Wayfinder fixture site. The seed deletes every page, post, service and team member, and runs only where fixtures/mu-plugins/vwf-fixture-core.php is loaded (see the mappings in .wp-env.json).');
}

final class VwfFixtureSeed
{
    /** The argument the seed passes to its own rerun. */
    public const RELAUNCHED = 'relaunched';

    private const TAG = '_vwf_fixture';

    private const SITE_TITLE = 'Riverbend Plumbing';

    private const PERMALINKS = '/%postname%/';

    private const THEME = 'vwf-fixture';

    private const PARENT_THEME = 'twentytwentyfive';

    private const ADMIN_LOGIN = 'admin';

    private const APP_PASSWORD_NAME = 'vwf-bench';

    /**
     * Row ids the theme files reference. core/navigation and core/block only
     * take a post id, and theme files are static, so the seed reserves these
     * with `import_id` and stops if one is taken.
     */
    private const NAV_PRIMARY_ID = 90001;

    private const NAV_FOOTER_ID = 90002;

    private const PATTERN_ID = 90003;

    /**
     * Every other row the seed creates is reserved too, so edit paths such as
     * route:page-contact's come back the same on every reseed. Each kind owns
     * IDS_PER_KIND ids from its base, in the order of its constant: the contact
     * page is 90012 and drain cleaning is 90020.
     */
    private const ID_BASE = [
        'page' => 90010,
        'service' => 90020,
        'team_member' => 90030,
        'post' => 90040,
        'attachment' => 90050,
        'edit_safety_page' => 90060,
        'edit_safety_2_page' => 90080,
    ];

    /** The synced pattern the edit-safety pages place. */
    private const OFFER_PATTERN_ID = 90070;

    /** The synced pattern the edit-safety-2 pages place. */
    private const NOTICE_PATTERN_ID = 90071;

    private const IDS_PER_KIND = 10;

    private const BUSINESS_INFO = [
        'address' => '120 Mill Race Rd, Fredericksburg, VA',
        'hours' => 'Mon to Fri 7am to 6pm',
        'phone' => '(540) 555-0142',
    ];

    private const SERVICE_AREAS = [
        'culpeper' => 'Culpeper',
        'fredericksburg' => 'Fredericksburg',
        'king-george' => 'King George',
        'spotsylvania' => 'Spotsylvania',
        'stafford' => 'Stafford',
    ];

    private const SERVICES = [
        'drain-cleaning' => [
            'title' => 'Drain cleaning',
            'price_from' => 149,
            'duration' => '1 to 2 hours',
            'internal_sku' => 'RB-DRN-01',
            'areas' => ['fredericksburg', 'stafford'],
            'text' => 'We clear slow and blocked drains in kitchens, bathrooms and laundry rooms, then camera check the line.',
        ],
        'emergency-plumbing' => [
            'title' => 'Emergency plumbing',
            'price_from' => 225,
            'duration' => 'Same day',
            'internal_sku' => 'RB-EMR-06',
            'areas' => ['culpeper', 'fredericksburg', 'king-george', 'spotsylvania', 'stafford'],
            'text' => 'Burst pipes, overflowing toilets and no water at all. A plumber is on the way the same day.',
        ],
        'fixture-installation' => [
            'title' => 'Fixture installation',
            'price_from' => 120,
            'duration' => '1 to 3 hours',
            'internal_sku' => 'RB-FIX-05',
            'areas' => ['spotsylvania'],
            'text' => 'Faucets, sinks, toilets and showers fitted and tested. Bring your own fixture or pick one with us.',
        ],
        'leak-detection' => [
            'title' => 'Leak detection',
            'price_from' => 189,
            'duration' => '2 hours',
            'internal_sku' => 'RB-LEK-03',
            'areas' => ['king-george', 'stafford'],
            'text' => 'Acoustic and thermal leak finding behind walls and under slabs, so we open only what we have to.',
        ],
        'sewer-line-repair' => [
            'title' => 'Sewer line repair',
            'price_from' => 950,
            'duration' => '1 to 2 days',
            'internal_sku' => 'RB-SWR-04',
            'areas' => ['culpeper', 'spotsylvania'],
            'text' => 'Trenchless repair where the ground allows it, and a full replacement where it does not.',
        ],
        'water-heater-repair' => [
            'title' => 'Water heater repair',
            'price_from' => 179,
            'duration' => '2 to 4 hours',
            'internal_sku' => 'RB-WHR-02',
            'areas' => ['fredericksburg'],
            'text' => 'Tank and tankless units repaired, flushed and adjusted. Most parts are on the truck.',
        ],
    ];

    private const TEAM = [
        'dana-whitfield' => [
            'title' => 'Dana Whitfield',
            'role' => 'Owner and master plumber',
            'linkedin' => 'https://example.com/linkedin/dana-whitfield',
        ],
        'luis-ortega' => [
            'title' => 'Luis Ortega',
            'role' => 'Service plumber',
            'linkedin' => 'https://example.com/linkedin/luis-ortega',
        ],
        'priya-nair' => [
            'title' => 'Priya Nair',
            'role' => 'Office manager',
            'linkedin' => 'https://example.com/linkedin/priya-nair',
        ],
        'sam-becker' => [
            'title' => 'Sam Becker',
            'role' => 'Apprentice plumber',
            'linkedin' => 'https://example.com/linkedin/sam-becker',
        ],
    ];

    /** `cta` appends the synced CTA banner to the page content. */
    private const PAGES = [
        'about' => [
            'title' => 'About',
            'template' => 'default',
            'text' => [
                'Riverbend Plumbing has served the Fredericksburg region since 2009.',
                'Every plumber on the team is licensed in Virginia, and every job comes with a written quote first.',
            ],
            'cta' => true,
        ],
        'blog' => [
            'title' => 'Blog',
            'template' => 'default',
            'text' => [],
            'cta' => false,
        ],
        'contact' => [
            'title' => 'Contact',
            'template' => 'page-contact',
            'text' => ['Call us or send a message and we will get back to you.'],
            'cta' => false,
        ],
        'home' => [
            'title' => 'Home',
            'template' => 'default',
            'text' => ['Licensed plumbers for homes across the Fredericksburg region. Upfront prices, tidy work, same day help.'],
            'cta' => true,
        ],
    ];

    private const FRONT_PAGE = 'home';

    private const POSTS_PAGE = 'blog';

    private const POSTS = [
        'how-to-find-your-main-water-shut-off' => [
            'title' => 'How to find your main water shut-off',
            'date' => '2026-06-04 09:00:00',
            'text' => 'Most homes have the main valve where the water line enters, often in a basement, crawl space or garage.',
        ],
        'signs-your-water-heater-needs-repair' => [
            'title' => 'Signs your water heater needs repair',
            'date' => '2026-07-09 09:00:00',
            'text' => 'Rusty water, popping sounds and short bursts of hot water usually mean sediment or a failing element.',
        ],
        'what-not-to-put-down-a-kitchen-drain' => [
            'title' => 'What not to put down a kitchen drain',
            'date' => '2026-08-13 09:00:00',
            'text' => 'Grease, coffee grounds and rice cause most of the kitchen clogs we clear.',
        ],
        'winterizing-outdoor-faucets' => [
            'title' => 'Winterizing outdoor faucets',
            'date' => '2026-09-17 09:00:00',
            'text' => 'Disconnect hoses, shut the inside valve and open the outdoor tap to drain it before the first freeze.',
        ],
    ];

    /** Items point at a page slug or at a post type archive. */
    private const NAVIGATION = [
        'footer' => [
            'id' => self::NAV_FOOTER_ID,
            'title' => 'Footer',
            'items' => [
                ['label' => 'About', 'page' => 'about'],
                ['label' => 'Contact', 'page' => 'contact'],
            ],
        ],
        'primary' => [
            'id' => self::NAV_PRIMARY_ID,
            'title' => 'Primary',
            'items' => [
                ['label' => 'Home', 'page' => 'home'],
                ['label' => 'About', 'page' => 'about'],
                ['label' => 'Services', 'archive' => 'service'],
                ['label' => 'Blog', 'page' => 'blog'],
                ['label' => 'Contact', 'page' => 'contact'],
            ],
        ],
    ];

    private const PATTERN = [
        'slug' => 'cta-banner',
        'title' => 'CTA banner',
        'heading' => 'Need a plumber today?',
        'text' => 'Licensed, insured and on call across the Fredericksburg region.',
        'button' => 'Book a visit',
        'button_page' => 'contact',
    ];

    /**
     * Rows for the edit-safety question set (bench/questions/fixture-edit-safety.yaml):
     * locked blocks, text with more than one home, class and List View names,
     * and anchors to check before a write. They are new rows at reserved ids
     * 90060 to 90070, so no fact an earlier question reads moves. None of them
     * is in a menu, uses the CTA banner or the page-contact template, or shows
     * a business info value.
     *
     * Each page is a list of blocks. A block is `type` (paragraph, heading,
     * group, buttons, button, or pattern for the offer pattern) with optional
     * `text`, `name` (metadata.name, its List View name), `class` (className),
     * `lock` (the block's own lock), `template_lock` (templateLock), `url`
     * (a button's link page) and `inner`. renderBlocks() writes the markup and
     * editSafetyTruth() reads the facts from these same arrays.
     */
    private const EDIT_SAFETY_PAGES = [
        'commercial' => [
            'title' => 'Commercial plumbing',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'We look after restaurants, offices and rental properties across the region.'],
                ['type' => 'paragraph', 'text' => 'Founded in 2009, we have kept local businesses running ever since.'],
                ['type' => 'paragraph', 'text' => 'Ask about after-hours service.'],
                ['type' => 'pattern'],
                ['type' => 'paragraph', 'text' => 'Ask about after-hours service.'],
                ['type' => 'buttons', 'inner' => [
                    ['type' => 'button', 'name' => 'Survey button', 'class' => 'is-style-outline', 'text' => 'Request a site survey', 'url' => 'contact'],
                ]],
            ],
        ],
        'financing' => [
            'title' => 'Financing',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Spread the cost of a bigger repair over a year.'],
                ['type' => 'pattern'],
                ['type' => 'paragraph', 'class' => 'hide-mobile', 'text' => 'Apply by phone or in person.'],
                ['type' => 'paragraph', 'name' => 'Approval time', 'text' => 'Approval usually takes about 10 minutes.'],
            ],
        ],
        'maintenance-plan' => [
            'title' => 'Maintenance plan',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Book your annual tune-up'],
                ['type' => 'paragraph', 'text' => 'Our yearly maintenance plan keeps your plumbing in shape between visits.'],
                ['type' => 'group', 'name' => 'Plan card', 'class' => 'plan-card', 'template_lock' => 'contentOnly', 'inner' => [
                    ['type' => 'heading', 'name' => 'Plan name', 'text' => 'Annual plan'],
                    ['type' => 'paragraph', 'name' => 'Plan price', 'text' => '$199 a year, billed once.'],
                    ['type' => 'paragraph', 'name' => 'Plan inclusions', 'text' => 'Includes one water heater flush and a whole-home leak check.'],
                ]],
                ['type' => 'group', 'name' => 'Plan terms', 'template_lock' => 'all', 'inner' => [
                    ['type' => 'paragraph', 'name' => 'Renewal terms', 'text' => 'Plans renew each year unless you cancel in writing.'],
                    ['type' => 'paragraph', 'name' => 'Refund terms', 'text' => 'Cancel any time before the renewal date for a full refund.'],
                ]],
                ['type' => 'paragraph', 'name' => 'License notice', 'lock' => ['move' => true, 'remove' => true], 'text' => 'Every visit is done by a plumber licensed in Virginia.'],
                ['type' => 'paragraph', 'name' => 'Financing line', 'text' => '0% financing for 12 months on approved credit.'],
                ['type' => 'paragraph', 'class' => 'hide-mobile', 'text' => 'Weekend visits cost $40 extra.'],
                ['type' => 'buttons', 'inner' => [
                    ['type' => 'button', 'class' => 'is-style-outline', 'text' => 'Book your annual tune-up', 'url' => 'contact'],
                ]],
            ],
        ],
    ];

    /** The synced pattern placed on the commercial and financing pages. */
    private const OFFER_PATTERN = [
        'slug' => 'financing-offer',
        'title' => 'Financing offer',
        'blocks' => [
            ['type' => 'group', 'class' => 'promo-strip', 'inner' => [
                ['type' => 'paragraph', 'text' => '0% financing for 12 months on approved credit.'],
            ]],
        ],
    ];

    /**
     * Text whose every home truth.json lists. findTextHomes() searches all
     * content the seed writes and the theme files, and stops if a tracked
     * text turns up anywhere it cannot place on a URL.
     */
    private const TRACKED_TEXTS = [
        'book-tune-up' => 'Book your annual tune-up',
        'financing-offer' => '0% financing for 12 months on approved credit.',
        'founding-year' => '2009',
    ];

    /**
     * Anchors a writer took before the content changed: the block's entity,
     * its parse-order path then, and the block as it was. truth.json gives
     * each one's fingerprint (computed here as docs/ai-context-format.md
     * defines it) and where that fingerprint sits now.
     */
    private const ANCHORS = [
        'after-hours' => [
            'page' => 'commercial',
            'path' => '3',
            'block' => ['type' => 'paragraph', 'text' => 'Ask about after-hours service.'],
        ],
        'financing-line' => [
            'page' => 'maintenance-plan',
            'path' => '4',
            'block' => ['type' => 'paragraph', 'name' => 'Financing line', 'text' => '0% financing for 12 months on approved credit.'],
        ],
        'inspection-offer' => [
            'page' => 'maintenance-plan',
            'path' => '6',
            'block' => ['type' => 'paragraph', 'name' => 'Inspection offer', 'text' => 'Plans include a free first-year inspection.'],
        ],
        'plan-price' => [
            'page' => 'maintenance-plan',
            'path' => '2.1',
            'block' => ['type' => 'paragraph', 'name' => 'Plan price', 'text' => '$179 a year, billed once.'],
        ],
        'renewal-terms' => [
            'page' => 'maintenance-plan',
            'path' => '3.0',
            'block' => ['type' => 'paragraph', 'name' => 'Renewal terms', 'text' => 'Plans renew each year unless you cancel in writing.'],
        ],
    ];

    /**
     * The role Wayfinder answers "can the client edit this" for: option
     * vwf_client_role, unset, so its default. checkClientRole() stops if the
     * site's role differs from these capabilities.
     */
    private const CLIENT_ROLE = 'editor';

    private const CLIENT_ROLE_CAPS = [
        'edit_others_pages' => true,
        'edit_published_pages' => true,
        'edit_theme_options' => false,
        'manage_options' => false,
    ];

    /** A page's own edit screen needs these for a page another user wrote. */
    private const PAGE_EDIT_CAPS = ['edit_others_pages', 'edit_published_pages'];

    /**
     * Things a client asks to change. `needs` is the capability the edit
     * screen needs: Business Info is added with manage_options
     * (vwf_fixture_admin_menu()), the Site Editor needs edit_theme_options.
     * A page block also needs its text to be editable under its lock.
     */
    private const CLIENT_ASKS = [
        'footer-layout' => ['part' => 'footer', 'needs' => ['edit_theme_options']],
        'footer-phone' => ['binding_key' => 'phone', 'needs' => ['manage_options']],
        'plan-price' => ['page' => 'maintenance-plan', 'block' => 'Plan price'],
        'renewal-terms' => ['page' => 'maintenance-plan', 'block' => 'Renewal terms'],
    ];

    /**
     * Rows for the held-out edit-safety-2 set (
     * bench/questions/fixture-edit-safety-2.yaml), written before any fix
     * tuned on the first set. Its layouts lock differently: a rate card
     * under templateLock insert, a line in it whose own lock lets it be
     * removed, a block locked against moving and removal, steps under
     * templateLock all, and an unlocked group (templateLock false) inside a
     * locked one. New rows at reserved ids 90080 to 90082 and 90071.
     *
     * editSafetyTruth() never reads them, and editSafety2Truth() stops if
     * they reuse a class, List View name or tracked text of the first set,
     * so no edit_safety answer moves. Like the first set's rows they sit in no
     * menu, use neither the CTA banner nor the page-contact template, and
     * show no business info value. Blocks take the keys EDIT_SAFETY_PAGES
     * documents, and a pattern block names its pattern by `pattern`.
     */
    private const EDIT_SAFETY_2_PAGES = [
        'pricing' => [
            'title' => 'Pricing',
            'blocks' => [
                ['type' => 'heading', 'text' => 'What a visit costs'],
                ['type' => 'paragraph', 'text' => 'Every price below is for a standard home visit.'],
                ['type' => 'group', 'name' => 'Rate card', 'class' => 'rate-card', 'template_lock' => 'insert', 'inner' => [
                    ['type' => 'paragraph', 'name' => 'Call-out fee', 'text' => 'Call-out fee: $89, waived when you book the repair.'],
                    ['type' => 'paragraph', 'name' => 'Labor rate', 'text' => 'Labor: $135 an hour after the first hour.'],
                    ['type' => 'paragraph', 'name' => 'Night rate', 'lock' => ['remove' => false], 'text' => 'Nights and Sundays are billed at time and a half.'],
                ]],
                ['type' => 'paragraph', 'name' => 'Price promise', 'lock' => ['move' => true, 'remove' => true], 'text' => 'The price we quote is the price you pay.'],
                ['type' => 'pattern', 'pattern' => 'same-day-service'],
                ['type' => 'paragraph', 'class' => 'small-print', 'text' => 'Prices include Virginia sales tax where it applies.'],
                ['type' => 'buttons', 'inner' => [
                    ['type' => 'button', 'name' => 'Quote button', 'class' => 'quote-cta', 'text' => 'Ask for a quote', 'url' => 'contact'],
                ]],
            ],
        ],
        'guarantee' => [
            'title' => 'Our guarantee',
            'blocks' => [
                ['type' => 'heading', 'text' => 'Our workmanship guarantee'],
                ['type' => 'paragraph', 'text' => 'Every repair is guaranteed for two years, parts and labor.'],
                ['type' => 'group', 'name' => 'Claim steps', 'class' => 'claim-steps', 'template_lock' => 'all', 'inner' => [
                    ['type' => 'paragraph', 'name' => 'Step one', 'text' => 'Call us within two years of the repair.'],
                    ['type' => 'paragraph', 'name' => 'Step two', 'text' => 'We inspect the work at no charge.'],
                    ['type' => 'paragraph', 'name' => 'Step three', 'text' => 'If our work failed, we fix it free.'],
                ]],
                ['type' => 'paragraph', 'text' => 'All work is done by plumbers licensed in Virginia.'],
                ['type' => 'pattern', 'pattern' => 'same-day-service'],
                ['type' => 'paragraph', 'class' => 'guarantee-note', 'text' => 'The guarantee does not cover frozen pipes.'],
            ],
        ],
        'emergencies' => [
            'title' => 'Emergencies',
            'blocks' => [
                ['type' => 'paragraph', 'text' => 'Water where it should not be? Shut off the main valve first.'],
                ['type' => 'group', 'name' => 'Emergency checklist', 'template_lock' => 'all', 'inner' => [
                    ['type' => 'heading', 'name' => 'Checklist title', 'text' => 'While you wait for us'],
                    ['type' => 'group', 'name' => 'Your own notes', 'template_lock' => false, 'inner' => [
                        ['type' => 'paragraph', 'name' => 'Valve note', 'text' => 'Our main valve is in the crawl space.'],
                    ]],
                ]],
                ['type' => 'paragraph', 'text' => 'Same-day service on calls booked before noon.'],
                ['type' => 'paragraph', 'name' => 'After-dark line', 'class' => 'night-note', 'text' => 'After 8pm, leave a message and we call back within the hour.'],
                ['type' => 'paragraph', 'class' => 'night-note', 'text' => 'Night visits are billed at time and a half.'],
                ['type' => 'paragraph', 'class' => 'small-print', 'text' => 'Holiday visits are billed at time and a half too.'],
            ],
        ],
    ];

    /** The synced pattern placed on the pricing and guarantee pages. /emergencies/ has a plain copy of its line. */
    private const NOTICE_PATTERN = [
        'slug' => 'same-day-service',
        'title' => 'Same-day service',
        'blocks' => [
            ['type' => 'group', 'class' => 'notice-bar', 'inner' => [
                ['type' => 'paragraph', 'text' => 'Same-day service on calls booked before noon.'],
            ]],
        ],
    ];

    /** Text whose every home edit_safety_2 lists, on every page and pattern the seed writes. */
    private const TRACKED_TEXTS_2 = [
        'licensed-in-virginia' => 'licensed in Virginia',
        'same-day-service' => 'Same-day service on calls booked before noon.',
        'time-and-a-half' => 'time and a half',
    ];

    /** Anchors taken on the edit-safety-2 pages before they reached their current state, as ANCHORS. */
    private const ANCHORS_2 = [
        'emergency-line' => [
            'page' => 'emergencies',
            'path' => '2',
            'block' => ['type' => 'paragraph', 'text' => 'Call our emergency line any time, day or night.'],
        ],
        'frozen-pipes' => [
            'page' => 'guarantee',
            'path' => '4',
            'block' => ['type' => 'paragraph', 'text' => 'The guarantee does not cover frozen pipes.'],
        ],
        'labor-rate' => [
            'page' => 'pricing',
            'path' => '2.1',
            'block' => ['type' => 'paragraph', 'name' => 'Labor rate', 'text' => 'Labor: $125 an hour after the first hour.'],
        ],
        'night-rate' => [
            'page' => 'pricing',
            'path' => '2.2',
            'block' => ['type' => 'paragraph', 'name' => 'Night rate', 'text' => 'Nights and Sundays are billed at time and a half.'],
        ],
        'price-promise' => [
            'page' => 'pricing',
            'path' => '4',
            'block' => ['type' => 'paragraph', 'name' => 'Price promise', 'text' => 'The price we quote is the price you pay.'],
        ],
        'valve-note' => [
            'page' => 'emergencies',
            'path' => '1.1',
            'block' => ['type' => 'paragraph', 'name' => 'Valve note', 'text' => 'Our main valve is in the crawl space.'],
        ],
    ];

    /**
     * Capabilities the edit-safety-2 asks read beyond CLIENT_ROLE_CAPS.
     * checkClientRole() checks these too.
     */
    private const CLIENT_ROLE_CAPS_2 = [
        'edit_others_posts' => true,
        'edit_published_posts' => true,
    ];

    /**
     * Things a client asks to change, as CLIENT_ASKS. A menu, the site
     * title and a template need capabilities the Editor role lacks; a synced
     * pattern another user published needs the post capabilities (wp_block
     * maps edit_post to them).
     */
    private const CLIENT_ASKS_2 = [
        'contact-layout' => ['template' => 'page-contact', 'needs' => ['edit_theme_options']],
        'cta-banner' => ['pattern' => self::PATTERN_ID, 'needs' => ['edit_others_posts', 'edit_published_posts']],
        'main-menu' => ['nav' => self::NAV_PRIMARY_ID, 'needs' => ['edit_theme_options']],
        'night-rate' => ['page' => 'pricing', 'block' => 'Night rate'],
        'site-title' => ['edit_path' => 'options-general.php', 'needs' => ['manage_options']],
    ];

    /**
     * What each fixture theme file references, as truth.json states it.
     * checkThemeFiles() parses the files and stops on any difference.
     */
    private const TEMPLATES = [
        'page-contact' => [
            'refs' => ['part:footer', 'part:header'],
        ],
        'single-service' => [
            'refs' => ['part:footer', 'part:header', 'pat:cta-banner'],
            'post_type' => 'service',
        ],
    ];

    private const PARTS = [
        'footer' => [
            'area' => 'footer',
            'refs' => ['bind:vwf-fixture/business-info.address', 'bind:vwf-fixture/business-info.phone', 'nav:footer'],
        ],
        'header' => [
            'area' => 'header',
            'refs' => ['nav:primary'],
        ],
    ];

    /**
     * The active plugins, by folder slug. .wp-env.json installs and activates
     * them, and checkActivePlugins() stops if the site runs any other set.
     * The MCP Adapter is active in every benchmark condition, because the
     * adapter condition talks to it and every condition answers the same key.
     */
    private const PLUGINS = [
        'advanced-custom-fields' => ['name' => 'Advanced Custom Fields', 'role' => 'fields'],
        'mcp-adapter' => ['name' => 'MCP Adapter', 'role' => 'ai'],
        'voyager-wayfinder' => ['name' => 'Voyager Wayfinder', 'role' => 'other'],
    ];

    /** The classic fixture's fields plugin, which cannot be active beside ACF. */
    private const SWAPPED_PLUGINS = ['secure-custom-fields'];

    private const FIELDS_PLUGIN = 'advanced-custom-fields';

    private const MU_PLUGIN_FILE = 'vwf-fixture-core.php';

    /** A 1x1 PNG, shared by every team member as the ACF photo. */
    private const PHOTO_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** @var array<string, mixed> */
    private array $model;

    private int $adminId = 0;

    /** @var array<string, int> page slug => post id */
    private array $pageIds = [];

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
        $this->configureSite();
        $this->seedPattern();
        $this->seedOfferPattern();
        $this->seedNoticePattern();
        $this->seedPages();
        $this->seedEditSafetyPages();
        $this->seedEditSafety2Pages();
        $this->seedServices($this->seedServiceAreas());
        $this->seedTeam();
        $this->seedPosts();
        $this->seedNavigation();

        // The CPT permastructs were built during init, before the permalink
        // structure was set on a first run. Dropping the stored rules makes the
        // next request rebuild them with every rewrite in place.
        delete_option('rewrite_rules');

        $this->writeCredentials();
        $this->writeTruth();

        WP_CLI::success(sprintf(
            'Seeded %s: %d services, %d service areas, %d team members, %d pages, %d posts, 2 menus, 3 synced patterns. Wrote fixtures/truth.json and fixtures/.credentials.',
            self::SITE_TITLE,
            count(self::SERVICES),
            count(self::SERVICE_AREAS),
            count(self::TEAM),
            count(self::PAGES) + count(self::EDIT_SAFETY_PAGES) + count(self::EDIT_SAFETY_2_PAGES),
            count(self::POSTS)
        ));
    }

    /**
     * Makes this the block fixture's site: ACF in place of Secure Custom
     * Fields, and the vwf-fixture theme. Registration happens during `init`,
     * and ACF cannot load beside Secure Custom Fields, so after a switch the
     * seed reruns itself in a fresh WP-CLI process and this one stops.
     *
     * @return bool True when it switched and the rerun seeded the site.
     */
    private function switchSite(): bool
    {
        $active = array_map('dirname', (array) get_option('active_plugins', []));
        $out = array_values(array_intersect(self::SWAPPED_PLUGINS, $active));
        $in = in_array(self::FIELDS_PLUGIN, $active, true) ? [] : [self::FIELDS_PLUGIN];

        if (!$out && !$in && self::THEME === get_stylesheet()) {
            return false;
        }

        if ($this->relaunched) {
            WP_CLI::error(sprintf('Still not the block fixture site after switching: theme %s, active plugins [%s].', get_stylesheet(), implode(', ', $active)));
        }

        $theme = wp_get_theme(self::THEME);
        if (!$theme->exists() || self::PARENT_THEME !== $theme->get_template()) {
            WP_CLI::error(sprintf('Theme %s (child of %s) is not installed. Check the mappings in .wp-env.json.', self::THEME, self::PARENT_THEME));
        }

        // Each in its own process, so ACF never loads beside Secure Custom Fields.
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
        if (!function_exists('vwf_fixture_model')) {
            WP_CLI::error('The vwf-fixture-core mu-plugin is not loaded. Check the mappings in .wp-env.json.');
        }

        if (!function_exists('update_field')) {
            WP_CLI::error('Advanced Custom Fields is not active. It is listed in .wp-env.json plugins.');
        }

        $this->checkActivePlugins();
        $this->checkClientRole();

        $theme = wp_get_theme(self::THEME);
        if (!$theme->exists() || self::PARENT_THEME !== $theme->get_template()) {
            WP_CLI::error(sprintf('Theme %s (child of %s) is not installed. Check the mappings in .wp-env.json.', self::THEME, self::PARENT_THEME));
        }

        if (self::THEME !== get_stylesheet()) {
            WP_CLI::error(sprintf('The active theme is %s, not %s.', get_stylesheet(), self::THEME));
        }

        $admin = get_user_by('login', self::ADMIN_LOGIN);
        if (false === $admin) {
            WP_CLI::error(sprintf('User %s not found.', self::ADMIN_LOGIN));
        }

        $this->adminId = $admin->ID;
        $this->model = vwf_fixture_model();

        // Run as admin so kses leaves block markup alone.
        wp_set_current_user($this->adminId);

        $this->checkThemeFiles($theme->get_stylesheet_directory());
    }

    /**
     * Fails when the active plugins differ from PLUGINS, so the plugin facts
     * in truth.json cannot drift from the site.
     */
    private function checkActivePlugins(): void
    {
        $active = array_map('dirname', (array) get_option('active_plugins', []));
        sort($active);
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
     * Fails when the client role's capabilities differ from CLIENT_ROLE_CAPS,
     * so the client_can answers in truth.json cannot drift from the site.
     */
    private function checkClientRole(): void
    {
        $role = get_role(self::CLIENT_ROLE);
        if (null === $role) {
            WP_CLI::error(sprintf('Role %s not found.', self::CLIENT_ROLE));
        }

        foreach (['CLIENT_ROLE_CAPS' => self::CLIENT_ROLE_CAPS, 'CLIENT_ROLE_CAPS_2' => self::CLIENT_ROLE_CAPS_2] as $constant => $caps) {
            foreach ($caps as $cap => $expected) {
                if ($role->has_cap($cap) !== $expected) {
                    WP_CLI::error(sprintf('The %s role %s %s, but the seed declares otherwise in %s.', self::CLIENT_ROLE, $expected ? 'lacks' : 'has', $cap, $constant));
                }
            }
        }
    }

    /**
     * Fails when a theme file references something other than what
     * TEMPLATES and PARTS declare, so truth.json cannot drift from the files.
     */
    private function checkThemeFiles(string $themeDir): void
    {
        $files = [];
        foreach (self::TEMPLATES as $slug => $template) {
            $files["templates/{$slug}.html"] = $template['refs'];
        }
        foreach (self::PARTS as $slug => $part) {
            $files["parts/{$slug}.html"] = $part['refs'];
        }

        foreach ($files as $file => $expected) {
            $markup = file_get_contents($themeDir . '/' . $file);
            if (false === $markup) {
                WP_CLI::error("Cannot read theme file {$file}.");
            }

            $found = $this->collectRefs(parse_blocks($markup));
            if ($found !== $expected) {
                WP_CLI::error(sprintf(
                    '%s references [%s], but the seed declares [%s]. Update the file or the TEMPLATES and PARTS constants.',
                    $file,
                    implode(', ', $found),
                    implode(', ', $expected)
                ));
            }
        }
    }

    /**
     * Ids of the parts, menus, patterns and bindings a block tree references.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return list<string>
     */
    private function collectRefs(array $blocks): array
    {
        $navSlugs = [];
        foreach (self::NAVIGATION as $slug => $menu) {
            $navSlugs[$menu['id']] = $slug;
        }

        $refs = [];
        foreach ($blocks as $block) {
            $attrs = $block['attrs'] ?? [];
            $name = $block['blockName'] ?? '';

            if ('core/template-part' === $name) {
                $refs[] = 'part:' . $attrs['slug'];
            } elseif ('core/navigation' === $name) {
                $refs[] = 'nav:' . ($navSlugs[$attrs['ref'] ?? 0] ?? '#' . ($attrs['ref'] ?? 'none'));
            } elseif ('core/block' === $name) {
                $refs[] = self::PATTERN_ID === ($attrs['ref'] ?? 0) ? 'pat:' . self::PATTERN['slug'] : '#' . ($attrs['ref'] ?? 'none');
            }

            foreach ($attrs['metadata']['bindings'] ?? [] as $binding) {
                $refs[] = 'bind:' . $binding['source'] . '.' . ($binding['args']['key'] ?? '');
            }

            $refs = array_merge($refs, $this->collectRefs($block['innerBlocks'] ?? []));
        }

        $refs = array_values(array_unique($refs));
        sort($refs, SORT_STRING);

        return $refs;
    }

    private function reset(): void
    {
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

        // The seed's other rows (the team photo, the pattern and the menus) and
        // every row the classic fixture tagged, whose types are not registered here.
        global $wpdb;
        $tagged = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::TAG)));

        // Site Editor copies of the fixture theme's templates and parts.
        $customized = get_posts([
            'post_type' => ['wp_template', 'wp_template_part'],
            'post_status' => array_keys(get_post_stati()),
            'tax_query' => [['taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => self::THEME]],
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);

        foreach (array_unique(array_merge($owned, $tagged, $customized)) as $id) {
            $type = get_post_type($id);
            if (false === $type) {
                continue; // Deleted with its parent.
            }

            $deleted = 'attachment' === $type
                ? wp_delete_attachment((int) $id, true)
                : wp_delete_post((int) $id, true);

            if (!$deleted) {
                WP_CLI::error("Could not delete post {$id}.");
            }
        }

        // Every term, so one added by hand cannot survive a reseed either.
        foreach (array_keys($this->model['taxonomies']) as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy,
                'hide_empty' => false,
                'fields' => 'ids',
            ]);

            foreach (is_array($terms) ? $terms : [] as $termId) {
                wp_delete_term((int) $termId, $taxonomy);
            }
        }
    }

    private function configureSite(): void
    {
        update_option('blogname', self::SITE_TITLE);

        foreach ($this->model['business_info']['options'] as $key => $option) {
            update_option($option['option'], self::BUSINESS_INFO[$key]);
        }

        update_option($this->model['drift']['option'], 0);

        // Wayfinder's client role, back to its default (CLIENT_ROLE).
        delete_option('vwf_client_role');

        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure(self::PERMALINKS);
    }

    /**
     * @return array<string, int> service area slug => term id
     */
    private function seedServiceAreas(): array
    {
        $ids = [];
        foreach (self::SERVICE_AREAS as $slug => $name) {
            $term = wp_insert_term($name, 'service_area', ['slug' => $slug]);
            if (is_wp_error($term)) {
                WP_CLI::error("Service area {$slug}: " . $term->get_error_message());
            }

            $ids[$slug] = (int) $term['term_id'];
        }

        return $ids;
    }

    private function seedPattern(): void
    {
        $content = implode("\n", [
            '<!-- wp:group {"layout":{"type":"constrained"}} -->',
            '<div class="wp-block-group">',
            '<!-- wp:heading -->',
            '<h2 class="wp-block-heading">' . esc_html(self::PATTERN['heading']) . '</h2>',
            '<!-- /wp:heading -->',
            '',
            self::paragraph(self::PATTERN['text']),
            '',
            '<!-- wp:buttons -->',
            '<div class="wp-block-buttons"><!-- wp:button -->',
            '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url(self::path(self::PATTERN['button_page'])) . '">' . esc_html(self::PATTERN['button']) . '</a></div>',
            '<!-- /wp:button --></div>',
            '<!-- /wp:buttons -->',
            '</div>',
            '<!-- /wp:group -->',
        ]);

        $this->insertPost([
            'post_type' => 'wp_block',
            'post_name' => self::PATTERN['slug'],
            'post_title' => self::PATTERN['title'],
            'post_content' => $content,
        ], self::PATTERN_ID);
    }

    private function seedOfferPattern(): void
    {
        $this->insertPost([
            'post_type' => 'wp_block',
            'post_name' => self::OFFER_PATTERN['slug'],
            'post_title' => self::OFFER_PATTERN['title'],
            'post_content' => self::renderBlocks(self::OFFER_PATTERN['blocks']),
        ], self::OFFER_PATTERN_ID);
    }

    private function seedNoticePattern(): void
    {
        $this->insertPost([
            'post_type' => 'wp_block',
            'post_name' => self::NOTICE_PATTERN['slug'],
            'post_title' => self::NOTICE_PATTERN['title'],
            'post_content' => self::renderBlocks(self::NOTICE_PATTERN['blocks']),
        ], self::NOTICE_PATTERN_ID);
    }

    private function seedEditSafetyPages(): void
    {
        foreach (self::EDIT_SAFETY_PAGES as $slug => $page) {
            $this->insertPost([
                'post_type' => 'page',
                'post_name' => $slug,
                'post_title' => $page['title'],
                'post_content' => self::renderBlocks($page['blocks']),
            ], self::reservedId('edit_safety_page', self::EDIT_SAFETY_PAGES, $slug));
        }
    }

    private function seedEditSafety2Pages(): void
    {
        foreach (self::EDIT_SAFETY_2_PAGES as $slug => $page) {
            $this->insertPost([
                'post_type' => 'page',
                'post_name' => $slug,
                'post_title' => $page['title'],
                'post_content' => self::renderBlocks($page['blocks']),
            ], self::reservedId('edit_safety_2_page', self::EDIT_SAFETY_2_PAGES, $slug));
        }
    }

    private function seedPages(): void
    {
        foreach (self::PAGES as $slug => $page) {
            $blocks = array_map([self::class, 'paragraph'], $page['text']);
            if ($page['cta']) {
                $blocks[] = '<!-- wp:block {"ref":' . self::PATTERN_ID . '} /-->';
            }

            $meta = 'default' === $page['template'] ? [] : ['_wp_page_template' => $page['template']];

            $this->pageIds[$slug] = $this->insertPost([
                'post_type' => 'page',
                'post_name' => $slug,
                'post_title' => $page['title'],
                'post_content' => implode("\n\n", $blocks),
                'meta_input' => $meta,
            ], self::reservedId('page', self::PAGES, $slug));
        }

        update_option('show_on_front', 'page');
        update_option('page_on_front', $this->pageIds[self::FRONT_PAGE]);
        update_option('page_for_posts', $this->pageIds[self::POSTS_PAGE]);
    }

    /**
     * @param array<string, int> $areaIds
     */
    private function seedServices(array $areaIds): void
    {
        foreach (self::SERVICES as $slug => $service) {
            $id = $this->insertPost([
                'post_type' => 'service',
                'post_name' => $slug,
                'post_title' => $service['title'],
                'post_content' => self::paragraph($service['text']),
                'post_excerpt' => $service['text'],
                'meta_input' => [
                    'price_from' => $service['price_from'],
                    'duration' => $service['duration'],
                    'internal_sku' => $service['internal_sku'],
                ],
            ], self::reservedId('service', self::SERVICES, $slug));

            $areas = array_map(static fn(string $area): int => $areaIds[$area], $service['areas']);
            $result = wp_set_object_terms($id, $areas, 'service_area');
            if (is_wp_error($result)) {
                WP_CLI::error("Service {$slug}: " . $result->get_error_message());
            }
        }
    }

    private function seedTeam(): void
    {
        $upload = wp_upload_bits('vwf-fixture-team-photo.png', null, (string) base64_decode(self::PHOTO_PNG, true));
        if (!empty($upload['error'])) {
            WP_CLI::error('Team photo upload: ' . $upload['error']);
        }

        $photoReservedId = self::ID_BASE['attachment'];
        $this->assertFree($photoReservedId, 'the team photo');

        $photoId = wp_insert_attachment(wp_slash([
            'import_id' => $photoReservedId,
            'post_mime_type' => 'image/png',
            'post_title' => 'Team photo placeholder',
            'post_status' => 'inherit',
            'post_author' => $this->adminId,
            'meta_input' => [self::TAG => 1],
        ]), $upload['file'], 0, true);
        if (is_wp_error($photoId)) {
            WP_CLI::error('Team photo: ' . $photoId->get_error_message());
        }
        if ($photoReservedId !== $photoId) {
            WP_CLI::error("WordPress gave the team photo id {$photoId}, not its reserved id {$photoReservedId}.");
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($photoId, wp_generate_attachment_metadata($photoId, $upload['file']));

        $fieldKeys = [];
        foreach ($this->model['acf_group']['fields'] as $field) {
            $fieldKeys[$field['name']] = $field['key'];
        }

        foreach (self::TEAM as $slug => $member) {
            $id = $this->insertPost([
                'post_type' => 'team_member',
                'post_name' => $slug,
                'post_title' => $member['title'],
                'post_content' => self::paragraph($member['title'] . ', ' . strtolower($member['role']) . '.'),
            ], self::reservedId('team_member', self::TEAM, $slug));

            $values = ['role' => $member['role'], 'photo' => $photoId, 'linkedin' => $member['linkedin']];
            foreach ($values as $name => $value) {
                if (!update_field($fieldKeys[$name], $value, $id)) {
                    WP_CLI::error("Team member {$slug}: ACF did not save {$name}.");
                }
            }
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

    private function seedNavigation(): void
    {
        foreach (self::NAVIGATION as $slug => $menu) {
            $links = [];
            foreach ($menu['items'] as $item) {
                $attrs = ['label' => $item['label'], 'url' => $this->itemPath($item)];
                $attrs += isset($item['page'])
                    ? ['type' => 'page', 'id' => $this->pageIds[$item['page']], 'kind' => 'post-type']
                    : ['kind' => 'custom'];

                $links[] = serialize_block([
                    'blockName' => 'core/navigation-link',
                    'attrs' => $attrs,
                    'innerBlocks' => [],
                    'innerHTML' => '',
                    'innerContent' => [],
                ]);
            }

            $this->insertPost([
                'post_type' => 'wp_navigation',
                'post_name' => $slug,
                'post_title' => $menu['title'],
                'post_content' => implode("\n", $links),
            ], $menu['id']);
        }
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

        if (false === file_put_contents(__DIR__ . '/.credentials', self::ADMIN_LOGIN . ':' . $created[0])) {
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
            WP_CLI::error('Could not write fixtures/truth.json.');
        }
    }

    /**
     * Every fact the seed creates. Lists are sorted by id or slug; object keys
     * are sorted when written. No row ids except the three reserved ones, and
     * no timestamps, so the file only changes when the fixture does.
     *
     * @return array<string, mixed>
     */
    private function truth(): array
    {
        $model = $this->model;
        $binding = $model['binding_source'];

        $partRefs = [];
        foreach (self::PARTS as $slug => $part) {
            foreach ($part['refs'] as $ref) {
                $partRefs[$ref][] = 'part:' . $slug;
            }
        }

        $postTypes = [];
        foreach ($model['post_types'] as $slug => $definition) {
            $args = $definition['args'];
            $public = (bool) $args['public'];
            $rewrite = $args['rewrite'] ?? true;

            $fields = [];
            foreach ($definition['meta'] as $key => $meta) {
                $fields[] = [
                    'id' => "field:{$slug}.{$key}",
                    'key' => $key,
                    'label' => $meta['label'],
                    'type' => $meta['type'],
                    'single' => $meta['single'],
                    'show_in_rest' => $meta['show_in_rest'],
                    'source' => 'registered_meta',
                ];
            }

            $group = $model['acf_group'];
            if ($slug === $group['location'][0][0]['value']) {
                foreach ($group['fields'] as $field) {
                    $fields[] = [
                        'id' => "field:{$slug}.{$field['name']}",
                        'key' => $field['name'],
                        'label' => $field['label'],
                        'type' => $field['type'],
                        'show_in_rest' => (bool) $group['show_in_rest'],
                        'source' => 'acf',
                        'group' => $group['title'],
                        'group_edit_path' => 'defined in code',
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
                'public' => $public,
                'has_public_url' => $public,
                'has_archive' => (bool) ($args['has_archive'] ?? false),
                'archive_path' => !empty($args['has_archive']) ? '/' . $rewrite['slug'] . '/' : null,
                'rewrite_slug' => is_array($rewrite) ? $rewrite['slug'] : null,
                'show_ui' => (bool) ($args['show_ui'] ?? $public),
                'show_in_rest' => (bool) $args['show_in_rest'],
                'supports' => $args['supports'],
                'taxonomies' => $taxonomies,
                'fields' => self::sortList($fields),
                'published' => 'service' === $slug ? count(self::SERVICES) : count(self::TEAM),
                'edit_path' => 'edit.php?post_type=' . $slug,
            ];
        }

        $taxonomies = [];
        foreach ($model['taxonomies'] as $slug => $tax) {
            $parent = $model['post_types'][$tax['object_types'][0]]['args']['labels'];
            $terms = [];
            foreach (self::SERVICE_AREAS as $termSlug => $name) {
                $services = array_keys(array_filter(
                    self::SERVICES,
                    static fn(array $service): bool => in_array($termSlug, $service['areas'], true)
                ));
                $terms[] = ['slug' => $termSlug, 'name' => $name, 'services' => $services];
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

        $templates = [];
        foreach (self::TEMPLATES as $slug => $template) {
            if (isset($template['post_type'])) {
                $paths = array_map(static fn(string $service): string => '/services/' . $service . '/', array_keys(self::SERVICES));
                $assigned = [];
            } else {
                $pages = array_keys(array_filter(self::PAGES, static fn(array $page): bool => $slug === $page['template']));
                $paths = array_map([self::class, 'path'], $pages);
                $assigned = array_map(static fn(string $page): string => 'page:' . $page, $pages);
            }

            $templates[] = [
                'id' => 'tpl:' . $slug,
                'slug' => $slug,
                'theme' => self::THEME,
                'file' => "templates/{$slug}.html",
                'refs' => $template['refs'],
                'used_by_paths' => $paths,
                'assigned_to' => $assigned,
                'edit_path' => 'site-editor.php?p=/wp_template/' . self::THEME . '//' . $slug . '&canvas=edit',
            ];
        }

        $parts = [];
        foreach (self::PARTS as $slug => $part) {
            $parts[] = [
                'id' => 'part:' . $slug,
                'slug' => $slug,
                'area' => $part['area'],
                'theme' => self::THEME,
                'overrides' => self::PARENT_THEME,
                'file' => "parts/{$slug}.html",
                'refs' => $part['refs'],
                'edit_path' => 'site-editor.php?p=/wp_template_part/' . self::THEME . '//' . $slug . '&canvas=edit',
            ];
        }

        $patternUsage = [];
        foreach (self::PAGES as $slug => $page) {
            if ($page['cta']) {
                $patternUsage[] = 'page:' . $slug;
            }
        }
        foreach (self::TEMPLATES as $slug => $template) {
            if (in_array('pat:' . self::PATTERN['slug'], $template['refs'], true)) {
                $patternUsage[] = 'tpl:' . $slug;
            }
        }
        sort($patternUsage, SORT_STRING);

        $bindingKeys = [];
        foreach ($model['business_info']['options'] as $key => $option) {
            $id = 'bind:' . $binding['name'] . '.' . $key;
            $bindingKeys[] = [
                'id' => $id,
                'key' => $key,
                'label' => $option['label'],
                'option' => $option['option'],
                'value' => self::BUSINESS_INFO[$key],
                'used_in' => $partRefs[$id] ?? [],
            ];
        }

        $navigation = [];
        foreach (self::NAVIGATION as $slug => $menu) {
            $navigation[] = [
                'id' => 'nav:' . $slug,
                'slug' => $slug,
                'title' => $menu['title'],
                'items' => array_map(fn(array $item): array => ['label' => $item['label'], 'path' => $this->itemPath($item)], $menu['items']),
                'item_count' => count($menu['items']),
                'used_in' => $partRefs['nav:' . $slug] ?? [],
                'edit_path' => 'site-editor.php?p=/wp_navigation/' . $menu['id'],
                // Every menu shares this Site Editor path, so a grader reading it also needs the menu's title.
                'menu_path' => 'Appearance > Editor > Navigation',
            ];
        }

        $pages = [];
        foreach (self::PAGES as $slug => $page) {
            $pages[] = [
                'slug' => $slug,
                'title' => $page['title'],
                'path' => self::path($slug),
                'template' => $page['template'],
                'refs' => $page['cta'] ? ['pat:' . self::PATTERN['slug']] : [],
            ];
        }
        foreach (self::EDIT_SAFETY_PAGES as $slug => $page) {
            $pages[] = [
                'slug' => $slug,
                'title' => $page['title'],
                'path' => self::path($slug),
                'template' => 'default',
                'refs' => self::placesOffer($page['blocks']) ? ['pat:' . self::OFFER_PATTERN['slug']] : [],
            ];
        }
        foreach (self::EDIT_SAFETY_2_PAGES as $slug => $page) {
            $pages[] = [
                'slug' => $slug,
                'title' => $page['title'],
                'path' => self::path($slug),
                'template' => 'default',
                'refs' => self::placesPattern($page['blocks'], self::NOTICE_PATTERN['slug']) ? ['pat:' . self::NOTICE_PATTERN['slug']] : [],
            ];
        }

        $services = [];
        foreach (self::SERVICES as $slug => $service) {
            $services[] = [
                'slug' => $slug,
                'title' => $service['title'],
                'path' => '/services/' . $slug . '/',
                'edit_path' => 'post.php?post=' . self::reservedId('service', self::SERVICES, $slug) . '&action=edit',
                'price_from' => $service['price_from'],
                'duration' => $service['duration'],
                'internal_sku' => $service['internal_sku'],
                'service_areas' => $service['areas'],
                'template' => 'tpl:single-service',
            ];
        }

        $team = [];
        foreach (self::TEAM as $slug => $member) {
            $team[] = [
                'slug' => $slug,
                'title' => $member['title'],
                'role' => $member['role'],
                'linkedin' => $member['linkedin'],
                'has_photo' => true,
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

        $drift = $model['drift'];

        return [
            'format' => 'wayfinder-fixture-truth/1',
            'site' => [
                'title' => self::SITE_TITLE,
                'theme' => self::THEME,
                'parent_theme' => self::PARENT_THEME,
                'permalink_structure' => self::PERMALINKS,
            ],
            'counts' => [
                'pt:page' => count(self::PAGES) + count(self::EDIT_SAFETY_PAGES) + count(self::EDIT_SAFETY_2_PAGES),
                'pt:post' => count(self::POSTS),
                'pt:service' => count(self::SERVICES),
                'pt:team_member' => count(self::TEAM),
                'tax:service_area' => count(self::SERVICE_AREAS),
            ],
            'post_types' => self::sortList($postTypes),
            'taxonomies' => self::sortList($taxonomies),
            'templates' => self::sortList($templates),
            'template_parts' => self::sortList($parts),
            'patterns' => self::sortList([
                [
                    'id' => 'pat:' . self::PATTERN['slug'],
                    'slug' => self::PATTERN['slug'],
                    'title' => self::PATTERN['title'],
                    'synced' => true,
                    'used_in' => $patternUsage,
                    'usage_count' => count($patternUsage),
                    'edit_path' => 'post.php?post=' . self::PATTERN_ID . '&action=edit',
                ],
                [
                    'id' => 'pat:' . self::OFFER_PATTERN['slug'],
                    'slug' => self::OFFER_PATTERN['slug'],
                    'title' => self::OFFER_PATTERN['title'],
                    'synced' => true,
                    'used_in' => self::offerUsage(),
                    'usage_count' => count(self::offerUsage()),
                    'edit_path' => self::postEditPath(self::OFFER_PATTERN_ID),
                ],
                [
                    'id' => 'pat:' . self::NOTICE_PATTERN['slug'],
                    'slug' => self::NOTICE_PATTERN['slug'],
                    'title' => self::NOTICE_PATTERN['title'],
                    'synced' => true,
                    'used_in' => self::noticeUsage(),
                    'usage_count' => count(self::noticeUsage()),
                    'edit_path' => self::postEditPath(self::NOTICE_PATTERN_ID),
                ],
            ]),
            'binding_sources' => [[
                'id' => $binding['name'],
                'label' => $binding['label'],
                'arg' => $binding['arg'],
                'keys' => self::sortList($bindingKeys),
                'edit_path' => $binding['edit_path'],
                'settings_page' => [
                    'menu' => 'Settings > ' . $model['business_info']['title'],
                    'slug' => $model['business_info']['page_slug'],
                    'option_group' => $model['business_info']['option_group'],
                ],
                'registered_by' => 'mu-plugin:' . self::MU_PLUGIN_FILE,
            ]],
            'navigation' => self::sortList($navigation),
            'front_page' => ['page' => self::FRONT_PAGE, 'path' => '/'],
            'posts_page' => ['page' => self::POSTS_PAGE, 'path' => self::path(self::POSTS_PAGE)],
            'pages' => self::sortList($pages, 'slug'),
            'posts' => self::sortList($posts, 'slug'),
            'services' => self::sortList($services, 'slug'),
            'team_members' => self::sortList($team, 'slug'),
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
                'name' => 'Wayfinder fixture core',
                'registers' => ['bind:' . $binding['name'], 'pt:service', 'pt:team_member', 'tax:service_area'],
            ]],
            'drift' => [
                'option' => $drift['option'],
                'value' => 0,
                'when_enabled_adds' => array_map(
                    static fn(string $key, array $meta): array => [
                        'id' => "field:{$drift['post_type']}.{$key}",
                        'type' => $meta['type'],
                        'show_in_rest' => $meta['show_in_rest'],
                    ],
                    array_keys($drift['meta']),
                    $drift['meta']
                ),
            ],
            'edit_safety' => $this->editSafetyTruth(),
            'edit_safety_2' => $this->editSafety2Truth(),
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
        if (false === $index || $index >= self::IDS_PER_KIND) {
            WP_CLI::error(sprintf('No reserved id for %s %s: each kind has %d ids from ID_BASE.', $kind, $slug, self::IDS_PER_KIND));
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

    /**
     * The edit-safety facts from EDIT_SAFETY_PAGES, OFFER_PATTERN,
     * TRACKED_TEXTS, ANCHORS and CLIENT_ASKS. Lock and removal facts follow
     * core's canRemoveBlock(): a block's own lock.remove when set, else no
     * under a parent templateLock (all, insert or contentOnly, the nearest one
     * inherited, contentOnly final). Nothing is read back from the site.
     *
     * @return array<string, mixed>
     */
    private function editSafetyTruth(): array
    {
        $pages = [];
        $blocks = [];
        $classes = [];
        $trees = [];

        foreach (self::EDIT_SAFETY_PAGES as $slug => $page) {
            $nodes = self::walkBlocks($page['blocks']);
            $trees[$slug] = $nodes;
            $post = self::reservedId('edit_safety_page', self::EDIT_SAFETY_PAGES, $slug);
            $editPath = self::postEditPath($post);
            $cannotRemove = [];

            foreach ($nodes as $node) {
                if (isset($node['spec']['class'])) {
                    $classes[$node['spec']['class']][] = ['home' => 'page:' . $slug, 'edit_path' => $editPath, 'shown_on' => [self::path($slug)]];
                }

                if (!isset($node['spec']['name'])) {
                    continue;
                }

                $blocks[] = [
                    'name' => $node['spec']['name'],
                    'block' => $node['name'],
                    'page' => 'page:' . $slug,
                    'page_path' => self::path($slug),
                    'edit_path' => $editPath,
                    'path' => $node['path'],
                    'classes' => isset($node['spec']['class']) ? [$node['spec']['class']] : [],
                    'template_lock' => $node['spec']['template_lock'] ?? null,
                    'can_remove' => $node['can_remove'],
                    'text_editable' => $node['text_editable'],
                ];

                if ('no' === $node['can_remove']) {
                    $cannotRemove[] = $node['spec']['name'];
                }
            }

            sort($cannotRemove, SORT_STRING);
            $pages[] = [
                'slug' => $slug,
                'title' => $page['title'],
                'path' => self::path($slug),
                'post' => $post,
                'edit_path' => $editPath,
                'cannot_remove' => $cannotRemove,
            ];
        }

        $offerShownOn = self::offerPaths();
        foreach (self::walkBlocks(self::OFFER_PATTERN['blocks']) as $node) {
            if (isset($node['spec']['class'])) {
                $classes[$node['spec']['class']][] = ['home' => 'pat:' . self::OFFER_PATTERN['slug'], 'edit_path' => self::postEditPath(self::OFFER_PATTERN_ID), 'shown_on' => $offerShownOn];
            }
        }

        $classList = [];
        foreach ($classes as $class => $homes) {
            $classList[] = [
                'class' => $class,
                'count' => count($homes),
                'homes' => self::uniqueSorted(array_column($homes, 'home')),
                'edit_paths' => self::uniqueSorted(array_column($homes, 'edit_path')),
                'shown_on' => self::uniqueSorted(array_merge(...array_column($homes, 'shown_on'))),
            ];
        }

        $clientCan = [];
        foreach (self::CLIENT_ASKS as $key => $ask) {
            $textEditable = 'yes';
            if (isset($ask['page'])) {
                $needs = self::PAGE_EDIT_CAPS;
                $editPath = self::postEditPath(self::reservedId('edit_safety_page', self::EDIT_SAFETY_PAGES, $ask['page']));
                $node = current(array_filter($trees[$ask['page']], static fn(array $n): bool => ($n['spec']['name'] ?? null) === $ask['block']));
                if (false === $node) {
                    WP_CLI::error("CLIENT_ASKS {$key}: no block named {$ask['block']} on {$ask['page']}.");
                }
                $textEditable = $node['text_editable'];
            } elseif (isset($ask['part'])) {
                $needs = $ask['needs'];
                $editPath = 'site-editor.php?p=/wp_template_part/' . self::THEME . '//' . $ask['part'] . '&canvas=edit';
            } else {
                $needs = $ask['needs'];
                $editPath = $this->model['binding_source']['edit_path'];
            }

            $missing = array_values(array_filter($needs, static fn(string $cap): bool => !self::CLIENT_ROLE_CAPS[$cap]));
            $clientCan[] = [
                'key' => $key,
                'edit_path' => $editPath,
                'needs' => $needs,
                'role_lacks' => $missing,
                'text_editable' => $textEditable,
                'answer' => !$missing && 'yes' === $textEditable ? 'yes' : 'no',
            ];
        }

        return [
            'client_role' => self::CLIENT_ROLE,
            'client_role_caps' => self::CLIENT_ROLE_CAPS,
            'client_can' => self::sortList($clientCan, 'key'),
            'pages' => self::sortList($pages, 'slug'),
            'blocks' => self::sortList($blocks, 'name'),
            'classes' => self::sortList($classList, 'class'),
            'texts' => $this->trackedTexts($trees),
            'anchors' => self::anchorFacts(self::ANCHORS, $trees, 'edit_safety_page', self::EDIT_SAFETY_PAGES),
        ];
    }

    /**
     * The edit-safety-2 facts from EDIT_SAFETY_2_PAGES,
     * NOTICE_PATTERN, TRACKED_TEXTS_2, ANCHORS_2 and CLIENT_ASKS_2, by the
     * rules editSafetyTruth() follows, plus two more from core:
     * canMoveBlock() (a block's own lock.move when set, else no under a
     * parent templateLock all; insert lets blocks move) and
     * canInsertBlockType() (nothing can be added inside a block whose
     * children inherit a templateLock; false unlocks a locked parent's).
     * Each page also lists the text it shows, its placed pattern's
     * included, so a question's find inputs can be checked against it.
     * Nothing is read back from the site.
     *
     * @return array<string, mixed>
     */
    private function editSafety2Truth(): array
    {
        $v1Trees = [];
        foreach (self::EDIT_SAFETY_PAGES as $slug => $page) {
            $v1Trees[$slug] = self::walkBlocks($page['blocks']);
        }
        $trees = [];
        foreach (self::EDIT_SAFETY_2_PAGES as $slug => $page) {
            $trees[$slug] = self::walkBlocks($page['blocks']);
        }
        $noticeNodes = self::walkBlocks(self::NOTICE_PATTERN['blocks']);
        $noticeEditPath = self::postEditPath(self::NOTICE_PATTERN_ID);

        self::checkEditSafety2Apart($v1Trees, $trees, $noticeNodes);

        $patternTexts = [
            self::OFFER_PATTERN['slug'] => array_values(array_filter(array_column(self::walkBlocks(self::OFFER_PATTERN['blocks']), 'text'))),
            self::NOTICE_PATTERN['slug'] => array_values(array_filter(array_column($noticeNodes, 'text'))),
        ];

        $pages = [];
        $blocks = [];
        $classes = [];
        foreach ($trees as $slug => $nodes) {
            $post = self::reservedId('edit_safety_2_page', self::EDIT_SAFETY_2_PAGES, $slug);
            $editPath = self::postEditPath($post);
            $cannotRemove = [];
            $shows = [];

            foreach ($nodes as $node) {
                if ('contentOnly' === $node['parent_lock']) {
                    WP_CLI::error("EDIT_SAFETY_2_PAGES {$slug} {$node['path']}: can_move is not modelled under templateLock contentOnly.");
                }

                if ('core/block' === $node['name']) {
                    array_push($shows, ...$patternTexts[$node['spec']['pattern'] ?? self::OFFER_PATTERN['slug']]);
                } elseif ('' !== $node['text']) {
                    $shows[] = $node['text'];
                }

                if (isset($node['spec']['class'])) {
                    $classes[$node['spec']['class']][] = ['home' => 'page:' . $slug, 'edit_path' => $editPath, 'shown_on' => [self::path($slug)]];
                }

                if (!isset($node['spec']['name'])) {
                    continue;
                }

                $ownMove = $node['spec']['lock']['move'] ?? null;
                $canMove = null !== $ownMove ? !$ownMove : 'all' !== $node['parent_lock'];
                $blocks[] = [
                    'name' => $node['spec']['name'],
                    'block' => $node['name'],
                    'page' => 'page:' . $slug,
                    'page_path' => self::path($slug),
                    'edit_path' => $editPath,
                    'path' => $node['path'],
                    'classes' => isset($node['spec']['class']) ? [$node['spec']['class']] : [],
                    'template_lock' => $node['spec']['template_lock'] ?? null,
                    'can_remove' => $node['can_remove'],
                    'can_move' => $canMove ? 'yes' : 'no',
                    'can_insert' => isset($node['spec']['inner']) ? (in_array($node['inner_lock'], ['all', 'insert', 'contentOnly'], true) ? 'no' : 'yes') : null,
                    'text_editable' => $node['text_editable'],
                ];

                if ('no' === $node['can_remove']) {
                    $cannotRemove[] = $node['spec']['name'];
                }
            }

            sort($cannotRemove, SORT_STRING);
            $pages[] = [
                'slug' => $slug,
                'title' => self::EDIT_SAFETY_2_PAGES[$slug]['title'],
                'path' => self::path($slug),
                'post' => $post,
                'edit_path' => $editPath,
                'cannot_remove' => $cannotRemove,
                'shows' => $shows,
            ];
        }

        $names = array_column($blocks, 'name');
        if (count($names) !== count(array_unique($names))) {
            WP_CLI::error('Two EDIT_SAFETY_2_PAGES blocks share a List View name, so a question cannot name one: ' . implode(', ', array_diff_assoc($names, array_unique($names))) . '.');
        }

        foreach ($noticeNodes as $node) {
            if (isset($node['spec']['class'])) {
                $classes[$node['spec']['class']][] = ['home' => 'pat:' . self::NOTICE_PATTERN['slug'], 'edit_path' => $noticeEditPath, 'shown_on' => self::noticePaths()];
            }
        }

        $classList = [];
        foreach ($classes as $class => $homes) {
            $classList[] = [
                'class' => $class,
                'count' => count($homes),
                'homes' => self::uniqueSorted(array_column($homes, 'home')),
                'edit_paths' => self::uniqueSorted(array_column($homes, 'edit_path')),
                'shown_on' => self::uniqueSorted(array_merge(...array_column($homes, 'shown_on'))),
            ];
        }

        $caps = self::CLIENT_ROLE_CAPS + self::CLIENT_ROLE_CAPS_2;
        $clientCan = [];
        foreach (self::CLIENT_ASKS_2 as $key => $ask) {
            $textEditable = 'yes';
            if (isset($ask['page'])) {
                $needs = self::PAGE_EDIT_CAPS;
                $editPath = self::postEditPath(self::reservedId('edit_safety_2_page', self::EDIT_SAFETY_2_PAGES, $ask['page']));
                $node = current(array_filter($trees[$ask['page']], static fn(array $n): bool => ($n['spec']['name'] ?? null) === $ask['block']));
                if (false === $node) {
                    WP_CLI::error("CLIENT_ASKS_2 {$key}: no block named {$ask['block']} on {$ask['page']}.");
                }
                $textEditable = $node['text_editable'];
            } else {
                $needs = $ask['needs'];
                if (isset($ask['template']) && !isset(self::TEMPLATES[$ask['template']])) {
                    WP_CLI::error("CLIENT_ASKS_2 {$key}: no template {$ask['template']} in TEMPLATES.");
                }
                $editPath = match (true) {
                    isset($ask['template']) => 'site-editor.php?p=/wp_template/' . self::THEME . '//' . $ask['template'] . '&canvas=edit',
                    isset($ask['pattern']) => self::postEditPath($ask['pattern']),
                    isset($ask['nav']) => 'site-editor.php?p=/wp_navigation/' . $ask['nav'],
                    default => $ask['edit_path'],
                };
            }

            $undeclared = array_diff($needs, array_keys($caps));
            if ($undeclared) {
                WP_CLI::error("CLIENT_ASKS_2 {$key} needs " . implode(', ', $undeclared) . ', which neither CLIENT_ROLE_CAPS nor CLIENT_ROLE_CAPS_2 declares.');
            }

            $missing = array_values(array_filter($needs, static fn(string $cap): bool => !$caps[$cap]));
            $clientCan[] = [
                'key' => $key,
                'edit_path' => $editPath,
                'needs' => $needs,
                'role_lacks' => $missing,
                'text_editable' => $textEditable,
                'answer' => !$missing && 'yes' === $textEditable ? 'yes' : 'no',
            ];
        }

        // Every home a tracked text can have: the first set's, these pages and the notice pattern.
        $homes = self::textHomes($v1Trees);
        foreach ($trees as $slug => $nodes) {
            $homes[] = ['home' => 'page:' . $slug, 'edit_path' => self::postEditPath(self::reservedId('edit_safety_2_page', self::EDIT_SAFETY_2_PAGES, $slug)), 'shown_on' => [self::path($slug)], 'texts' => array_column($nodes, 'text')];
        }
        $homes[] = ['home' => 'pat:' . self::NOTICE_PATTERN['slug'], 'edit_path' => $noticeEditPath, 'shown_on' => self::noticePaths(), 'texts' => array_column($noticeNodes, 'text')];
        $elsewhere = array_merge($this->untrackedContent(), array_column(self::EDIT_SAFETY_2_PAGES, 'title'), [self::NOTICE_PATTERN['title']]);

        return [
            'client_role' => self::CLIENT_ROLE,
            'client_role_caps' => $caps,
            'client_can' => self::sortList($clientCan, 'key'),
            'pages' => self::sortList($pages, 'slug'),
            'blocks' => self::sortList($blocks, 'name'),
            'classes' => self::sortList($classList, 'class'),
            'texts' => self::indexTexts(self::TRACKED_TEXTS_2, $homes, $elsewhere),
            'anchors' => self::anchorFacts(self::ANCHORS_2, $trees, 'edit_safety_2_page', self::EDIT_SAFETY_2_PAGES),
        ];
    }

    /**
     * Stops when the edit-safety-2 rows would move a first-set answer that
     * editSafetyTruth() does not recompute from them: a class, List View name
     * or tracked text of the first set reused, or the offer pattern placed.
     * Also stops when one of their classes is in a theme file, where the
     * class index cannot say which pages show it.
     *
     * @param array<string, list<array<string, mixed>>> $v1Trees
     * @param array<string, list<array<string, mixed>>> $trees
     * @param list<array<string, mixed>> $noticeNodes
     */
    private static function checkEditSafety2Apart(array $v1Trees, array $trees, array $noticeNodes): void
    {
        $v1Nodes = array_merge(self::walkBlocks(self::OFFER_PATTERN['blocks']), ...array_values($v1Trees));
        $v2Nodes = array_merge($noticeNodes, ...array_values($trees));
        $pick = static fn(array $nodes, string $key): array => array_values(array_unique(array_filter(array_map(static fn(array $n): ?string => $n['spec'][$key] ?? null, $nodes))));

        foreach (['class' => 'class', 'name' => 'List View name'] as $key => $what) {
            $shared = array_intersect($pick($v1Nodes, $key), $pick($v2Nodes, $key));
            if ($shared) {
                WP_CLI::error(sprintf('The edit-safety-2 rows reuse the first set\'s %s %s, which would move an edit_safety answer. Pick another.', $what, implode(', ', $shared)));
            }
        }

        $v2Content = array_merge(array_column($v2Nodes, 'text'), array_column(self::EDIT_SAFETY_2_PAGES, 'title'), [self::NOTICE_PATTERN['title']]);
        foreach (self::TRACKED_TEXTS as $key => $text) {
            foreach ($v2Content as $source) {
                if (str_contains((string) $source, $text)) {
                    WP_CLI::error("The edit-safety-2 rows contain \"{$text}\" ({$key}), a first-set tracked text, in \"{$source}\". Reword it.");
                }
            }
        }

        foreach (self::EDIT_SAFETY_2_PAGES as $slug => $page) {
            if (self::placesOffer($page['blocks'])) {
                WP_CLI::error("EDIT_SAFETY_2_PAGES {$slug} places the offer pattern, which would move an edit_safety answer.");
            }
        }

        $themeDir = wp_get_theme(self::THEME)->get_stylesheet_directory();
        foreach (array_merge(array_map(static fn(string $s): string => "/templates/{$s}.html", array_keys(self::TEMPLATES)), array_map(static fn(string $s): string => "/parts/{$s}.html", array_keys(self::PARTS))) as $file) {
            $markup = (string) file_get_contents($themeDir . $file);
            foreach ($pick($v2Nodes, 'class') as $class) {
                if (str_contains($markup, $class)) {
                    WP_CLI::error("Theme file {$file} contains \"{$class}\", an edit-safety-2 class the class index cannot place on a page.");
                }
            }
        }
    }

    /**
     * Where each anchor's block is now, as ANCHORS documents: its
     * fingerprint, the paths that carry that fingerprint now, and its status.
     * Changed means the same named block still sits at the path with other
     * content; gone means it does not.
     *
     * @param array<string, array<string, mixed>> $anchorSpecs
     * @param array<string, list<array<string, mixed>>> $trees
     * @param array<string, mixed> $rows The pages constant whose reserved ids `$kind` holds
     * @return list<array<string, mixed>>
     */
    private static function anchorFacts(array $anchorSpecs, array $trees, string $kind, array $rows): array
    {
        $anchors = [];
        foreach ($anchorSpecs as $key => $anchor) {
            $then = self::walkBlocks([$anchor['block']])[0];
            $fp = self::fingerprint($then);
            $now = [];
            $at = null;
            foreach ($trees[$anchor['page']] as $node) {
                if (self::fingerprint($node) === $fp) {
                    $now[] = $node['path'];
                }
                if ($node['path'] === $anchor['path']) {
                    $at = $node;
                }
            }

            $sameBlock = null !== $at && isset($anchor['block']['name']) && $at['name'] === $then['name'] && ($at['spec']['name'] ?? null) === $anchor['block']['name'];
            $status = in_array($anchor['path'], $now, true) ? 'unchanged' : ($now ? 'moved' : ($sameBlock ? 'changed' : 'gone'));

            $anchors[] = [
                'key' => $key,
                'entity' => 'pt:page',
                'post' => self::reservedId($kind, $rows, $anchor['page']),
                'page_path' => self::path($anchor['page']),
                'path' => $anchor['path'],
                'fp' => $fp,
                'text' => $anchor['block']['text'],
                'paths_now' => $now,
                'status' => $status,
            ];
        }

        return self::sortList($anchors, 'key');
    }

    /**
     * Every home of each TRACKED_TEXTS entry and the URLs that show it: the
     * fixture's pages (each paragraph of PAGES, each block of
     * EDIT_SAFETY_PAGES) and the offer pattern, placed on its pages. Stops
     * if a tracked text sits anywhere else the seed writes or in a theme
     * file, where this cannot say which URLs show it.
     *
     * @param array<string, list<array<string, mixed>>> $trees
     * @return list<array<string, mixed>>
     */
    private function trackedTexts(array $trees): array
    {
        return self::indexTexts(self::TRACKED_TEXTS, self::textHomes($trees), $this->untrackedContent());
    }

    /**
     * The homes trackedTexts() searches: each paragraph of PAGES, each block
     * of EDIT_SAFETY_PAGES, and the offer pattern, placed on its pages.
     *
     * @param array<string, list<array<string, mixed>>> $trees
     * @return list<array<string, mixed>>
     */
    private static function textHomes(array $trees): array
    {
        $homes = [];
        foreach (self::PAGES as $slug => $page) {
            $homes[] = ['home' => 'page:' . $slug, 'edit_path' => self::postEditPath(self::reservedId('page', self::PAGES, $slug)), 'shown_on' => [self::path($slug)], 'texts' => $page['text']];
        }
        foreach ($trees as $slug => $nodes) {
            $homes[] = ['home' => 'page:' . $slug, 'edit_path' => self::postEditPath(self::reservedId('edit_safety_page', self::EDIT_SAFETY_PAGES, $slug)), 'shown_on' => [self::path($slug)], 'texts' => array_column($nodes, 'text')];
        }
        $homes[] = ['home' => 'pat:' . self::OFFER_PATTERN['slug'], 'edit_path' => self::postEditPath(self::OFFER_PATTERN_ID), 'shown_on' => self::offerPaths(), 'texts' => array_column(self::walkBlocks(self::OFFER_PATTERN['blocks']), 'text')];

        return $homes;
    }

    /**
     * Everything else the seed writes, and the theme files: content no
     * tracked text may sit in, because a text index cannot place it on a URL.
     *
     * @return list<string>
     */
    private function untrackedContent(): array
    {
        $elsewhere = array_merge(
            [self::SITE_TITLE],
            array_values(self::BUSINESS_INFO),
            array_values(self::SERVICE_AREAS),
            array_column(self::PAGES, 'title'),
            array_column(self::EDIT_SAFETY_PAGES, 'title'),
            [self::OFFER_PATTERN['title']],
            array_values(array_filter(self::PATTERN, 'is_string')),
            array_merge(...array_map(static fn(array $s): array => [$s['title'], $s['text'], $s['duration'], $s['internal_sku']], array_values(self::SERVICES))),
            array_merge(...array_map(static fn(array $m): array => [$m['title'], $m['role'], $m['linkedin']], array_values(self::TEAM))),
            array_merge(...array_map(static fn(array $p): array => [$p['title'], $p['text']], array_values(self::POSTS))),
            array_merge(...array_map(static fn(array $n): array => array_merge([$n['title']], array_column($n['items'], 'label')), array_values(self::NAVIGATION)))
        );
        $themeDir = wp_get_theme(self::THEME)->get_stylesheet_directory();
        foreach (array_merge(array_keys(self::TEMPLATES), array_keys(self::PARTS)) as $i => $slug) {
            $elsewhere[] = (string) file_get_contents($themeDir . ($i < count(self::TEMPLATES) ? '/templates/' : '/parts/') . $slug . '.html');
        }

        return $elsewhere;
    }

    /**
     * Every home of each tracked text and the URLs that show it. Stops if a
     * tracked text sits in `$elsewhere`.
     *
     * @param array<string, string> $tracked
     * @param list<array<string, mixed>> $homes
     * @param list<string> $elsewhere
     * @return list<array<string, mixed>>
     */
    private static function indexTexts(array $tracked, array $homes, array $elsewhere): array
    {
        $out = [];
        foreach ($tracked as $key => $text) {
            foreach ($elsewhere as $source) {
                if (str_contains((string) $source, $text)) {
                    WP_CLI::error("Tracked text \"{$text}\" ({$key}) is in content truth.json cannot place on a URL: \"{$source}\". Move it, or extend trackedTexts().");
                }
            }

            $shownAt = [];
            foreach ($homes as $home) {
                $count = count(array_filter($home['texts'], static fn(string $t): bool => str_contains($t, $text)));
                if (0 === $count) {
                    continue;
                }
                foreach ($home['shown_on'] as $path) {
                    $shownAt[] = [
                        'path' => $path,
                        'home' => $home['home'],
                        'edit_path' => $home['edit_path'],
                        'blocks' => $count,
                        'follows_pattern' => str_starts_with($home['home'], 'pat:') ? 'yes' : 'no',
                    ];
                }
            }
            usort($shownAt, static fn(array $a, array $b): int => strcmp($a['path'] . ' ' . $a['home'], $b['path'] . ' ' . $b['home']));

            $out[] = [
                'key' => $key,
                'text' => $text,
                'homes' => self::uniqueSorted(array_column($shownAt, 'home')),
                'edit_paths' => self::uniqueSorted(array_column($shownAt, 'edit_path')),
                'shown_at' => $shownAt,
            ];
        }

        return $out;
    }

    /**
     * Every block in a spec list, parents before children, with its
     * parse-order path, block name, attrs as the markup carries them, own
     * text, and whether core lets it be removed and its text edited.
     *
     * @param list<array<string, mixed>> $specs
     * @return list<array<string, mixed>>
     */
    private static function walkBlocks(array $specs, string $parentPath = '', ?string $templateLock = null): array
    {
        $nodes = [];
        foreach (array_values($specs) as $index => $spec) {
            $path = '' === $parentPath ? (string) $index : $parentPath . '.' . $index;
            $ownRemove = $spec['lock']['remove'] ?? null;
            $canRemove = null !== $ownRemove ? !$ownRemove : !in_array($templateLock, ['all', 'insert', 'contentOnly'], true);
            $text = $spec['text'] ?? '';
            $childLock = 'contentOnly' === $templateLock || !array_key_exists('template_lock', $spec) ? $templateLock : ($spec['template_lock'] ?: null);

            $nodes[] = [
                'path' => $path,
                'spec' => $spec,
                'name' => self::blockName($spec),
                'attrs' => self::blockAttrs($spec),
                'text' => $text,
                'can_remove' => $canRemove ? 'yes' : 'no',
                // A lock never stops text edits, and contentOnly leaves only text editable.
                'text_editable' => '' !== $text ? 'yes' : 'no',
                // The templateLock that applies to this block (its parent's), and to its children.
                'parent_lock' => $templateLock,
                'inner_lock' => $childLock,
            ];

            array_push($nodes, ...self::walkBlocks($spec['inner'] ?? [], $path, $childLock));
        }

        return $nodes;
    }

    /**
     * A block's fingerprint as docs/ai-context-format.md ("Anchors") defines
     * it: the first 16 hex characters of sha256 over the canonical JSON
     * (keys sorted, slashes and unicode unescaped) of its name, its attrs
     * without className and lock, and its own text with whitespace collapsed.
     *
     * @param array<string, mixed> $node
     */
    private static function fingerprint(array $node): string
    {
        $attrs = array_diff_key($node['attrs'], array_flip(['className', 'lock']));
        $value = [
            'attrs' => [] === $attrs ? new stdClass() : self::sortKeys($attrs),
            'name' => $node['name'],
            'text' => trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node['text'])),
        ];

        return substr(hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * @param list<array<string, mixed>> $specs
     */
    private static function renderBlocks(array $specs): string
    {
        return implode("\n\n", array_map([self::class, 'renderBlock'], $specs));
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function renderBlock(array $spec): string
    {
        $class = isset($spec['class']) ? ' ' . $spec['class'] : '';
        $text = esc_html($spec['text'] ?? '');
        $inner = self::renderBlocks($spec['inner'] ?? []);

        $html = match ($spec['type']) {
            'paragraph' => '<p' . ('' === $class ? '' : ' class="' . esc_attr(trim($class)) . '"') . '>' . $text . '</p>',
            'heading' => '<h2 class="wp-block-heading' . esc_attr($class) . '">' . $text . '</h2>',
            'group' => '<div class="wp-block-group' . esc_attr($class) . "\">\n" . $inner . "\n</div>",
            'buttons' => '<div class="wp-block-buttons">' . $inner . '</div>',
            'button' => '<div class="wp-block-button' . esc_attr($class) . '"><a class="wp-block-button__link wp-element-button" href="' . esc_url(self::path($spec['url'])) . '">' . $text . '</a></div>',
            'pattern' => '',
        };

        return get_comment_delimited_block_content(self::blockName($spec), self::blockAttrs($spec), $html);
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function blockName(array $spec): string
    {
        return 'pattern' === $spec['type'] ? 'core/block' : 'core/' . $spec['type'];
    }

    /**
     * The attrs a block's comment carries, as parse_blocks() returns them.
     *
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private static function blockAttrs(array $spec): array
    {
        $attrs = [];
        if ('pattern' === $spec['type']) {
            $attrs['ref'] = match ($spec['pattern'] ?? self::OFFER_PATTERN['slug']) {
                self::OFFER_PATTERN['slug'] => self::OFFER_PATTERN_ID,
                self::NOTICE_PATTERN['slug'] => self::NOTICE_PATTERN_ID,
            };
        }
        if (isset($spec['template_lock'])) {
            $attrs['templateLock'] = $spec['template_lock'];
        }
        if (isset($spec['lock'])) {
            $attrs['lock'] = $spec['lock'];
        }
        if (isset($spec['name'])) {
            $attrs['metadata'] = ['name' => $spec['name']];
        }
        if (isset($spec['class'])) {
            $attrs['className'] = $spec['class'];
        }
        if ('group' === $spec['type']) {
            $attrs['layout'] = ['type' => 'constrained'];
        }

        return $attrs;
    }

    /**
     * @param list<array<string, mixed>> $specs
     */
    private static function placesOffer(array $specs): bool
    {
        return self::placesPattern($specs, self::OFFER_PATTERN['slug']);
    }

    /**
     * True when a spec list places the synced pattern `$slug`. A pattern
     * block without `pattern` places the offer pattern.
     *
     * @param list<array<string, mixed>> $specs
     */
    private static function placesPattern(array $specs, string $slug): bool
    {
        foreach ($specs as $spec) {
            if ('pattern' === $spec['type'] && $slug === ($spec['pattern'] ?? self::OFFER_PATTERN['slug'])) {
                return true;
            }
            if (self::placesPattern($spec['inner'] ?? [], $slug)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> The pages that place the notice pattern, as `page:<slug>`.
     */
    private static function noticeUsage(): array
    {
        $usage = [];
        foreach (self::EDIT_SAFETY_2_PAGES as $slug => $page) {
            if (self::placesPattern($page['blocks'], self::NOTICE_PATTERN['slug'])) {
                $usage[] = 'page:' . $slug;
            }
        }
        sort($usage, SORT_STRING);

        return $usage;
    }

    /**
     * @return list<string>
     */
    private static function noticePaths(): array
    {
        return self::uniqueSorted(array_map(static fn(string $page): string => self::path(substr($page, strlen('page:'))), self::noticeUsage()));
    }

    /**
     * @return list<string> The pages that place the offer pattern, as `page:<slug>`.
     */
    private static function offerUsage(): array
    {
        $usage = [];
        foreach (self::EDIT_SAFETY_PAGES as $slug => $page) {
            if (self::placesOffer($page['blocks'])) {
                $usage[] = 'page:' . $slug;
            }
        }
        sort($usage, SORT_STRING);

        return $usage;
    }

    /**
     * @return list<string>
     */
    private static function offerPaths(): array
    {
        return self::uniqueSorted(array_map(static fn(string $page): string => self::path(substr($page, strlen('page:'))), self::offerUsage()));
    }

    private static function postEditPath(int $id): string
    {
        return 'post.php?post=' . $id . '&action=edit';
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }

    private static function paragraph(string $text): string
    {
        return "<!-- wp:paragraph -->\n<p>" . esc_html($text) . "</p>\n<!-- /wp:paragraph -->";
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

// wp eval-file passes the arguments after the file name as $args.
(new VwfFixtureSeed(in_array(VwfFixtureSeed::RELAUNCHED, $args ?? [], true)))->run();
