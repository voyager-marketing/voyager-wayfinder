<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Scanners\PluginRoles;

final class PluginRolesTest extends TestCase
{
    public function testTheBundledListCoversAtLeastFortyPluginsAcrossEveryRole(): void
    {
        $all = PluginRoles::bundled()->all();

        $this->assertGreaterThanOrEqual(40, count($all));

        $used = array_values(array_unique($all));
        sort($used, SORT_STRING);
        $this->assertSame(PluginRoles::ROLES, $used);
    }

    public function testKnownPluginsMapToTheirRoleAndOthersToOther(): void
    {
        $roles = PluginRoles::bundled();

        $this->assertSame('fields', $roles->role('advanced-custom-fields'));
        $this->assertSame('forms', $roles->role('gravityforms'));
        $this->assertSame('seo', $roles->role('wordpress-seo'));
        $this->assertSame('ecommerce', $roles->role('woocommerce'));
        $this->assertSame('multilingual', $roles->role('sitepress-multilingual-cms'));
        $this->assertSame('other', $roles->role('voyager-wayfinder'));
        $this->assertSame('other', $roles->role('not-a-real-plugin'));
    }

    public function testAiMcpAndHostPluginsHaveTheirOwnRoles(): void
    {
        $roles = PluginRoles::bundled();

        // Each of these was `other` on Voyager's own site before the ai and hosting roles existed.
        foreach (['abilities-api', 'ai-provider-for-anthropic', 'mcp-adapter', 'wp-ai-client'] as $slug) {
            $this->assertSame('ai', $roles->role($slug), $slug);
        }

        $this->assertSame('hosting', $roles->role('spinupwp'));
        $this->assertSame('email', $roles->role('postmark-approved-wordpress-plugin'));
    }

    public function testEachListInTheFileIsSorted(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/data/plugin-roles.json'), true);

        $this->assertIsArray($data);
        $this->assertSame(PluginRoles::ROLES, array_keys($data['roles']));

        foreach ($data['roles'] as $role => $slugs) {
            $sorted = $slugs;
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $slugs, sprintf('The %s list is not sorted.', $role));
        }
    }

    public function testASlugUnderTwoRolesThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Plugin "elementor" is listed as both builder and forms.');

        PluginRoles::fromData(['roles' => ['builder' => ['elementor'], 'forms' => ['elementor']]]);
    }

    public function testAnUnknownRoleThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plugin role "crm".');

        PluginRoles::fromData(['roles' => ['crm' => ['hubspot']]]);
    }

    public function testOtherIsNotAssignable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PluginRoles::fromData(['roles' => ['other' => ['hello']]]);
    }

    public function testAnInvalidSlugThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PluginRoles::fromData(['roles' => ['seo' => ['two words']]]);
    }

    public function testTheWrongShapeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PluginRoles::fromData(['seo' => ['wordpress-seo']]);
    }
}
