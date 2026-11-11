<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

/**
 * The role a plugin plays on a site (forms, seo, fields, ...), by plugin
 * slug, from data/plugin-roles.json. A plugin the file does not name is
 * `other`.
 */
final class PluginRoles
{
    public const OTHER = 'other';

    /** The roles data/plugin-roles.json may assign. `other` is implied. */
    public const ROLES = [
        'ai',
        'analytics',
        'backup',
        'builder',
        'cache',
        'ecommerce',
        'email',
        'fields',
        'forms',
        'hosting',
        'multilingual',
        'security',
        'seo',
    ];

    /** @var array<string, string> Plugin slug => role, sorted by slug. */
    private array $roles;

    /**
     * @param array<string, string> $roles
     */
    private function __construct(array $roles)
    {
        ksort($roles, SORT_STRING);
        $this->roles = $roles;
    }

    /**
     * The list that ships with the plugin.
     *
     * @throws UnexpectedValueException When data/plugin-roles.json is missing or not JSON.
     * @throws InvalidArgumentException When it is JSON of the wrong shape.
     */
    public static function bundled(): self
    {
        $path = dirname(__DIR__, 2) . '/data/plugin-roles.json';
        $json = is_readable($path) ? file_get_contents($path) : false;

        if ($json === false) {
            throw new UnexpectedValueException('Cannot read data/plugin-roles.json.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('data/plugin-roles.json is not valid JSON: ' . esc_html($e->getMessage()));
        }

        return self::fromData($data);
    }

    /**
     * Builds the map from the decoded file: `{"roles": {"<role>": ["<slug>", ...]}}`.
     *
     * @throws InvalidArgumentException On an unknown role, a bad slug, or a slug listed twice.
     */
    public static function fromData(mixed $data): self
    {
        if (!is_array($data) || !isset($data['roles']) || !is_array($data['roles'])) {
            throw new InvalidArgumentException('Plugin roles need a "roles" object of role => plugin slugs.');
        }

        $roles = [];

        foreach ($data['roles'] as $role => $slugs) {
            if (!in_array($role, self::ROLES, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown plugin role "%s". Use one of: %s.',
                    esc_html((string) $role),
                    esc_html(implode(', ', self::ROLES))
                ));
            }

            if (!is_array($slugs)) {
                throw new InvalidArgumentException(sprintf('Plugin role "%s" must list plugin slugs.', esc_html((string) $role)));
            }

            foreach ($slugs as $slug) {
                if (!is_string($slug) || preg_match('/^[^\s|,]+$/', $slug) !== 1) {
                    throw new InvalidArgumentException(sprintf('Plugin role "%s" lists an invalid slug.', esc_html((string) $role)));
                }

                if (isset($roles[$slug])) {
                    throw new InvalidArgumentException(sprintf(
                        'Plugin "%s" is listed as both %s and %s.',
                        esc_html($slug),
                        esc_html($roles[$slug]),
                        esc_html((string) $role)
                    ));
                }

                $roles[$slug] = $role;
            }
        }

        return new self($roles);
    }

    public function role(string $slug): string
    {
        return $this->roles[$slug] ?? self::OTHER;
    }

    /**
     * @return array<string, string> Plugin slug => role, sorted by slug.
     */
    public function all(): array
    {
        return $this->roles;
    }
}
