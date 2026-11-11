<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use Closure;

/**
 * Reads what PHP source does without running it, from PHP's tokenizer.
 *
 * For a file: the functions and methods it declares, with their lines, and
 * the constants it declares with `const` or `define()`. For one function or
 * method body: the calls it makes, each argument as a shape (a string
 * literal, a constant by name, a variable and what it was last assigned, a
 * call and its first argument, an array with its literal keys), the
 * constants it reads, what it returns, the path pairs it maps
 * (`'/a/' => '/b/'`) and the files it names beside itself
 * (`__DIR__ . '/redirects.csv'`).
 *
 * Calls are followed into other user code through two injectable
 * resolvers, at most DEPTH levels below the entry (follow()). A method call
 * resolves when the reader knows the class: a static call, `$this->`,
 * `self::` and `static::`, a call chained onto `Class::instance()`, and a
 * variable assigned `new Class(...)` or `Class::instance()` (and the like)
 * earlier in the same body. Calls on properties, on objects passed in and on
 * containers stay unresolved, as do values held in variables and work a
 * listener, cron job or queue does later. So a negative fact ("calls no
 * mail function") holds for the code this reader follows, and no further.
 *
 * Shared by the code section (REST handlers and head filters) and
 * the redirect scan. WordPress-free, so the unit suite reads PHP
 * source strings. It never runs code, never reads a stored value and never
 * returns code text: names, shapes, literal strings and line numbers. The
 * caller decides what reaches the manifest, and a constant's value is never
 * read at all.
 *
 * Built on token_get_all(), which costs less memory than PhpToken. Without
 * the tokenizer extension it reads nothing, and available() says so, so a
 * caller can report "not traced" rather than "does nothing".
 *
 * @phpstan-type Arg array{kind: string, value: string|null, arg?: array<string, mixed>|null, keys?: array<string, string|null>, from?: array<string, mixed>|null, class?: string|null}
 * @phpstan-type Call array{function: string, line: int, args: list<Arg>, via?: string}
 * @phpstan-type Body array{calls: list<Call>, constants: list<array{name: string, line: int}>, returns: list<Arg>, pairs: list<array{0: string, 1: string}>, files: list<string>}
 * @phpstan-type Declaration array{name: string, start: int, end: int, open: int, close: int, class: string|null}
 * @phpstan-type Source array{file: string, php: string}
 * @phpstan-type Step array{name: string, file: string, start: int, end: int, depth: int, from: string|null, body: Body}
 * @phpstan-type Token array{0: int, 1: string, 2: int}|string
 */
final class TokenReader
{
    /** How many levels below the entry follow() reads by default. */
    public const DEPTH = 2;

    /** Static factory names whose result is an instance of their class. */
    private const FACTORIES = ['instance', 'get_instance', 'getinstance', 'init', 'get', 'singleton', 'factory', 'create'];

    /** Bare words that are not constants. */
    private const NOT_CONSTANTS = ['true', 'false', 'null', 'self', 'static', 'parent', 'array', 'list', 'callable'];

    /** @var Closure(string): ?array{file: string, php: string} */
    private Closure $functions;

    /** @var Closure(string, string): ?array{file: string, php: string} */
    private Closure $methods;

    /** @var Closure(string): ?array{file: string, line: int} */
    private Closure $constantSource;

    /** @var array<string, list<array{0: int, 1: string, 2: int}|string>> Tokens by file, read once. */
    private array $tokens = [];

    /** @var array<string, list<int>> The line of each token, by file. */
    private array $lines = [];

    /** @var array<string, array<string, array{name: string, start: int, end: int, open: int, close: int, class: string|null}>> */
    private array $declarations = [];

    /** @var array<string, array<string, int>> */
    private array $constants = [];

    /**
     * @param (callable(string): ?array{file: string, php: string})|null         $functions      Where a user function is declared, by name as called
     *                                                                                          (namespace without a leading `\`). Null for a PHP
     *                                                                                          built-in, a core function or one not found.
     * @param (callable(string, string): ?array{file: string, php: string})|null $methods        Where a method is declared, by class and method name.
     * @param (callable(string): ?array{file: string, line: int})|null           $constantSource Where a constant the followed files do not declare is
     *                                                                                          declared. Defaults to nowhere.
     */
    public function __construct(?callable $functions = null, ?callable $methods = null, ?callable $constantSource = null)
    {
        $this->functions = $functions !== null ? Closure::fromCallable($functions) : static fn (string $name): ?array => null;
        $this->methods = $methods !== null ? Closure::fromCallable($methods) : static fn (string $class, string $method): ?array => null;
        $this->constantSource = $constantSource !== null ? Closure::fromCallable($constantSource) : static fn (string $name): ?array => null;
    }

    public static function available(): bool
    {
        return function_exists('token_get_all') && defined('T_NAME_FULLY_QUALIFIED');
    }

    /**
     * Functions and methods a file declares, keyed by lowercase name:
     * `voyagermark_contact_handle`, or `acme\leads\module::insertlead` for a
     * method (the class with its namespace). Closures are not declarations;
     * a callback that is one is found with bodyAt().
     *
     * @return array<string, Declaration>
     */
    public static function declarations(string $php): array
    {
        [$tokens, $lines] = self::lex($php);

        return self::declare($tokens, $lines);
    }

    /**
     * Constants a file declares outside any class, with the line of the
     * declaration: `const NAME = ...;` and `define('NAME', ...)`. Never the
     * value.
     *
     * @return array<string, int> Name => line, sorted by name.
     */
    public static function constants(string $php): array
    {
        return self::declaredConstants(self::lex($php)[0]);
    }

    /**
     * What one declared function or method does: `voyagermark_contact_handle`,
     * or `Class::method` with the class's namespace. Null when the source
     * declares no such thing, or the tokenizer is missing.
     *
     * @return Body|null
     */
    public static function body(string $php, string $name): ?array
    {
        [$tokens, $lines] = self::lex($php);
        $declaration = self::declare($tokens, $lines)[strtolower(ltrim($name, '\\'))] ?? null;

        return $declaration !== null ? self::read($tokens, $declaration) : null;
    }

    /**
     * What the function or closure whose `function` keyword is on this line
     * does. A closure is found by the line reflection reports for it.
     *
     * @return Body|null
     */
    public static function bodyAt(string $php, int $line): ?array
    {
        [$tokens, $lines] = self::lex($php);
        $declaration = self::declarationAt($tokens, $lines, $line);

        return $declaration !== null ? self::read($tokens, $declaration) : null;
    }

    /**
     * The entry and every user function and method it reaches, breadth
     * first, at most `$depth` levels below it. Each step names the function,
     * its file and lines, its depth, the step it was reached from and its
     * body. A function reached twice is read once.
     *
     * @param string $file  How the resolvers name the entry's file; passed back unchanged.
     * @param string $name  A declared function or `Class::method`, or '' to find the entry by `$line`.
     * @param int    $line  The line of the entry's `function` keyword, for a closure.
     * @return list<Step>
     */
    public function follow(string $file, string $php, string $name, int $line = 0, int $depth = self::DEPTH): array
    {
        $this->load($file, $php);
        $declaration = $name !== ''
            ? ($this->declarationsOf($file)[strtolower(ltrim($name, '\\'))] ?? null)
            : self::declarationAt($this->tokens[$file], $this->lines[$file], $line);

        if ($declaration === null) {
            return [];
        }

        $steps = [];
        $seen = [strtolower($file . '#' . $declaration['start']) => true];
        $queue = [[$file, $declaration, 0, null]];

        while ($queue !== []) {
            [$at, $current, $level, $from] = array_shift($queue);
            $body = self::read($this->tokens[$at], $current);
            $steps[] = [
                'name'  => $current['name'],
                'file'  => $at,
                'start' => $current['start'],
                'end'   => $current['end'],
                'depth' => $level,
                'from'  => $from,
                'body'  => $body,
            ];

            if ($level >= $depth) {
                continue;
            }

            foreach ($body['calls'] as $call) {
                $target = $this->resolve($call['function']);

                if ($target === null) {
                    continue;
                }

                [$targetFile, $targetDeclaration] = $target;
                $key = strtolower($targetFile . '#' . $targetDeclaration['start']);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $queue[] = [$targetFile, $targetDeclaration, $level + 1, $current['name']];
            }
        }

        return $steps;
    }

    /**
     * Where a constant is declared: the first followed file that declares
     * it, in the order the steps reached them, then the constant source.
     * Null when neither knows. The value is never read.
     *
     * @param list<Step> $steps
     * @return array{file: string, line: int}|null
     */
    public function declaredAt(string $name, array $steps): ?array
    {
        foreach ($steps as $step) {
            $this->constants[$step['file']] ??= self::declaredConstants($this->tokens[$step['file']] ?? []);

            if (isset($this->constants[$step['file']][$name])) {
                return ['file' => $step['file'], 'line' => $this->constants[$step['file']][$name]];
            }
        }

        return ($this->constantSource)($name);
    }

    /**
     * The file and declaration a call reaches, or null when it reaches no
     * user code the resolvers know.
     *
     * @return array{0: string, 1: Declaration}|null
     */
    private function resolve(string $function): ?array
    {
        $method = str_contains($function, '::') ? explode('::', $function, 2) : null;
        $source = $method !== null ? ($this->methods)($method[0], $method[1]) : ($this->functions)($function);

        if ($source === null) {
            return null;
        }

        $this->load($source['file'], $source['php']);
        $declaration = $this->declarationsOf($source['file'])[strtolower($function)] ?? null;

        if ($declaration === null && $method !== null) {
            // The class may sit in another namespace than the one written: match on the short name.
            $short = strtolower(substr(strrchr('\\' . $method[0], '\\') ?: '', 1) . '::' . $method[1]);

            foreach ($this->declarationsOf($source['file']) as $key => $candidate) {
                if (str_ends_with($key, '\\' . $short) || $key === $short) {
                    $declaration = $candidate;
                    break;
                }
            }
        }

        return $declaration !== null ? [$source['file'], $declaration] : null;
    }

    /**
     * @return array<string, Declaration>
     */
    private function declarationsOf(string $file): array
    {
        return $this->declarations[$file] ??= self::declare($this->tokens[$file] ?? [], $this->lines[$file] ?? []);
    }

    /**
     * Reads a file's tokens once.
     */
    private function load(string $file, string $php): void
    {
        if (!isset($this->tokens[$file])) {
            [$this->tokens[$file], $this->lines[$file]] = self::lex($php);
        }
    }

    /**
     * Tokens without whitespace and comments, and the line each one starts
     * on, single characters included (the tokenizer gives those no line).
     *
     * @return array{0: list<array{0: int, 1: string, 2: int}|string>, 1: list<int>}
     */
    private static function lex(string $php): array
    {
        if (!self::available()) {
            return [[], []];
        }

        $tokens = [];
        $lines = [];
        $line = 1;

        foreach (token_get_all($php) as $token) {
            $at = is_array($token) ? $token[2] : $line;
            $line = $at + substr_count(is_array($token) ? $token[1] : $token, "\n");

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = $token;
            $lines[] = $at;
        }

        return [$tokens, $lines];
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<int>                                     $lines
     * @return array<string, Declaration>
     */
    private static function declare(array $tokens, array $lines): array
    {
        $declarations = [];
        $namespace = '';
        $classes = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null)) {
                $namespace = in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true) ? $tokens[$i + 1][1] : '';
                continue;
            }

            if (self::isClassLike($tokens, $i)) {
                $name = is_array($tokens[$i + 1] ?? null) ? $tokens[$i + 1][1] : '';
                $open = self::next($tokens, $i, '{');

                if ($open !== null && $name !== '') {
                    $classes[] = ['name' => ($namespace !== '' ? $namespace . '\\' : '') . $name, 'depth' => $depth];
                    $depth++;
                    $i = $open;
                }

                continue;
            }

            if (is_array($token) && $token[0] === T_FUNCTION && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING) {
                $paren = self::next($tokens, $i, '(');
                $close = $paren !== null ? self::match($tokens, $paren) : null;
                $open = $close !== null ? self::bodyOpen($tokens, $close) : null;

                if ($open === null) {
                    continue;
                }

                $end = self::match($tokens, $open);
                $class = $classes !== [] ? $classes[count($classes) - 1]['name'] : null;
                $short = $tokens[$i + 1][1];
                $name = $class !== null
                    ? $class . '::' . $short
                    : ($namespace !== '' ? $namespace . '\\' : '') . $short;

                $declarations[strtolower($name)] ??= [
                    'name'  => $name,
                    'start' => $token[2],
                    'end'   => $lines[$end ?? $open] ?? $token[2],
                    'open'  => $open,
                    'close' => $end ?? count($tokens) - 1,
                    'class' => $class,
                ];

                $i = $end ?? $open;
                continue;
            }

            if (self::opensBrace($token)) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;

                if ($classes !== [] && $classes[count($classes) - 1]['depth'] === $depth) {
                    array_pop($classes);
                }
            }
        }

        return $declarations;
    }

    /**
     * The function or closure whose `function` (or `fn`) keyword is on this line.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<int>                                     $lines
     * @return Declaration|null
     */
    private static function declarationAt(array $tokens, array $lines, int $line): ?array
    {
        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION || $token[2] !== $line) {
                continue;
            }

            $paren = self::next($tokens, $i, '(');
            $close = $paren !== null ? self::match($tokens, $paren) : null;
            $open = $close !== null ? self::bodyOpen($tokens, $close) : null;

            if ($open === null) {
                continue;
            }

            $end = self::match($tokens, $open);
            $name = is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING ? $tokens[$i + 1][1] : '{closure}';

            return ['name' => $name, 'start' => $line, 'end' => $lines[$end ?? $open] ?? $line, 'open' => $open, 'close' => $end ?? count($tokens) - 1, 'class' => null];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, int>
     */
    private static function declaredConstants(array $tokens): array
    {
        $constants = [];
        $depth = 0;
        $classDepths = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::isClassLike($tokens, $i)) {
                $open = self::next($tokens, $i, '{');

                if ($open !== null) {
                    $classDepths[] = $depth;
                    $depth++;
                    $i = $open;
                }

                continue;
            }

            if (self::opensBrace($token)) {
                $depth++;
                continue;
            }

            if ($token === '}') {
                $depth--;

                if ($classDepths !== [] && end($classDepths) === $depth) {
                    array_pop($classDepths);
                }

                continue;
            }

            if ($classDepths !== [] || !is_array($token)) {
                continue;
            }

            if ($token[0] === T_CONST && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_STRING && ($tokens[$i + 2] ?? null) === '=') {
                $constants[$tokens[$i + 1][1]] ??= $token[2];
            } elseif (
                $token[0] === T_STRING
                && strtolower($token[1]) === 'define'
                && ($tokens[$i + 1] ?? null) === '('
                && !self::isMember($tokens[$i - 1] ?? null)
            ) {
                $name = self::stringLiteral($tokens[$i + 2] ?? null);

                if ($name !== null && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                    $constants[$name] ??= $token[2];
                }
            }
        }

        ksort($constants, SORT_STRING);

        return $constants;
    }

    /**
     * Everything one body does, read between its braces.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param Declaration                                   $declaration
     * @return Body
     */
    private static function read(array $tokens, array $declaration): array
    {
        $namespace = self::namespaceAt($tokens, $declaration['open']);
        $imports = self::imports($tokens);
        $class = $declaration['class'];
        $calls = [];
        $constants = [];
        $returns = [];
        $pairs = [];
        $files = [];
        $variables = [];

        for ($i = $declaration['open'] + 1; $i < $declaration['close']; $i++) {
            $token = $tokens[$i];

            // A nested closure or function is its own body: skip it.
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $paren = self::next($tokens, $i, '(');
                $close = $paren !== null ? self::match($tokens, $paren) : null;
                $open = $close !== null ? self::bodyOpen($tokens, $close) : null;
                $i = $open !== null ? (self::match($tokens, $open) ?? $i) : $i;
                continue;
            }

            // `$name = <expression>;` remembers the expression's shape for later calls.
            if (is_array($token) && $token[0] === T_VARIABLE && ($tokens[$i + 1] ?? null) === '=' && !self::isMember($tokens[$i - 1] ?? null)) {
                $end = self::expressionEnd($tokens, $i + 2, $declaration['close']);
                $variables[substr($token[1], 1)] = self::shape(array_slice($tokens, $i + 2, $end - $i - 2), $namespace, $imports, $class, $variables);
                continue;
            }

            if (is_array($token) && $token[0] === T_RETURN) {
                $end = self::expressionEnd($tokens, $i + 1, $declaration['close']);
                array_push($returns, ...self::returned(array_slice($tokens, $i + 1, $end - $i - 1), $namespace, $imports, $class, $variables));
            }

            if (is_array($token) && $token[0] === T_DIR && ($tokens[$i + 1] ?? null) === '.') {
                $file = self::stringLiteral($tokens[$i + 2] ?? null);

                if ($file !== null && $file !== '') {
                    $files[] = ltrim($file, '/');
                }
            }

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                $key = self::stringLiteral($tokens[$i - 1] ?? null);
                $value = self::stringLiteral($tokens[$i + 1] ?? null);
                $after = $tokens[$i + 2] ?? null;

                if ($key !== null && $value !== null && str_starts_with($key, '/') && str_starts_with($value, '/') && ($after === ',' || $after === ')' || $after === ']')) {
                    $pairs[] = [$key, $value];
                }
            }

            $call = self::callAt($tokens, $i, $namespace, $imports, $class, $variables);

            if ($call !== null) {
                $calls[] = $call;
                continue;
            }

            if (self::isConstant($tokens, $i)) {
                $constants[] = ['name' => ltrim((string) $token[1], '\\'), 'line' => (int) $token[2]];
            }
        }

        return ['calls' => $calls, 'constants' => $constants, 'returns' => $returns, 'pairs' => $pairs, 'files' => array_values(array_unique($files))];
    }

    /**
     * The call that starts at token `$i`, or null. Functions are named as
     * written, lowercase, without a leading `\`. Methods are
     * `<class>::<method>` with the class resolved through the file's
     * namespace and `use` imports, all lowercase.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                        $imports
     * @param array<string, array<string, mixed>>          $variables
     * @return Call|null
     */
    private static function callAt(array $tokens, int $i, string $namespace, array $imports, ?string $class, array $variables): ?array
    {
        $token = $tokens[$i];

        if (!is_array($token)) {
            return null;
        }

        $before = $tokens[$i - 1] ?? null;

        // `name(`: a function call, not a method, a declaration or `new`.
        if (in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) && ($tokens[$i + 1] ?? null) === '(' && !self::isMember($before) && !(is_array($before) && in_array($before[0], [T_FUNCTION, T_NEW, T_FN], true))) {
            return ['function' => strtolower(ltrim($token[1], '\\')), 'line' => $token[2], 'args' => self::args($tokens, $i + 2, $namespace, $imports, $class, $variables)];
        }

        // `Class::method(`: a static call, or `self::`, `static::`.
        if (
            in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_STATIC], true)
            && is_array($tokens[$i + 1] ?? null) && $tokens[$i + 1][0] === T_DOUBLE_COLON
            && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING
            && ($tokens[$i + 3] ?? null) === '('
            && !self::isMember($before)
        ) {
            $owner = self::className($token[1], $namespace, $imports, $class);

            if ($owner === null) {
                return null;
            }

            $method = strtolower($tokens[$i + 2][1]);
            $open = $i + 3;
            $close = self::match($tokens, $open);

            // `Class::instance()->method(`: the chained call is on the class.
            if (
                $close !== null && in_array($method, self::FACTORIES, true)
                && is_array($tokens[$close + 1] ?? null) && in_array($tokens[$close + 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && is_array($tokens[$close + 2] ?? null) && $tokens[$close + 2][0] === T_STRING
                && ($tokens[$close + 3] ?? null) === '('
            ) {
                return ['function' => strtolower($owner . '::' . $tokens[$close + 2][1]), 'line' => $tokens[$close + 2][2], 'args' => self::args($tokens, $close + 4, $namespace, $imports, $class, $variables), 'via' => 'instance'];
            }

            return ['function' => strtolower($owner . '::' . $method), 'line' => $token[2], 'args' => self::args($tokens, $open + 1, $namespace, $imports, $class, $variables)];
        }

        // `$var->method(`: resolved when `$var` was assigned an instance, or is `$this`.
        if (
            $token[0] === T_VARIABLE
            && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_STRING
            && ($tokens[$i + 3] ?? null) === '('
            && !self::isMember($before)
        ) {
            $name = substr($token[1], 1);
            $owner = $name === 'this' ? $class : ($variables[$name]['class'] ?? null);

            if (!is_string($owner)) {
                return null;
            }

            return ['function' => strtolower($owner . '::' . $tokens[$i + 2][1]), 'line' => $tokens[$i + 2][2], 'args' => self::args($tokens, $i + 4, $namespace, $imports, $class, $variables), 'via' => 'instance'];
        }

        return null;
    }

    /**
     * Arguments of a call, from the token after its `(` to the matching `)`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                        $imports
     * @param array<string, array<string, mixed>>          $variables
     * @return list<Arg>
     */
    private static function args(array $tokens, int $start, string $namespace, array $imports, ?string $class, array $variables): array
    {
        $args = [];

        foreach (self::split($tokens, $start, [')']) as $part) {
            $args[] = self::shape($part, $namespace, $imports, $class, $variables);
        }

        return $args;
    }

    /**
     * Splits the tokens from `$start` at top-level commas until an unmatched
     * closing bracket.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param list<string>                                  $stops
     * @return list<list<array{0: int, 1: string, 2: int}|string>>
     */
    private static function split(array $tokens, int $start, array $stops): array
    {
        $parts = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    break;
                }

                $depth--;
            } elseif (self::opens($token)) {
                $depth++;
            } elseif ($token === ',' && $depth === 0) {
                $parts[] = $current;
                $current = [];
                continue;
            }

            $current[] = $token;
        }

        if ($current !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * The shape of one expression.
     *
     * - `string`: a string literal, `value` the string.
     * - `number`, `bool`, `null`: a literal, `value` as written.
     * - `const`: a constant by name, `value` the name. Never its value.
     * - `class_const`: `Class::NAME`.
     * - `var`: a variable, `value` the name without `$`, `from` the shape it was last assigned in this body.
     * - `call`: a call, `value` the function (or `Class::method`), `arg` its first argument's shape.
     * - `new`: `new Class(...)`, `value` the class.
     * - `array`: an array literal, `keys` its string keys with each value's string literal or null.
     * - `other`: anything else (concatenation, arithmetic, ...).
     *
     * A shape that resolves to an instance carries `class`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                        $imports
     * @param array<string, array<string, mixed>>          $variables
     * @return Arg
     */
    private static function shape(array $tokens, string $namespace, array $imports, ?string $class, array $variables): array
    {
        $tokens = self::withoutCasts($tokens);
        $count = count($tokens);
        $first = $tokens[0] ?? null;

        if ($count === 1 && is_array($first)) {
            $literal = self::stringLiteral($first);

            return match (true) {
                $literal !== null                                         => ['kind' => 'string', 'value' => $literal],
                in_array($first[0], [T_LNUMBER, T_DNUMBER], true)         => ['kind' => 'number', 'value' => $first[1]],
                $first[0] === T_VARIABLE                                  => ['kind' => 'var', 'value' => substr($first[1], 1), 'from' => $variables[substr($first[1], 1)] ?? null],
                in_array(strtolower($first[1]), ['true', 'false'], true)  => ['kind' => 'bool', 'value' => strtolower($first[1])],
                strtolower($first[1]) === 'null'                          => ['kind' => 'null', 'value' => null],
                in_array($first[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true) => ['kind' => 'const', 'value' => ltrim($first[1], '\\')],
                default                                                   => ['kind' => 'other', 'value' => null],
            };
        }

        if (is_array($first) && $first[0] === T_NEW && is_array($tokens[1] ?? null)) {
            $name = self::className($tokens[1][1], $namespace, $imports, $class);

            return ['kind' => 'new', 'value' => $name, 'class' => $name];
        }

        if (
            $count === 3 && is_array($first) && is_array($tokens[1]) && $tokens[1][0] === T_DOUBLE_COLON
            && is_array($tokens[2]) && $tokens[2][0] === T_STRING
        ) {
            return ['kind' => 'class_const', 'value' => self::className($first[1], $namespace, $imports, $class) . '::' . $tokens[2][1]];
        }

        if (($first === '[' && end($tokens) === ']') || (is_array($first) && $first[0] === T_ARRAY && ($tokens[1] ?? null) === '(' && end($tokens) === ')')) {
            $start = $first === '[' ? 1 : 2;

            return ['kind' => 'array', 'value' => null, 'keys' => self::keys($tokens, $start)];
        }

        $call = $count > 0 ? self::callAt($tokens, 0, $namespace, $imports, $class, $variables) : null;

        if ($call !== null && self::callEnds($tokens)) {
            $shape = ['kind' => 'call', 'value' => $call['function'], 'arg' => $call['args'][0] ?? null];

            if (str_contains($call['function'], '::') && in_array(substr($call['function'], (int) strrpos($call['function'], ':') + 1), self::FACTORIES, true)) {
                $shape['class'] = substr($call['function'], 0, (int) strpos($call['function'], '::'));
            }

            return $shape;
        }

        return ['kind' => 'other', 'value' => null];
    }

    /**
     * What one `return` gives back: its shape, or for a ternary at the top
     * level, the shape of each branch.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @param array<string, string>                        $imports
     * @param array<string, array<string, mixed>>          $variables
     * @return list<Arg>
     */
    private static function returned(array $tokens, string $namespace, array $imports, ?string $class, array $variables): array
    {
        $question = null;
        $colon = null;
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if (self::opens($token)) {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
            } elseif ($depth === 0 && $token === '?' && $question === null) {
                $question = $i;
            } elseif ($depth === 0 && $token === ':' && $question !== null && $colon === null) {
                $colon = $i;
            }
        }

        if ($question !== null && $colon !== null) {
            return [
                self::shape(array_slice($tokens, $question + 1, $colon - $question - 1), $namespace, $imports, $class, $variables),
                self::shape(array_slice($tokens, $colon + 1), $namespace, $imports, $class, $variables),
            ];
        }

        return $tokens === [] ? [] : [self::shape($tokens, $namespace, $imports, $class, $variables)];
    }

    /**
     * String keys of an array literal, each with its value when that is a
     * string literal.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string|null>
     */
    private static function keys(array $tokens, int $start): array
    {
        $keys = [];

        foreach (self::split($tokens, $start, [')', ']']) as $part) {
            if (count($part) >= 3 && is_array($part[1]) && $part[1][0] === T_DOUBLE_ARROW) {
                $key = self::stringLiteral($part[0]);

                if ($key !== null) {
                    $keys[$key] = count($part) === 3 ? self::stringLiteral($part[2]) : null;
                }
            }
        }

        return $keys;
    }

    /**
     * True when the expression is one call and nothing after it.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function callEnds(array $tokens): bool
    {
        $last = count($tokens) - 1;

        foreach ($tokens as $i => $token) {
            if ($token === '(') {
                $close = self::match($tokens, $i);

                if ($close === null) {
                    return false;
                }

                // `Class::instance()->method(...)` ends at the second call.
                if ($close < $last && is_array($tokens[$close + 1]) && in_array($tokens[$close + 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                    continue;
                }

                return $close === $last;
            }
        }

        return false;
    }

    /**
     * A bare word read as a constant: upper case, not called, not a class,
     * a type, a label or a member.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isConstant(array $tokens, int $i): bool
    {
        $token = $tokens[$i];

        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $name = ltrim($token[1], '\\');
        $before = $tokens[$i - 1] ?? null;
        $after = $tokens[$i + 1] ?? null;

        if (in_array(strtolower($name), self::NOT_CONSTANTS, true) || preg_match('/^[A-Z][A-Z0-9_]*$/', $name) !== 1) {
            return false;
        }

        if (self::isMember($before) || (is_array($before) && in_array($before[0], [T_NEW, T_INSTANCEOF, T_CONST, T_FUNCTION, T_CASE, T_GOTO, T_USE], true))) {
            return false;
        }

        return $after !== '(' && $after !== ':' && !(is_array($after) && in_array($after[0], [T_DOUBLE_COLON, T_VARIABLE, T_ELLIPSIS], true));
    }

    /**
     * Where an expression starting at `$start` ends: its `;`, or the end of
     * the body.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function expressionEnd(array $tokens, int $start, int $limit): int
    {
        $depth = 0;

        for ($i = $start; $i < $limit; $i++) {
            $token = $tokens[$i];

            if (self::opens($token)) {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                if ($depth === 0) {
                    return $i;
                }

                $depth--;
            } elseif ($token === ';' && $depth === 0) {
                return $i;
            }
        }

        return $limit;
    }

    /**
     * A class name as written, resolved against the file's namespace and
     * `use` imports. `self` and `static` are the class being read, `parent`
     * stays unresolved.
     *
     * @param array<string, string> $imports
     */
    private static function className(string $written, string $namespace, array $imports, ?string $class): ?string
    {
        $lower = strtolower($written);

        if ($lower === 'self' || $lower === 'static') {
            return $class;
        }

        if ($lower === 'parent') {
            return null;
        }

        if (str_starts_with($written, '\\')) {
            return ltrim($written, '\\');
        }

        $first = strtolower(explode('\\', $written)[0]);

        if (isset($imports[$first])) {
            $rest = substr($written, strlen($first));

            return $imports[$first] . $rest;
        }

        return ($namespace !== '' ? $namespace . '\\' : '') . $written;
    }

    /**
     * `use A\B\C;` and `use A\B\C as D;` at the top of the file, by lowercase
     * alias. Grouped and function imports are skipped.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if (self::opensBrace($token)) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            if ($depth !== 0 || !is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $full = ltrim($name[1], '\\');
            $alias = is_array($tokens[$i + 2] ?? null) && $tokens[$i + 2][0] === T_AS && is_array($tokens[$i + 3] ?? null)
                ? $tokens[$i + 3][1]
                : substr((string) strrchr('\\' . $full, '\\'), 1);

            $imports[strtolower($alias)] = $full;
        }

        return $imports;
    }

    /**
     * The namespace in force at a token.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function namespaceAt(array $tokens, int $at): string
    {
        $namespace = '';

        for ($i = 0; $i < $at; $i++) {
            $token = $tokens[$i];

            if (is_array($token) && $token[0] === T_NAMESPACE && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                $namespace = $tokens[$i + 1][1];
            }
        }

        return $namespace;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: string, 2: int}|string>
     */
    private static function withoutCasts(array $tokens): array
    {
        $casts = [T_STRING_CAST, T_INT_CAST, T_BOOL_CAST, T_ARRAY_CAST, T_DOUBLE_CAST, T_OBJECT_CAST];

        while ($tokens !== [] && is_array($tokens[0]) && in_array($tokens[0][0], $casts, true)) {
            array_shift($tokens);
        }

        // One pair of parentheses around the whole expression.
        if (($tokens[0] ?? null) === '(' && self::match($tokens, 0) === count($tokens) - 1) {
            $tokens = array_slice($tokens, 1, -1);
        }

        return $tokens;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function isClassLike(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        $before = $tokens[$i - 1] ?? null;

        return is_array($token)
            && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
            && !self::isMember($before)
            && !(is_array($before) && $before[0] === T_NEW)
            && is_array($tokens[$i + 1] ?? null)
            && $tokens[$i + 1][0] === T_STRING;
    }

    /**
     * The `{` that opens a function body after its parameter list's `)`, or
     * null for an abstract or interface method.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function bodyOpen(array $tokens, int $close): ?int
    {
        $count = count($tokens);

        for ($i = $close + 1; $i < $count; $i++) {
            if ($tokens[$i] === '{') {
                return $i;
            }

            if ($tokens[$i] === ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function next(array $tokens, int $from, string $what): ?int
    {
        $count = count($tokens);

        for ($i = $from + 1; $i < $count; $i++) {
            if ($tokens[$i] === $what) {
                return $i;
            }

            if ($tokens[$i] === ';' && $what !== ';') {
                return null;
            }
        }

        return null;
    }

    /**
     * The index of the bracket that closes the one at `$open`.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function match(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $token = $tokens[$i];

            if (self::opens($token)) {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function opens(array|string $token): bool
    {
        return $token === '(' || $token === '[' || $token === '{'
            || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private static function isMember(array|string|null $token): bool
    {
        return is_array($token) && in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true);
    }

    /**
     * A token that opens a brace block, which `}` closes.
     *
     * @param array{0: int, 1: string, 2: int}|string $token
     */
    private static function opensBrace(array|string $token): bool
    {
        return $token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    /**
     * @param array{0: int, 1: string, 2: int}|string|null $token
     */
    private static function stringLiteral(array|string|null $token): ?string
    {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $body = substr($token[1], 1, -1);

        return $token[1][0] === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }
}
