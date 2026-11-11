<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Plugin;
use Voyager\Wayfinder\Scanners\CodeScanner;
use Voyager\Wayfinder\Scanners\PhpSinks;
use Voyager\Wayfinder\Tests\Support\FakeCodeSource;
use Voyager\Wayfinder\Tests\Support\WpCalls;

/**
 * The `vwf_php_sinks` filter: a site adds a sink of its own, and
 * an entry that breaks the shape is dropped without taking the list down.
 */
final class PhpSinksFilterTest extends TestCase
{
    private const CRM = ['effect' => 'record', 'label' => 'a CRM contact'];

    protected function setUp(): void
    {
        WpCalls::reset();
    }

    public function testASinkAddedByTheFilterIsReportedOnATracedRoute(): void
    {
        $this->assertSame([], $this->records(), 'Without the filter the call means nothing.');

        WpCalls::$filterCallbacks['vwf_php_sinks'] = static fn (array $sinks): array => $sinks + ['acme_crm_push' => self::CRM];

        $this->assertSame(['a CRM contact'], $this->records());
        $this->assertSame([], WpCalls::$doingItWrong);
    }

    public function testAnEntryWithAnUnknownEffectIsDroppedAndTheRestKept(): void
    {
        WpCalls::$filterCallbacks['vwf_php_sinks'] = static fn (array $sinks): array => $sinks + [
            'acme_crm_push' => ['effect' => 'explode', 'label' => 'a CRM contact'],
            'acme_text_push' => ['effect' => 'record', 'label' => 'a text message'],
        ];

        $sinks = PhpSinks::bundled();
        $wrong = WpCalls::$doingItWrong;

        $this->assertNull($sinks->sink('acme_crm_push'), 'The bad effect is dropped.');
        $this->assertSame(['effect' => 'record', 'label' => 'a text message'], $sinks->sink('acme_text_push'));
        $this->assertSame('mail', $sinks->sink('wp_mail')['effect'] ?? null, 'The bundled sinks stay.');
        $this->assertSame([], $this->records(), 'A dropped sink reports nothing on a route.');

        $this->assertCount(1, $wrong);
        $this->assertSame('vwf_php_sinks', $wrong[0]['function']);
        $this->assertStringContainsString('acme_crm_push', $wrong[0]['message']);
        $this->assertStringContainsString('needs an effect', $wrong[0]['message']);
        $this->assertSame(Plugin::VERSION, $wrong[0]['version']);
    }

    public function testAFilterThatReturnsNoArrayLeavesTheBundledList(): void
    {
        WpCalls::$filterCallbacks['vwf_php_sinks'] = static fn (array $sinks): string => 'none';

        $this->assertSame(PhpSinks::fromData(self::bundledData())->all(), PhpSinks::bundled()->all());
        $this->assertCount(1, WpCalls::$doingItWrong);
    }

    /**
     * What the theme route's traced handler records, with the bundled sinks as filtered.
     *
     * @return list<string>
     */
    private function records(): array
    {
        $source = FakeCodeSource::fixture();
        $source->files['themes/acme/inc/crm.php'] = <<<'PHP'
            <?php
            function acme_crm_handle( WP_REST_Request $request ) {
                acme_crm_push( array( 'source' => 'crm' ) );
                return new WP_REST_Response( array( 'ok' => true ), 200 );
            }
            PHP;
        $source->endpoints[] = FakeCodeSource::endpoint('/acme/v1/crm', 'acme/v1', ['POST'], $source->callback('acme_crm_handle'), FakeCodeSource::PUBLIC);

        $item = (new CodeScanner($source, PhpSinks::bundled()))->scan()->item('rest:acme/v1/crm');
        $this->assertNotNull($item, 'The CRM route is in the code section.');

        return $item->facts['endpoints'][0]['records'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function bundledData(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/data/php-sinks.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
