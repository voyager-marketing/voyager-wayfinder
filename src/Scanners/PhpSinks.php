<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;
use Voyager\Wayfinder\Plugin;

/**
 * What a call in traced code means, from data/php-sinks.json: `wp_mail`
 * sends mail, Orbit's `Module::insertLead` records an Orbit lead,
 * `wp_insert_post` creates a post, `wp_safe_redirect` redirects. A call the
 * file does not list means nothing here.
 *
 * Which plugin actually sends the mail is not in this file: the code section
 * reflects `wp_mail` to the file that defines it, so a plugin that replaces
 * the pluggable function (Postmark, an SMTP plugin) is named from the site.
 *
 * The `vwf_php_sinks` filter adds a site's own sinks, or changes a bundled
 * one, without a plugin release.
 *
 * @phpstan-type Sink array{effect: string, label: string}
 */
final class PhpSinks
{
    /** The effects the file may name. */
    public const EFFECTS = ['mail', 'post', 'record', 'redirect'];

    /** @var array<string, Sink> Lowercase call => sink, sorted. */
    private array $sinks;

    /**
     * @param array<string, Sink> $sinks
     */
    private function __construct(array $sinks)
    {
        ksort($sinks, SORT_STRING);
        $this->sinks = $sinks;
    }

    /**
     * The list that ships with the plugin, passed through `vwf_php_sinks`.
     *
     * The filter gets the `call => {effect, label}` map: a lower case call
     * with no leading backslash, an effect from EFFECTS and a label. An entry
     * that breaks that shape is dropped and reported with _doing_it_wrong(),
     * so one bad entry never takes the rest of the list down.
     *
     * @throws UnexpectedValueException When data/php-sinks.json is missing or not JSON.
     * @throws InvalidArgumentException When it is JSON of the wrong shape.
     */
    public static function bundled(): self
    {
        $path = dirname(__DIR__, 2) . '/data/php-sinks.json';
        $json = is_readable($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new UnexpectedValueException('Cannot read data/php-sinks.json.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('data/php-sinks.json is not valid JSON: ' . esc_html($e->getMessage()));
        }

        $bundled = self::fromData($data);

        // Filters the sinks as `call => [effect, label]`; a callback may return anything, so it is checked below.
        $filtered = apply_filters('vwf_php_sinks', $bundled->sinks);

        if (!is_array($filtered)) {
            _doing_it_wrong('vwf_php_sinks', 'The filter must return an array of call => effect and label. The bundled list is used.', esc_html(Plugin::VERSION));

            return $bundled;
        }

        $sinks = [];

        foreach ($filtered as $call => $sink) {
            $problem = self::problem((string) $call, $sink);

            if ($problem !== null) {
                _doing_it_wrong('vwf_php_sinks', esc_html($problem . ' The entry is ignored.'), esc_html(Plugin::VERSION));

                continue;
            }

            /** @var Sink $sink */
            $sinks[(string) $call] = ['effect' => $sink['effect'], 'label' => $sink['label']];
        }

        return new self($sinks);
    }

    /**
     * Builds the map from the decoded file: `{"sinks": {"<call>": {"effect": .., "label": ..}}}`.
     *
     * @throws InvalidArgumentException On an unknown effect, an empty label or a key that is not lower case.
     */
    public static function fromData(mixed $data): self
    {
        if (!is_array($data) || !isset($data['sinks']) || !is_array($data['sinks'])) {
            throw new InvalidArgumentException('PHP sinks need a "sinks" object of call => effect and label.');
        }

        $sinks = [];

        foreach ($data['sinks'] as $call => $sink) {
            $call = (string) $call;
            $problem = self::problem($call, $sink);

            if ($problem !== null) {
                throw new InvalidArgumentException(esc_html($problem));
            }

            /** @var Sink $sink */
            $sinks[$call] = ['effect' => $sink['effect'], 'label' => $sink['label']];
        }

        return new self($sinks);
    }

    /**
     * What is wrong with one entry, or null when it is a sink.
     */
    private static function problem(string $call, mixed $sink): ?string
    {
        if ($call === '' || $call !== strtolower($call) || str_starts_with($call, '\\')) {
            return sprintf('PHP sink "%s" must be lower case, with no leading backslash.', $call);
        }

        $effect = is_array($sink) ? ($sink['effect'] ?? null) : null;
        $label = is_array($sink) ? ($sink['label'] ?? null) : null;

        if (!is_string($effect) || !in_array($effect, self::EFFECTS, true)) {
            return sprintf('PHP sink "%s" needs an effect: one of %s.', $call, implode(', ', self::EFFECTS));
        }

        if (!is_string($label) || trim($label) === '') {
            return sprintf('PHP sink "%s" needs a label.', $call);
        }

        return null;
    }

    /**
     * The sink a call is, by the name TokenReader gives it, or null.
     *
     * @return Sink|null
     */
    public function sink(string $call): ?array
    {
        return $this->sinks[strtolower(ltrim($call, '\\'))] ?? null;
    }

    /**
     * @return array<string, Sink>
     */
    public function all(): array
    {
        return $this->sinks;
    }
}
