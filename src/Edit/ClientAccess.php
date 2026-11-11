<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Edit;

use Closure;
use Voyager\Wayfinder\Admin\EditAccess;
use Voyager\Wayfinder\Blocks\Locks;

/**
 * Whether the client can make a change themselves: `yes`, `partial` (the
 * text only, under a content-only lock) or `no`, with the reason in plain
 * words.
 *
 * The client is a role, `vwf_client_role` (default `editor`), not a user,
 * so the answer is the same for whoever asks. The capability a screen needs
 * comes from EditAccess, the same table the Wayfinder page links by. A
 * surface with no edit screen is changed in code, which no client role does.
 *
 * The reason keeps two questions apart, in this order: whether the role can
 * open the edit screen at all, then what the lock allows once it is open. A
 * role that cannot open the screen is never told about the lock.
 *
 * @phpstan-import-type Lock from Locks
 * @phpstan-type Verdict array{client_can: 'yes'|'partial'|'no', client_can_why: string}
 * @phpstan-type Opens array{can_open: bool, needs: string|null, role: string}
 */
final class ClientAccess
{
    public const OPTION = 'vwf_client_role';

    public const DEFAULT_ROLE = 'editor';

    /** @var Closure(string): bool */
    private Closure $roleHas;

    /** @var Closure(string): string */
    private Closure $capabilityFor;

    /**
     * @param (callable(string): bool)|null   $roleHas       Whether the role has a capability. Defaults to get_role().
     * @param (callable(string): string)|null $capabilityFor The capability an edit path needs. Defaults to EditAccess::roleCapability().
     */
    public function __construct(
        private readonly string $role = self::DEFAULT_ROLE,
        ?callable $roleHas = null,
        ?callable $capabilityFor = null,
    ) {
        $this->roleHas = $roleHas !== null
            ? Closure::fromCallable($roleHas)
            : static function (string $capability) use ($role): bool {
                $object = function_exists('get_role') ? get_role($role) : null;

                return $object !== null && $object->has_cap($capability);
            };
        $this->capabilityFor = $capabilityFor !== null
            ? Closure::fromCallable($capabilityFor)
            : static fn (string $edit): string => EditAccess::roleCapability($edit);
    }

    /**
     * The client role configured on the site.
     */
    public static function forSite(): self
    {
        return new self(self::configuredRole());
    }

    public static function configuredRole(): string
    {
        $role = function_exists('get_option') ? get_option(self::OPTION, self::DEFAULT_ROLE) : self::DEFAULT_ROLE;

        return is_string($role) && $role !== '' ? $role : self::DEFAULT_ROLE;
    }

    public function role(): string
    {
        return $this->role;
    }

    /**
     * Whether the role can open an edit screen, and the capability it needs.
     * A null path has no screen: `needs` is null and nobody opens it.
     *
     * @return Opens
     */
    public function opens(?string $edit): array
    {
        if ($edit === null) {
            return ['can_open' => false, 'needs' => null, 'role' => $this->role];
        }

        $capability = ($this->capabilityFor)($edit);

        return ['can_open' => ($this->roleHas)($capability), 'needs' => $capability, 'role' => $this->role];
    }

    /**
     * @param Lock $lock
     * @return Verdict
     */
    public function verdict(?string $edit, array $lock): array
    {
        $opens = $this->opens($edit);

        if ($opens['needs'] === null) {
            return self::answer('no', 'no edit screen in wp-admin; it is changed in code');
        }

        if (!$opens['can_open']) {
            return self::answer('no', sprintf('the %s role cannot open it: it needs %s', $this->role, $opens['needs']));
        }

        $canOpen = sprintf('the %s role can open it', $this->role);

        if ($lock['effective'] === 'content_only') {
            return self::answer(
                $lock['text_editable'] ? 'partial' : 'no',
                sprintf('%s; content only (%s): %s', $canOpen, $lock['src'], Locks::effect('content_only', $lock['text_editable']))
            );
        }

        if ($lock['effective'] !== 'none') {
            return self::answer('yes', sprintf('%s; %s (%s): %s', $canOpen, $lock['effective'], $lock['src'], Locks::effect($lock['effective'], $lock['text_editable'])));
        }

        return self::answer('yes', $canOpen . '; not locked');
    }

    /**
     * @param 'yes'|'partial'|'no' $clientCan
     * @return Verdict
     */
    private static function answer(string $clientCan, string $why): array
    {
        return ['client_can' => $clientCan, 'client_can_why' => $why];
    }
}
