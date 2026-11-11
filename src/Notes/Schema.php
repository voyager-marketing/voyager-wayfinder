<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Notes;

/**
 * The `{prefix}vwf_notes` table.
 *
 * Installed through dbDelta the first time `init` runs with a schema version
 * the site has not seen, so a plugin that was already active when this
 * shipped gets the table without being reactivated.
 */
final class Schema
{
    public const TABLE = 'vwf_notes';

    public const VERSION = '1';

    public const VERSION_OPTION = 'vwf_notes_db_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    public static function maybeInstall(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            self::install();
        }
    }

    public static function install(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charsetCollate = $wpdb->get_charset_collate();

        // dbDelta wants one column per line and two spaces after PRIMARY KEY.
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  item_id varchar(191) NOT NULL,
  body longtext NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'ok',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_hash_at_write char(64) NOT NULL DEFAULT '',
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY item_id (item_id)
) {$charsetCollate};");

        update_option(self::VERSION_OPTION, self::VERSION);
    }
}
