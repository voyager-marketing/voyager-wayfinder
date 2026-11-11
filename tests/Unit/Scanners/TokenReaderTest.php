<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Tests\Unit\Scanners;

use PHPUnit\Framework\TestCase;
use Voyager\Wayfinder\Scanners\TokenReader;

/**
 * The shared tokenizer reader, on PHP source strings: what a file
 * declares, what one body calls and with which argument shapes, what it
 * returns, the path pairs and files it names, and how far follow() goes
 * through injected resolvers.
 */
final class TokenReaderTest extends TestCase
{
    private const CONTACT = <<<'PHP'
        <?php
        namespace Acme\Site;

        use Voyager\Orbit\Modules\Leads\Module as Leads;

        const NOTIFY = 'owner@example.com';
        define( 'ACME_DWELL', 6 );

        final class Contact {
            const LIMIT = 5;

            public function handle( $request ) {
                $to = apply_filters( 'acme_contact_to', get_option( 'admin_email' ) );
                $leads = Leads::instance();
                $leads->insertLead( array( 'source' => 'contact', 'kind' => $request ) );
                $this->notify( $to );
                self::log( ACME_DWELL );
                return wp_mail( NOTIFY, 'Subject', 'Body' );
            }

            private function notify( $to ) {
                // wp_mail( 'in a comment' ) is not a call.
                $text = 'wp_mail( "in a string" )';
                return $to;
            }

            private static function log( int $dwell ): void {
            }
        }

        function acme_helper() {
            return 'fixed';
        }
        PHP;

    public function testItListsDeclaredFunctionsAndMethodsWithTheirNamespaceAndLines(): void
    {
        $declarations = TokenReader::declarations(self::CONTACT);

        $this->assertSame(['acme\site\contact::handle', 'acme\site\contact::notify', 'acme\site\contact::log', 'acme\site\acme_helper'], array_keys($declarations));
        $this->assertSame('Acme\Site\Contact::handle', $declarations['acme\site\contact::handle']['name']);
        $this->assertSame(12, $declarations['acme\site\contact::handle']['start']);
        $this->assertSame(19, $declarations['acme\site\contact::handle']['end'], 'The end line is the closing brace, not the last statement.');
        $this->assertSame('Acme\Site\Contact', $declarations['acme\site\contact::handle']['class']);
        $this->assertNull($declarations['acme\site\acme_helper']['class']);
    }

    public function testItNamesDeclaredConstantsByLineAndNeverTheirValue(): void
    {
        $constants = TokenReader::constants(self::CONTACT);

        $this->assertSame(['ACME_DWELL' => 7, 'NOTIFY' => 6], $constants, 'A class constant is not a global one.');
        $this->assertStringNotContainsString('owner@example.com', (string) json_encode($constants));
    }

    public function testItReadsCallsWithTheShapeOfTheirArguments(): void
    {
        $body = TokenReader::body(self::CONTACT, 'Acme\Site\Contact::handle');
        $this->assertNotNull($body);

        $calls = [];
        foreach ($body['calls'] as $call) {
            $calls[$call['function']] = $call;
        }

        $this->assertSame(['apply_filters', 'get_option', 'voyager\orbit\modules\leads\module::instance', 'voyager\orbit\modules\leads\module::insertlead', 'acme\site\contact::notify', 'acme\site\contact::log', 'wp_mail'], array_keys($calls));

        $this->assertSame(['kind' => 'const', 'value' => 'NOTIFY'], $calls['wp_mail']['args'][0]);
        $this->assertSame('string', $calls['wp_mail']['args'][1]['kind']);
        $this->assertSame(18, $calls['wp_mail']['line']);

        // The import alias and the instance variable resolve to Orbit's class.
        $this->assertSame('instance', $calls['voyager\orbit\modules\leads\module::insertlead']['via']);
        $this->assertSame(['source' => 'contact', 'kind' => null], $calls['voyager\orbit\modules\leads\module::insertlead']['args'][0]['keys']);

        // A variable remembers what it was assigned: a filter over an option.
        $to = $calls['acme\site\contact::notify']['args'][0];
        $this->assertSame('var', $to['kind']);
        $this->assertSame('call', $to['from']['kind'] ?? null);
        $this->assertSame('apply_filters', $to['from']['value'] ?? null);
        $this->assertSame(['kind' => 'string', 'value' => 'acme_contact_to'], $to['from']['arg'] ?? null);

        $this->assertSame(['kind' => 'const', 'value' => 'ACME_DWELL'], $calls['acme\site\contact::log']['args'][0]);
        $this->assertSame(['ACME_DWELL', 'NOTIFY'], array_values(array_unique(array_column($body['constants'], 'name'))));
    }

    public function testCommentsAndStringsThatMentionACallAreNotCalls(): void
    {
        $body = TokenReader::body(self::CONTACT, 'Acme\Site\Contact::notify');

        $this->assertNotNull($body);
        $this->assertSame([], $body['calls']);
    }

    public function testItReadsReturnsBareThroughACastAndInATernary(): void
    {
        $php = <<<'PHP'
            <?php
            function a() { return 'Fixed title'; }
            function b( $t ) { return is_front_page() ? 'Home title' : $t; }
            function c() { return (string) get_bloginfo( 'name' ); }
            function d( $t ) { return $t; }
            PHP;

        $this->assertSame([['kind' => 'string', 'value' => 'Fixed title']], TokenReader::body($php, 'a')['returns'] ?? null);
        $this->assertSame([['kind' => 'string', 'value' => 'Home title'], ['kind' => 'var', 'value' => 't', 'from' => null]], TokenReader::body($php, 'b')['returns'] ?? null);
        $this->assertSame([['kind' => 'call', 'value' => 'get_bloginfo', 'arg' => ['kind' => 'string', 'value' => 'name']]], TokenReader::body($php, 'c')['returns'] ?? null);
        $this->assertSame('var', TokenReader::body($php, 'd')['returns'][0]['kind'] ?? null);
    }

    public function testItReadsPathPairsAndTheFilesABodyNamesBesideItself(): void
    {
        $php = <<<'PHP'
            <?php
            function acme_redirect_target( string $path ): ?string {
                $map = array(
                    '/services/old/' => '/seo/',
                    '/about-us/'     => '/about/',
                    'not-a-path'     => '/x/',
                );
                $csv = __DIR__ . '/redirects.csv';
                return $map[ $path ] ?? null;
            }
            PHP;

        $body = TokenReader::body($php, 'acme_redirect_target');

        $this->assertSame([['/services/old/', '/seo/'], ['/about-us/', '/about/']], $body['pairs'] ?? null);
        $this->assertSame(['redirects.csv'], $body['files'] ?? null);
    }

    public function testFollowGoesTwoLevelsBelowTheEntryAndNoFurther(): void
    {
        $files = [
            'a.php' => "<?php\nfunction a() { b(); }\nfunction b() { c(); }\n",
            'c.php' => "<?php\nfunction c() { d(); wp_mail( 'x', 'y', 'z' ); }\nfunction d() { wp_mail( 'deep', 'y', 'z' ); }\n",
        ];
        $reader = new TokenReader(self::functions($files));

        $steps = $reader->follow('a.php', $files['a.php'], 'a');

        $this->assertSame(['a', 'b', 'c'], array_column($steps, 'name'));
        $this->assertSame([0, 1, 2], array_column($steps, 'depth'));
        $this->assertSame([null, 'a', 'b'], array_column($steps, 'from'));
        $this->assertSame(['a.php', 'a.php', 'c.php'], array_column($steps, 'file'));
        $this->assertContains('d', array_column($steps[2]['body']['calls'], 'function'), 'The third level is read, and its calls listed...');
        $this->assertCount(3, $steps, '...but what it calls is not followed.');

        $this->assertSame(['a', 'b', 'c', 'd'], array_column($reader->follow('a.php', $files['a.php'], 'a', 0, 3), 'name'));
    }

    public function testFollowResolvesMethodsThroughTheResolverAndLeavesPropertiesAlone(): void
    {
        $files = [
            'theme.php'  => "<?php\nfunction acme_handle() {\n    \$leads = \\Voyager\\Orbit\\Modules\\Leads\\Module::instance();\n    \$leads->insertLead( array() );\n}\n",
            'module.php' => "<?php\nnamespace Voyager\\Orbit\\Modules\\Leads;\nclass Module {\n    public static function instance() { return new self(); }\n    public function insertLead( array \$row ) { return \$this->store->save( \$row ); }\n}\n",
        ];
        $reader = new TokenReader(
            self::functions($files),
            static fn (string $class, string $method): ?array => str_starts_with(strtolower($class), 'voyager\orbit') ? ['file' => 'module.php', 'php' => $files['module.php']] : null
        );

        $steps = $reader->follow('theme.php', $files['theme.php'], 'acme_handle');

        $this->assertSame(['acme_handle', 'Voyager\Orbit\Modules\Leads\Module::instance', 'Voyager\Orbit\Modules\Leads\Module::insertLead'], array_column($steps, 'name'));
        $this->assertSame([], $steps[2]['body']['calls'], 'A call on a property is not resolved.');
    }

    public function testDeclaredAtLooksInTheFollowedFilesThenTheConstantSource(): void
    {
        $files = ['a.php' => "<?php\n\nconst ACME_TO = 'x@example.com';\nfunction a() { wp_mail( ACME_TO, '', '' ); wp_mail( ACME_ELSEWHERE, '', '' ); }\n"];
        $reader = new TokenReader(
            self::functions($files),
            null,
            static fn (string $name): ?array => $name === 'ACME_ELSEWHERE' ? ['file' => 'functions.php', 'line' => 40] : null
        );

        $steps = $reader->follow('a.php', $files['a.php'], 'a');

        $this->assertSame(['file' => 'a.php', 'line' => 3], $reader->declaredAt('ACME_TO', $steps));
        $this->assertSame(['file' => 'functions.php', 'line' => 40], $reader->declaredAt('ACME_ELSEWHERE', $steps));
        $this->assertNull($reader->declaredAt('ACME_NOWHERE', $steps));
    }

    public function testAClosureIsFoundByTheLineOfItsFunctionKeyword(): void
    {
        $php = "<?php\nadd_filter( 'wp_title', function ( \$title ) {\n    return is_home() ? 'Blog' : \$title;\n} );\n";

        $body = TokenReader::bodyAt($php, 2);

        $this->assertSame('is_home', $body['calls'][0]['function'] ?? null);
        $this->assertSame('Blog', $body['returns'][0]['value'] ?? null);
        $this->assertNull(TokenReader::bodyAt($php, 3));
    }

    public function testAnUnknownNameReadsNothing(): void
    {
        $this->assertNull(TokenReader::body(self::CONTACT, 'acme_missing'));
        $this->assertSame([], (new TokenReader())->follow('x.php', self::CONTACT, 'acme_missing'));
    }

    /**
     * A function resolver over in-memory files.
     *
     * @param array<string, string> $files
     * @return callable(string): ?array{file: string, php: string}
     */
    private static function functions(array $files): callable
    {
        return static function (string $name) use ($files): ?array {
            foreach ($files as $file => $php) {
                if (isset(TokenReader::declarations($php)[strtolower($name)])) {
                    return ['file' => $file, 'php' => $php];
                }
            }

            return null;
        };
    }
}
