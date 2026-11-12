<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Edit;

/**
 * What find hands an agent: the compact answer by default, the whole
 * result on request.
 *
 * EditLocator::find() returns everything it found: every home of a match,
 * the whole chain the page is built from, and on the post content entry
 * the page's class, block name and locked block inventories. On a real
 * site that runs to tens of thousands of characters for one call, so the
 * REST route, the ability and the CLI shape it first.
 *
 * The compact answer (`detail` compact, the default) keeps the first
 * LIMIT hits and the first LIMIT chain entries, in find's own order, and
 * counts the rest in `hits_more` and `chain_more`. It leaves out the
 * inventories (INVENTORIES) and a synced pattern's `used_in` list, whose
 * first names and count its instruction already gives. Each entry it keeps
 * is unchanged: id, edit path, instruction, lock, client_can and its why,
 * anchor. `verify` is unchanged. `detail` full returns find's result as it
 * is. Both carry `detail`, so an answer says which one it is.
 *
 * The inspector and the Wayfinder page call EditLocator directly and keep
 * the whole result.
 *
 * @phpstan-import-type Result from EditLocator
 * @phpstan-import-type Hit from EditLocator
 * @phpstan-import-type Verify from EditLocator
 * @phpstan-type Payload array{detail: string, url: string, route: string|null, template: string|null, text: string|null, class: string|null, matched: bool|null, ambiguous: bool, message: string|null, chain: list<array<string, mixed>>, hits: list<Hit>, verify: Verify, coverage?: 'partial', reason?: 'builder_document', chain_more?: int, hits_more?: int}
 */
final class FindPayload
{
    public const COMPACT = 'compact';

    public const FULL = 'full';

    public const DETAILS = [self::COMPACT, self::FULL];

    /** Hits and chain entries the compact answer keeps. */
    public const LIMIT = 3;

    /** Page-wide lists on a chain entry that the compact answer leaves out. */
    public const INVENTORIES = ['classes', 'block_names', 'hidden_classes', 'classes_more', 'locked_blocks', 'used_in'];

    /** Replaces EditLocator::WHOLE_PAGE in a message when the compact answer cut the chain. */
    public const FIRST_ENTRIES = 'The chain lists the first three things the page is built from; ask with detail full for the rest.';

    /**
     * The detail a request asked for: compact when it gave none (or an
     * empty value, as a form or the bench sends), else the value when it is
     * one of DETAILS, else null.
     */
    public static function detail(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return self::COMPACT;
        }

        return is_string($value) && in_array($value, self::DETAILS, true) ? $value : null;
    }

    /**
     * Find's result shaped for `$detail`.
     *
     * @param Result $result
     * @return Payload
     */
    public static function shape(array $result, string $detail = self::COMPACT): array
    {
        if ($detail === self::FULL) {
            return ['detail' => self::FULL] + $result;
        }

        $chain = $result['chain'];
        $hits = $result['hits'];
        $payload = ['detail' => self::COMPACT] + $result;

        $payload['chain'] = array_map(
            static fn (array $entry): array => array_diff_key($entry, array_flip(self::INVENTORIES)),
            array_slice($chain, 0, self::LIMIT)
        );
        $payload['hits'] = array_slice($hits, 0, self::LIMIT);

        if (count($chain) > self::LIMIT) {
            $payload['chain_more'] = count($chain) - self::LIMIT;

            if (is_string($result['message']) && str_ends_with($result['message'], EditLocator::WHOLE_PAGE)) {
                $payload['message'] = substr($result['message'], 0, -strlen(EditLocator::WHOLE_PAGE)) . self::FIRST_ENTRIES;
            }
        }

        if (count($hits) > self::LIMIT) {
            $payload['hits_more'] = count($hits) - self::LIMIT;
        }

        return $payload;
    }
}
