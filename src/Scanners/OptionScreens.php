<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Scanners;

/**
 * Where an option a registered pattern reads is edited, named only where
 * Wayfinder can derive it, never guessed from the option's name:
 *
 * - an option on OptionAllowlist, at the core screen the allowlist records
 *   (`blogdescription` at `options-general.php`);
 * - a Site Data field, at the screen of whichever plugin owns the
 *   `voyager/site-data` source, with the field's label. voyager-blocks owns
 *   it when it is active: `VoyagerBlocks\SiteDataBindings\get_settings_fields()`
 *   returns its fields (option name => `label`, ...) and they are saved on the
 *   Site Data tab of Settings > Voyager Blocks (voyager-blocks
 *   inc/site-data-bindings.php and src/admin/settings-page.php at e76382d;
 *   `voyager_site_data` there is the Settings API group, not the page slug).
 *   Otherwise the voyager-open-blocks pack owns it: its
 *   `VoyagerOpenBlocks\SiteData\fields()` lists binding key => `label` and, for
 *   the keys stored in an option, `option`, and they are saved at Settings >
 *   Site Data, page `voyager-site-data` (voyager-open-blocks inc/site-data.php
 *   at e569d3b). The pack stands aside when voyager-blocks registered the
 *   source first, so voyager-blocks is asked first.
 *
 * Any other option is named without a screen. Only the option's name and
 * label leave here, never its value.
 *
 * @phpstan-type Screen array{label: string|null, edit: string}
 * @phpstan-type ReadScreen array{read: string, label: string|null, edit: string}
 * @phpstan-type Owner array{screen: string, keyed_by: 'option'|'binding'}
 */
final class OptionScreens
{
    /** The block bindings source both plugins register for Site Data. */
    public const SITE_DATA_SOURCE = 'voyager/site-data';

    /**
     * Sources that read a post's own meta, edited on the post: a binding's
     * key is the meta key (`voyager/post-meta-text` also takes a dot path
     * into it, `stats.0.value`, whose first part is the key).
     */
    public const POST_META_SOURCES = ['core/post-meta', 'voyager/post-meta-text'];

    /** voyager-blocks's list of its Site Data fields. */
    public const BLOCKS_FIELDS = 'VoyagerBlocks\\SiteDataBindings\\get_settings_fields';

    /** The Site Data tab of Settings > Voyager Blocks. */
    public const BLOCKS_SCREEN = 'options-general.php?page=voyager-blocks&tab=site-data';

    /** The voyager-open-blocks pack's list of its Site Data fields. */
    public const PACK_FIELDS = 'VoyagerOpenBlocks\\SiteData\\fields';

    /** Settings > Site Data of the voyager-open-blocks pack. */
    public const PACK_SCREEN = 'options-general.php?page=voyager-site-data';

    /**
     * The plugins that can own the source, first owner first: each one's field
     * function and what it is keyed by, `option` (voyager-blocks) or the
     * binding key with an `option` entry on the fields stored in an option (the
     * pack, whose derived fields such as `company` have none).
     */
    public const OWNERS = [
        self::BLOCKS_FIELDS => ['screen' => self::BLOCKS_SCREEN, 'keyed_by' => 'option'],
        self::PACK_FIELDS   => ['screen' => self::PACK_SCREEN, 'keyed_by' => 'binding'],
    ];

    /**
     * @param array<string, string|null> $siteData       The Site Data fields, option => English label; empty when no plugin owns them.
     * @param string|null                $siteDataScreen Where those fields are edited, see siteDataScreen().
     * @return Screen|null
     */
    public static function screen(string $option, array $siteData, ?string $siteDataScreen = null): ?array
    {
        if (isset(OptionAllowlist::OPTIONS[$option])) {
            [$label, $edit] = OptionAllowlist::OPTIONS[$option];

            return ['label' => $label, 'edit' => $edit];
        }

        if ($siteDataScreen !== null && array_key_exists($option, $siteData)) {
            return ['label' => $siteData[$option], 'edit' => $siteDataScreen];
        }

        return null;
    }

    /**
     * The screens of the `option:<name>` ids among `$reads`, in their order,
     * for the options screen() can name. Other reads are left out.
     *
     * @param list<string>               $reads
     * @param array<string, string|null> $siteData       See screen().
     * @param string|null                $siteDataScreen See screen().
     * @return list<ReadScreen>
     */
    public static function forReads(array $reads, array $siteData, ?string $siteDataScreen = null): array
    {
        $screens = [];

        foreach ($reads as $read) {
            $found = str_starts_with($read, 'option:') ? self::screen(substr($read, 7), $siteData, $siteDataScreen) : null;

            if ($found !== null) {
                $screens[] = ['read' => $read] + $found;
            }
        }

        return $screens;
    }

    /**
     * The Site Data fields of the plugin that owns the source, with their
     * labels in English, or empty when none does. The owner is found now, not
     * when the class loads, so a plugin loaded later counts.
     *
     * @param array<string, Owner>|null $owners Owners to ask instead of the two plugins (tests).
     * @return array<string, string|null>
     */
    public static function siteData(?array $owners = null): array
    {
        $found = self::owner($owners ?? self::OWNERS);

        if ($found === null) {
            return [];
        }

        [$read, $owner] = $found;

        $fields = EnglishLabels::whileSwitched(
            static fn (): bool => function_exists('switch_to_locale') && switch_to_locale('en_US'),
            static fn (): mixed => restore_previous_locale(),
            static fn (): mixed => $read()
        );

        $siteData = [];

        foreach (is_array($fields) ? $fields : [] as $key => $field) {
            $option = $owner['keyed_by'] === 'option' ? (string) $key : (is_array($field) ? ($field['option'] ?? null) : null);

            if (!is_string($option) || $option === '') {
                continue;
            }

            $label = is_array($field) ? ($field['label'] ?? null) : null;
            $siteData[$option] = is_string($label) && $label !== '' ? $label : null;
        }

        ksort($siteData, SORT_STRING);

        return $siteData;
    }

    /**
     * Where the owner of the source saves the fields siteData() lists, or
     * null when no plugin owns them.
     *
     * @param array<string, Owner>|null $owners See siteData().
     */
    public static function siteDataScreen(?array $owners = null): ?string
    {
        $found = self::owner($owners ?? self::OWNERS);

        return $found !== null ? $found[1]['screen'] : null;
    }

    /**
     * The first owner whose field function can be called.
     *
     * @param array<string, Owner> $owners
     * @return array{0: callable(): mixed, 1: Owner}|null
     */
    private static function owner(array $owners): ?array
    {
        foreach ($owners as $function => $owner) {
            if (is_callable($function)) {
                return [$function, $owner];
            }
        }

        return null;
    }
}
