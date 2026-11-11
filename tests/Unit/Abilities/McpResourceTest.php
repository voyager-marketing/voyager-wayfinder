<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Abilities;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Abilities\McpResources;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;
use Voyager\Wayfinder\Tests\Support\WpCalls;

final class McpResourceTest extends TestCase
{
    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testThePluginRegistersTheResourcesOnTheAbilitiesApiInit(): void
    {
        Plugin::instance()->register();

        $resources = array_values(array_filter(
            WpCalls::$actions,
            static fn (array $action): bool => is_array($action['callback']) && $action['callback'][0] instanceof McpResources
        ));

        $this->assertSame(
            [['wp_abilities_api_init', 'registerResources']],
            array_map(static fn (array $action): array => [$action['hook'], $action['callback'][1]], $resources)
        );
    }

    public function testBriefAndIndexRegisterAsPlainTextResourcesWithStableUris(): void
    {
        (new McpResources())->registerResources();

        $this->assertSame([McpResources::BRIEF, McpResources::INDEX], array_keys(WpCalls::$abilities));
        $this->assertSame(
            [McpResources::BRIEF => 'wayfinder://brief', McpResources::INDEX => 'wayfinder://index'],
            array_map(static fn (array $args): string => $args['meta']['mcp']['uri'], WpCalls::$abilities)
        );

        foreach (WpCalls::$abilities as $name => $args) {
            $this->assertSame('voyager-wayfinder', $args['category'], $name);
            $this->assertSame('resource', $args['meta']['mcp']['type'], $name);
            $this->assertSame('text/plain', $args['meta']['mcp']['mimeType'], $name);
            $this->assertSame(['readonly' => true, 'destructive' => false, 'idempotent' => true], $args['meta']['annotations'], $name);
            $this->assertArrayNotHasKey('public', $args['meta'], $name);
            $this->assertSame(Abilities::noInput(), $args['input_schema'], $name);
            $this->assertSame('array', $args['output_schema']['type'], $name);
            $this->assertIsCallable($args['execute_callback'], $name);
        }
    }

    public function testNeitherResourceIsListedUnlessTheMcpOptionIsOn(): void
    {
        $this->assertSame([], self::listed(), 'option missing');

        foreach ([0, '0', '', 'false', false, null] as $off) {
            WpCalls::$options[Abilities::MCP_OPTION] = $off;
            $this->assertSame([], self::listed(), var_export($off, true));
        }

        foreach ([1, '1', true, 'yes'] as $on) {
            WpCalls::$options[Abilities::MCP_OPTION] = $on;
            $this->assertSame(['wayfinder://brief', 'wayfinder://index'], self::listed(), var_export($on, true));
        }
    }

    public function testEveryResourceNeedsEditPagesLikeTheTools(): void
    {
        foreach ((new McpResources())->definitions(true) as $name => $args) {
            $this->assertSame([Abilities::class, 'canRead'], $args['permission_callback'], $name);

            WpCalls::$capabilities = ['read'];
            $this->assertFalse(($args['permission_callback'])(), $name . ' as a subscriber');

            WpCalls::$capabilities = ['read', 'edit_pages'];
            $this->assertTrue(($args['permission_callback'])(), $name . ' as an editor');
        }
    }

    public function testAReadCarriesTheToolTextAsOnePlainTextItemWithTheFullHash(): void
    {
        $resources = new McpResources(self::context(...));
        $tools = new Abilities(self::context(...));

        $brief = $resources->brief();
        $index = $resources->index([]);

        $this->assertSame(
            [['uri' => 'wayfinder://brief', 'mimeType' => 'text/plain', 'text' => $tools->brief()['text'], '_meta' => ['wayfinder/manifest_hash' => FixtureManifest::manifest()->hash]]],
            $brief
        );
        $this->assertSame('wayfinder://index', $index[0]['uri']);
        $this->assertSame($tools->index()['text'], $index[0]['text']);
        $this->assertSame(self::context()->render('index'), $index[0]['text']);
    }

    public function testTheResourceTextIsSmallerThanTheJsonEscapedToolResult(): void
    {
        $resources = new McpResources(self::context(...));
        $tools = new Abilities(self::context(...));

        foreach (['brief', 'index'] as $tier) {
            $plain = $resources->$tier()[0]['text'];
            // What mcp-adapter 0.7.0 ToolsHandler puts in the tool result's text block.
            $escaped = (string) json_encode($tools->$tier());

            $this->assertStringContainsString("\n", $plain, $tier);
            $this->assertStringNotContainsString('\\n', $plain, $tier);
            $this->assertGreaterThan(strlen($plain), strlen($escaped), $tier);
        }
    }

    public function testTheToolsStayToolsAndKeepTheirNames(): void
    {
        $tools = (new Abilities())->definitions(true);

        $this->assertArrayNotHasKey(McpResources::BRIEF, $tools);
        $this->assertArrayNotHasKey(McpResources::INDEX, $tools);
        $this->assertArrayHasKey(Abilities::GET_BRIEF, $tools);
        $this->assertArrayHasKey(Abilities::GET_INDEX, $tools);

        foreach ($tools as $name => $args) {
            $this->assertArrayNotHasKey('type', $args['meta']['mcp'], $name);
        }
    }

    /**
     * The URIs the MCP Adapter's default server would list: public abilities
     * whose `mcp.type` is `resource` (DefaultServerFactory::discover_abilities_by_type()).
     *
     * @return list<string>
     */
    private static function listed(): array
    {
        WpCalls::$abilities = [];
        (new McpResources())->registerResources();
        (new Abilities())->registerAbilities();

        $uris = [];

        foreach (WpCalls::$abilities as $args) {
            $mcp = $args['meta']['mcp'];

            if (($mcp['public'] ?? false) === true && ($mcp['type'] ?? 'tool') === 'resource') {
                $uris[] = $mcp['uri'];
            }
        }

        return $uris;
    }

    private static function context(): Context
    {
        return new Context(static fn () => FixtureManifest::manifest(), static fn (): array => []);
    }
}
