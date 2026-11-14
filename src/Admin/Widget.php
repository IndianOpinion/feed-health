<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Admin;

use IndianOpinion\FeedHealth\Plugin;

/**
 * Small wp-admin home widget: count per status and a link.
 */
final class Widget
{
    public function __construct(private Plugin $plugin)
    {
    }

    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'setup'], 10, 0);
    }

    public function setup(): void
    {
        if (!$this->plugin->settings->bool('dashboard_widget') || !current_user_can($this->plugin->capability())) {
            return;
        }
        wp_add_dashboard_widget('feed_health_widget', 'Feed health', [$this, 'render']);
    }

    public function render(): void
    {
        echo $this->html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
    }

    public function html(): string
    {
        try {
            $feeds = $this->plugin->repository->feeds();
        } catch (\Throwable $e) {
            return '<p>' . esc_html('Feed health tables are missing. Open Tools > Feed health to repair them.') . '</p>';
        }
        $counts = [];
        foreach ($feeds as $feed) {
            $status = (string) $feed['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);
        $items = '';
        foreach ($counts as $status => $n) {
            $items .= '<li>' . esc_html($status) . ': ' . (int) $n . '</li>';
        }
        $link = add_query_arg(['page' => AdminPage::SLUG], admin_url('tools.php'));

        return '<ul>' . ($items !== '' ? $items : '<li>' . esc_html('No feeds recorded yet.') . '</li>') . '</ul>'
            . '<p><a href="' . esc_url($link) . '">' . esc_html('Open feed health') . '</a></p>';
    }
}
