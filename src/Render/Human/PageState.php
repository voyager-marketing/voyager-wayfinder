<?php

declare(strict_types=1);

namespace Voyager\Wayfinder\Render\Human;

/**
 * What the Wayfinder page needs besides the manifest and the notes: the site,
 * who is looking, and the nonces its forms carry. Admin\ManualPage builds it
 * from WordPress. The unit suite builds it by hand.
 */
final class PageState
{
    /**
     * @param string                                  $adminUrl      wp-admin URL ending in a slash. Edit paths are relative to it.
     * @param string                                  $lastScan      When the stored manifest was last refreshed, ready to print. Empty before the first refresh.
     * @param string                                  $brief         The brief tier, for the copy button.
     * @param bool                                    $canWrite      The viewer may write notes, rescan and change settings (`manage_options`).
     * @param string                                  $rescanNonce   Nonce for the `vwf_rescan` action.
     * @param string                                  $settingsNonce Nonce for the `vwf_settings-options` action options.php checks.
     * @param string                                  $referer       Path options.php sends the viewer back to.
     * @param list<array{type: string, text: string}> $notices       Plain text, with a core notice type: success, info, warning or error.
     * @param string                                  $exportNonce   Nonce for the `vwf_export` action.
     */
    public function __construct(
        public readonly string $siteName,
        public readonly string $adminUrl,
        public readonly string $lastScan = '',
        public readonly string $brief = '',
        public readonly bool $canWrite = false,
        public readonly bool $mcpEnabled = false,
        public readonly string $rescanNonce = '',
        public readonly string $settingsNonce = '',
        public readonly string $referer = '',
        public readonly array $notices = [],
        public readonly string $exportNonce = ''
    ) {
    }
}
