<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Edit;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Abilities\Abilities;
use Voyager\Wayfinder\Edit\EditLocator;
use Voyager\Wayfinder\Edit\FindPayload;
use Voyager\Wayfinder\Render\Ai\Context;
use Voyager\Wayfinder\Tests\Support\FakeLayoutSource;
use Voyager\Wayfinder\Tests\Support\FakeRouteSource;
use Voyager\Wayfinder\Tests\Support\FixtureManifest;

/**
 * What find hands an agent on a page the size of a real site's home page:
 * hundreds of classes and block names, dozens of locked blocks, and a
 * heading that several places on the page repeat. A real home page answered
 * one such find with 19,991 characters, most of them the page's class and
 * lock lists. The answer must stay under 3,200 characters (three
 * candidates in about 800 tokens, at four characters a token) and still
 * name where each candidate is edited.
 *
 * Sizes are the JSON the REST route sends: wp_json_encode with no flags,
 * as WP_REST_Server::serve_request() encodes a response.
 */
final class FindPayloadSizeTest extends TestCase
{
    /** Three candidates in about 800 tokens, at four characters a token. */
    private const BUDGET = 3200;

    /** Sections on the page; each carries two classes of its own and a block name, and every other one a lock. */
    private const SECTIONS = 150;

    /** A stored manifest hash, as a site that has been scanned answers with. */
    private const HASH = 'f3a1c9e07b5d24681ac0e93f5b7d2c4e6a8b0d1f3e5c7a9b2d4f6e8a0c1b3d5e';

    public function testALargePageAnswersAHeadingItRepeatsInUnder3200Characters(): void
    {
        $answer = self::abilities()->find(['url' => '/', 'text' => 'Why Voyager']);

        $this->assertIsArray($answer);
        $this->assertLessThan(self::BUDGET, self::chars($answer), 'find on a large page must answer in under 3,200 characters');
        $this->assertCount(3, $answer['hits'], 'three candidates');
        $this->assertLessThanOrEqual(3, count($answer['chain']));
    }

    public function testWithoutTheTrimTheSamePageRunsFarPastTheBudget(): void
    {
        // The positive control: without the trim the same question runs far past the budget, on the inventories.
        $full = self::locator()->find('/', 'Why Voyager');
        $content = array_column($full['chain'], null, 'id')['pt:page'];

        $this->assertGreaterThan(3 * self::BUDGET, self::chars($full));
        $this->assertGreaterThan(300, $content['classes_more'] ?? 0);
        $this->assertCount(50, $content['block_names'] ?? []);
        $this->assertCount(50, $content['locked_blocks'] ?? [], 'the page has 75 locked blocks; the list stops at its cap');
        $this->assertGreaterThan(3, count($full['hits']));
    }

    public function testTheUrlAloneFitsToo(): void
    {
        $answer = self::abilities()->find(['url' => '/']);

        $this->assertIsArray($answer);
        $this->assertLessThan(self::BUDGET, self::chars($answer));
        $this->assertNull($answer['matched']);
        $this->assertSame(['pt:page', 'tpl:page', 'part:footer'], array_column($answer['chain'], 'id'), 'the first three, in the order find gives them');
        $this->assertGreaterThan(0, $answer['chain_more'] ?? 0);
    }

    public function testEachCandidateKeepsWhatAnAgentEditsWith(): void
    {
        $answer = self::abilities()->find(['url' => '/', 'text' => 'Why Voyager']);
        $full = self::locator()->find('/', 'Why Voyager');

        $this->assertIsArray($answer);
        $this->assertSame(FindPayload::COMPACT, $answer['detail']);
        $this->assertSame(array_slice($full['hits'], 0, 3), $answer['hits'], 'the first three hits, in rank order, unchanged');
        $this->assertSame(count($full['hits']) - 3, $answer['hits_more']);
        $this->assertSame($full['verify'], $answer['verify']);
        $this->assertSame(self::HASH, $answer['verify']['changes_since']);

        foreach ($answer['chain'] as $i => $entry) {
            $kept = array_diff_key($full['chain'][$i], array_flip(FindPayload::INVENTORIES));
            $this->assertSame($kept, $entry, 'a chain entry keeps everything but the inventories');

            foreach (['id', 'label', 'edit', 'instruction', 'match', 'lock', 'client_can', 'client_can_why'] as $key) {
                $this->assertArrayHasKey($key, $entry, $entry['id'] . ' keeps ' . $key);
            }

            foreach (FindPayload::INVENTORIES as $key) {
                $this->assertArrayNotHasKey($key, $entry);
            }
        }

        $this->assertSame($answer['hits'][0]['anchor'], $answer['chain'][0]['anchor'] ?? null, 'the top candidate keeps its anchor');

        foreach ($answer['hits'] as $hit) {
            $this->assertNotNull($hit['anchor'], $hit['id'] . ' ' . $hit['block_path']);
        }
    }

    public function testDetailFullReturnsTheWholeResult(): void
    {
        $full = self::abilities()->find(['url' => '/', 'text' => 'Why Voyager', 'detail' => 'full']);

        $this->assertSame(['detail' => 'full'] + self::locator()->find('/', 'Why Voyager'), $full);
    }

    public function testAnswersWithNothingToCutAreTheWholeResult(): void
    {
        $locator = new EditLocator(FakeRouteSource::fixture(), FakeRouteSource::layout());
        $result = $locator->find('/', '555-0142');

        $this->assertCount(4, $result['chain']);
        $this->assertCount(1, $result['hits']);

        $shaped = FindPayload::shape($result);
        $this->assertSame(array_slice($result['chain'], 0, 3), $shaped['chain']);
        $this->assertSame($result['hits'], $shaped['hits']);
        $this->assertArrayNotHasKey('hits_more', $shaped);
        $this->assertSame(1, $shaped['chain_more']);

        $contact = $locator->find('/contact/', 'send us a message');
        $this->assertSame(['detail' => 'compact'] + $contact, FindPayload::shape($contact), 'two entries and one hit: nothing to cut');
    }

    public function testAMissSaysTheChainWasCut(): void
    {
        $miss = self::locator()->find('/', 'free estimates');

        $this->assertFalse($miss['matched']);
        $this->assertStringEndsWith(EditLocator::WHOLE_PAGE, (string) $miss['message']);

        $shaped = FindPayload::shape($miss);
        $this->assertSame(
            'No block on this page contains that text. It may come from PHP, a plugin or a dynamic block. ' . FindPayload::FIRST_ENTRIES,
            $shaped['message']
        );
        $this->assertSame($miss['message'], FindPayload::shape($miss, FindPayload::FULL)['message']);
    }

    public function testDetailIsCompactUnlessFullIsAsked(): void
    {
        $this->assertSame('compact', FindPayload::detail(null));
        $this->assertSame('compact', FindPayload::detail(''));
        $this->assertSame('compact', FindPayload::detail('compact'));
        $this->assertSame('full', FindPayload::detail('full'));
        $this->assertNull(FindPayload::detail('FULL'));
        $this->assertNull(FindPayload::detail('all'));
        $this->assertNull(FindPayload::detail(['full']));

        $schema = (new Abilities())->definitions(false)[Abilities::FIND_EDIT_LOCATION]['input_schema']['properties']['detail'];
        $this->assertSame(['compact', 'full'], $schema['enum']);
    }

    /**
     * The REST bytes: wp_json_encode with no flags, so slashes and non-ASCII are escaped.
     *
     * @param array<string, mixed> $answer
     */
    private static function chars(array $answer): int
    {
        return strlen((string) wp_json_encode($answer));
    }

    private static function abilities(): Abilities
    {
        return new Abilities(
            static fn (): Context => new Context(static fn () => FixtureManifest::manifest(), static fn (): array => []),
            static fn (): EditLocator => self::locator()
        );
    }

    /**
     * The fixture site with a home page the size of a real one. The page
     * repeats its "Why Voyager" heading once, and a synced band on it
     * repeats it four times, so a text search finds more homes than the
     * answer keeps.
     */
    private static function locator(): EditLocator
    {
        $routes = FakeRouteSource::fixture();
        $layout = FakeRouteSource::layout();

        $sections = [];

        for ($i = 1; $i <= self::SECTIONS; $i++) {
            $n = sprintf('%03d', $i);
            $attrs = ['className' => 'vm-section-' . $n . ' vm-band-' . $n, 'metadata' => ['name' => 'Section ' . $n]];

            if ($i % 2 === 0) {
                $attrs['lock'] = ['move' => true, 'remove' => true];
            }

            $heading = $i === 1 ? 'Why Voyager' : 'Section ' . $n . ' heading';
            $sections[] = '<!-- wp:group ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' -->' . "\n"
                . '<div class="wp-block-group vm-section-' . $n . ' vm-band-' . $n . '">'
                . '<!-- wp:heading {"className":"vm-heading-' . $n . '"} -->' . "\n"
                . '<h2 class="wp-block-heading vm-heading-' . $n . '">' . $heading . '</h2>' . "\n"
                . '<!-- /wp:heading -->' . "\n"
                . FakeLayoutSource::paragraph('Copy for section ' . $n . ', long enough to read like a real paragraph on a marketing page.')
                . '</div>' . "\n" . '<!-- /wp:group -->';
        }

        $sections[] = '<!-- wp:block {"ref":90071} /-->';
        $home = implode("\n\n", $sections);
        $routes->add(10, 'page', 'home', 'Home', '/', '', $home);

        $band = '';
        foreach (['Strategy', 'Design', 'Build', 'Grow'] as $step) {
            $band .= '<!-- wp:heading {"className":"vm-band-heading"} -->' . "\n"
                . '<h3 class="wp-block-heading vm-band-heading">Why Voyager</h3>' . "\n"
                . '<!-- /wp:heading -->' . "\n"
                . FakeLayoutSource::paragraph($step . ' with a team that ships.') . "\n\n";
        }

        $layout->patterns[] = ['id' => 90071, 'slug' => 'why-voyager-band', 'title' => 'Why Voyager band', 'synced' => true, 'content' => $band];
        $layout->content[0] = ['type' => 'page', 'slug' => 'home', 'content' => $home];

        return new EditLocator(
            $routes,
            $layout,
            null,
            null,
            static fn (): array => [
                'route:front-page'  => ['pt:page', 'tpl:page', 'part:header', 'part:footer', 'pat:why-voyager-band', 'pat:cta-banner'],
                'route:single-page' => ['pt:page', 'tpl:page', 'part:header', 'part:footer', 'pat:cta-banner'],
            ],
            static fn (): string => self::HASH
        );
    }
}
