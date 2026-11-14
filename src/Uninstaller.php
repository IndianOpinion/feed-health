<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth;

use IndianOpinion\FeedHealth\Notify\Notifier;

/**
 * Removes everything the plugin created.
 */
final class Uninstaller
{
    public static function run(?Plugin $plugin = null): void
    {
        $plugin ??= Plugin::instance();
        if (is_multisite()) {
            foreach (get_sites(['fields' => 'ids', 'number' => 0]) as $siteId) {
                switch_to_blog((int) $siteId);
                self::site($plugin);
                restore_current_blog();
            }
            delete_site_option(Notifier::NETWORK_OPTION);

            return;
        }
        self::site($plugin);
    }

    private static function site(Plugin $plugin): void
    {
        try {
            $plugin->repository->dropAll();
        } catch (\Throwable $e) {
            error_log(Logger::PREFIX . 'Uninstall could not drop tables: ' . $e->getMessage());
        }
        delete_option(Settings::OPTION);
        delete_option(State::OPTION);
        delete_option(Checker::LOCK_OPTION);
        wp_clear_scheduled_hook(Plugin::CHECK_HOOK);
        wp_clear_scheduled_hook(Plugin::AFTER_RUN_HOOK);
        wp_clear_scheduled_hook(Plugin::DAILY_HOOK);
    }
}
