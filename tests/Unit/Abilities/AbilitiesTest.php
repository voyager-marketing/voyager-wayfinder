<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use stdClass;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\FindPayload;
use Voyager\Wayfinder\Manifest\CanonicalJson;
use Voyager\Wayfinder\Manifest\Item;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\WpCalls;
use WP_Error;

final class AbilitiesTest extends TestCase
{
    private const NAMES = [
        'voyager-wayfinder/get-brief',
        'voyager-wayfinder/get-index',
        'voyager-wayfinder/get-section',
        'voyager-wayfinder/find-edit-location',
        'voyager-wayfinder/get-changes-since',
    ];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testRegisterHooksTheCategoryAndTheAbilitiesOntoTheAbilitiesApiActions(): void
    {
        $abilities = new Abilities();
        $abilities->register();

        $this->assertSame(
            [
                ['wp_abilities_api_categories_init', [$abilities, 'registerCategory']],
                ['wp_abilities_api_init', [$abilities, 'registerAbilities']],
            ],
            array_map(static fn (array $action): array => [$action['hook'], $action['callback']], WpCalls::$actions)
        );
    }

    public function testFiveReadOnlyAbilitiesRegisterUnderTheWayfinderCategory(): void
    {
        $abilities = new Abilities();
        $abilities->registerCategory();
        $abilities->registerAbilities();

        $this->assertSame(['voyager-wayfinder'], array_keys(WpCalls::$abilityCategories));
        $this->assertSame('Wayfinder', WpCalls::$abilityCategories['voyager-wayfinder']['label']);
        $this->assertSame(self::NAMES, array_keys(WpCalls::$abilities));

        foreach (WpCalls::$abilities as $name => $args) {
            $this->assertSame('voyager-wayfinder', $args['category'], $name);
            $this->assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $args['meta']['annotations'], $name);
            $this->assertTrue($args['meta']['show_in_rest'], $name);
            $this->assertArrayNotHasKey('public', $args['meta'], $name);
            $this->assertIsCallable($args['execute_callback'], $name);
            $this->assertNotSame('', trim($args['label']), $name);
        }
    }

    public function testMcpStaysOffUnlessTheOptionIsTruthy(): void
    {
        $public = static function (): array {
            WpCalls::$abilities = [];
            (new Abilities())->registerAbilities();

            return array_values(array_unique(array_map(
                static fn (array $args): mixed => $args['meta']['mcp']['public'],
                WpCalls::$abilities
            ), SORT_REGULAR));
        };

        $this->assertSame([false], $public(), 'option missing');

        foreach ([0, '0', '', 'false', false, null] as $off) {
            WpCalls::$options[Abilities::MCP_OPTION] = $off;
            $this->assertSame([false], $public(), var_export($off, true));
        }

        foreach ([1, '1', true, 'yes'] as $on) {
            WpCalls::$options[Abilities::MCP_OPTION] = $on;
            $this->assertSame([true], $public(), var_export($on, true));
        }
    }

    public function testEveryAbilityNeedsEditPagesSoASubscriberIsRefused(): void
    {
        foreach ((new Abilities())->definitions(false) as $name => $args) {
            WpCalls::$capabilities = ['read'];
            $this->assertFalse(($args['permission_callback'])(), $name . ' as a subscriber');

            WpCalls::$capabilities = ['read', 'edit_pages'];
            $this->assertTrue(($args['permission_callback'])(), $name . ' as an editor');
        }
    }

    public function testInputSchemasAreClosedObjectsAndNameWhatIsRequired(): void
    {
        $required = [];

        foreach ((new Abilities())->definitions(false) as $name => $args) {
            $this->assertSame('object', $args['input_schema']['type'], $name);
            $this->assertFalse($args['input_schema']['additionalProperties'], $name);
            $this->assertSame('object', $args['output_schema']['type'], $name);
            $required[$name] = $args['input_schema']['required'] ?? [];
        }

        $this->assertSame(array_combine(self::NAMES, [[], [], ['id'], ['url'], ['since']]), $required);
        $this->assertSame([], (new Abilities())->definitions(false)['voyager-wayfinder/get-brief']['input_schema']['default']);
    }

    public function testDescriptionsTellAnAgentToStartWithTheBrief(): void
    {
        $definitions = (new Abilities())->definitions(false);

        $this->assertStringStartsWith('Call this first.', $definitions['voyager-wayfinder/get-brief']['description']);

        foreach (array_slice(self::NAMES, 1) as $name) {
            $this->assertStringContainsString('get-brief first', $definitions[$name]['description'], $name);
        }
    }

    public function testBriefAndIndexReturnTheContextTierAndTheManifestHash(): void
    {
        $abilities = self::abilities();
        $context = self::context();

        $brief = $abilities->brief();
        $index = $abilities->index([]);

        $this->assertSame(FixtureManifest::manifest()->hash, $brief['hash']);
        $this->assertSame($context->render('brief'), $brief['text']);
        $this->assertSame($context->render('index'), $index['text']);
        $this->assertSame($brief['hash'], $index['hash']);
    }

    public function testSectionDecodesTheSameJsonTheRestTierSends(): void
    {
        $item = self::abilities()->section(['id' => 'pt:service']);

        $this->assertIsArray($item);
        $this->assertSame('pt:service', $item['id']);
        $this->assertSame('content_model', $item['section']);
        $this->assertSame(self::context()->render('section', 'pt:service'), CanonicalJson::encode($item));

        $section = self::abilities()->section(['id' => 'layout']);

        $this->assertIsArray($section);
        $this->assertSame('layout', $section['name']);
        $this->assertSame(self::context()->render('section', 'layout'), CanonicalJson::encode($section));
    }

    public function testSectionTakesThePageTheIndexNames(): void
    {
        $page = self::abilities()->section(['id' => 'layout', 'page' => 1]);

        $this->assertIsArray($page);
        $this->assertSame(1, $page['page']);
        $this->assertSame(1, $page['pages']);
        $this->assertSame(self::context()->render('section', 'layout', 1), CanonicalJson::encode($page));

        // A GET run before WordPress 7.1 hands the number over as a string.
        $this->assertSame(CanonicalJson::encode($page), CanonicalJson::encode(self::abilities()->section(['id' => 'layout', 'page' => '1'])));

        $past = self::abilities()->section(['id' => 'layout', 'page' => 2]);
        $this->assertInstanceOf(WP_Error::class, $past);
        $this->assertSame('vwf_bad_request', $past->get_error_code());
        $this->assertSame(['status' => 400], $past->get_error_data());

        $definition = (new Abilities())->definitions(false)['voyager-wayfinder/get-section'];
        $this->assertSame(['type' => 'integer', 'minimum' => 1], array_intersect_key($definition['input_schema']['properties']['page'], ['type' => true, 'minimum' => true]));
        $this->assertSame(['id'], $definition['input_schema']['required']);
        $this->assertSame(['type' => 'integer'], $definition['output_schema']['oneOf'][0]['properties']['page']);
        $this->assertSame(['type' => 'integer'], $definition['output_schema']['oneOf'][0]['properties']['pages']);
    }

    public function testAnItemWithNoFactsKeepsAnObjectSoItEncodesAsBraces(): void
    {
        $manifest = FixtureManifest::manifest(['site' => [new Item('opt:blogdescription', 'option', 'Tagline', [], 'options-general.php')]]);
        $abilities = new Abilities(static fn (): Context => new Context(static fn () => $manifest, static fn (): array => []));

        $item = $abilities->section(['id' => 'opt:blogdescription']);

        $this->assertIsArray($item);
        $this->assertInstanceOf(stdClass::class, $item['facts']);
        $this->assertStringContainsString('"facts":{}', (string) json_encode($item));
    }

    public function testAnUnknownSectionOrItemIsANotFoundError(): void
    {
        $error = self::abilities()->section(['id' => 'pat:no-such-pattern']);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('vwf_not_found', $error->get_error_code());
        $this->assertSame(['status' => 404], $error->get_error_data());
        $this->assertStringContainsString('pat:no-such-pattern', $error->get_error_message());
    }

    public function testFindReturnsTheLocatorChainForAUrlAndText(): void
    {
        $result = self::abilities()->find(['url' => '/', 'text' => '555-0142']);

        $this->assertIsArray($result);
        $this->assertSame(FindPayload::shape((new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout()))->find('/', '555-0142')), $result);
        $this->assertSame('bind:vwf-fixture/business-info.phone', $result['chain'][0]['id']);
        $this->assertSame('compact', $result['detail']);
    }

    public function testFindWithoutTextAndAnUnknownUrl(): void
    {
        $page = self::abilities()->find(['url' => '/services/drain-cleaning/', 'text' => '']);

        $this->assertIsArray($page);
        $this->assertNull($page['matched']);
        $this->assertSame('route:single-service', $page['route']);

        $bad = self::abilities()->find(['url' => '/', 'detail' => 'everything']);

        $this->assertInstanceOf(WP_Error::class, $bad);
        $this->assertSame('vwf_bad_request', $bad->get_error_code());
        $this->assertSame(['status' => 400], $bad->get_error_data());

        $error = self::abilities()->find(['url' => '/no-such-page/']);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame('vwf_unknown_url', $error->get_error_code());
        $this->assertSame(['status' => 404], $error->get_error_data());
        $this->assertStringStartsWith('No route matches this URL.', $error->get_error_message());
    }

    public function testChangesSinceFollowsTheStoredHistoryToTheCurrentHash(): void
    {
        $current = FixtureManifest::manifest()->hash;
        $history = [
            ['previous' => 'aaa', 'hash' => 'bbb', 'added' => ['pt:faq'], 'removed' => [], 'changed' => []],
            ['previous' => 'bbb', 'hash' => $current, 'added' => [], 'removed' => [], 'changed' => ['pt:service']],
        ];
        $abilities = self::abilities(static fn (): array => $history);

        $this->assertSame(['changed' => ['pt:faq', 'pt:service'], 'full' => false, 'hash' => $current], $abilities->changes(['since' => 'aaa']));
        $this->assertSame(['changed' => [], 'full' => false, 'hash' => $current], $abilities->changes(['since' => ' ' . $current . ' ']));
        $this->assertSame(['full' => true, 'hash' => $current], $abilities->changes(['since' => 'not-in-history']));
    }

    /**
     * @param (callable(): mixed)|null $history
     */
    private static function abilities(?callable $history = null): Abilities
    {
        return new Abilities(
            self::context(...),
            static fn (): EditLocator => new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout()),
            $history ?? static fn (): array => []
        );
    }

    private static function context(): Context
    {
        return new Context(static fn () => FixtureManifest::manifest(), static fn (): array => []);
    }
}
