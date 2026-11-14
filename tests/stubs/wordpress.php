<?php

/**
 * A small stand-in for the WordPress functions the plugin calls, so the
 * tests run without a WordPress install. State lives in WpStub and is reset
 * before every test.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WpStub
{
    /** @var array<string, array<int, list<array{0: callable, 1: int}>>> */
    public static array $hooks = [];
    /** @var array<int, array<string, mixed>> */
    public static array $options = [];
    /** @var array<int, array<string, bool>> */
    public static array $autoload = [];
    /** @var array<string, mixed> */
    public static array $siteOptions = [];
    public static int $blogId = 1;
    public static string $prefix = 'wp_';
    public static bool $multisite = false;
    /** @var list<int> */
    public static array $sites = [1];
    /** @var list<int> */
    private static array $blogStack = [];
    /** @var array<int, array<string, array{0: int, 1: string|false}>> */
    public static array $cron = [];
    /** @var list<array{to: mixed, subject: string, body: string, headers: mixed}> */
    public static array $mail = [];
    public static bool $mailResult = true;
    /** @var array<string, bool> */
    public static array $caps = ['manage_options' => true, 'manage_network_options' => true];
    public static int $userId = 1;
    public static bool $nonceValid = true;
    /** @var (callable(string): mixed)|null */
    public static $fetchFeed = null;
    public static int $fetchFeedCalls = 0;
    /** @var array<string, int> */
    public static array $actionsFired = [];
    public static ?string $redirect = null;
    public static bool $objectCache = false;
    /** @var list<string> */
    public static array $httpCalls = [];
    /** @var list<array{0: string, 1: string}> */
    public static array $privacy = [];
    /** @var array<string, mixed> */
    public static array $registered = [];

    public static function reset(): void
    {
        self::$hooks = [];
        self::$options = [];
        self::$autoload = [];
        self::$siteOptions = [];
        self::$blogId = 1;
        self::$prefix = 'wp_';
        self::$multisite = false;
        self::$sites = [1];
        self::$blogStack = [];
        self::$cron = [];
        self::$mail = [];
        self::$mailResult = true;
        self::$caps = ['manage_options' => true, 'manage_network_options' => true];
        self::$userId = 1;
        self::$nonceValid = true;
        self::$fetchFeed = null;
        self::$fetchFeedCalls = 0;
        self::$actionsFired = [];
        self::$redirect = null;
        self::$objectCache = false;
        self::$httpCalls = [];
        self::$privacy = [];
        self::$registered = [];
        self::$options[1] = ['admin_email' => 'admin@example.com', 'blogname' => 'Example Site'];
    }

    public static function switchTo(int $id): void
    {
        self::$blogStack[] = self::$blogId;
        self::$blogId = $id;
        self::$prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
        self::$options[$id] ??= ['admin_email' => 'admin@example.com', 'blogname' => 'Site ' . $id];
    }

    public static function restore(): void
    {
        $id = array_pop(self::$blogStack) ?? 1;
        self::$blogId = $id;
        self::$prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    }
}

final class WP_Error
{
    /** @param mixed $data */
    public function __construct(private string $code = '', private string $message = '', private $data = null)
    {
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    /** @return mixed */
    public function get_error_data()
    {
        return $this->data;
    }
}

final class WPDieException extends RuntimeException
{
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$hook][$priority][] = [$callback, $args];

    return true;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_action($hook, $callback, $priority, $args);
}

function remove_all_filters(string $hook): void
{
    unset(WpStub::$hooks[$hook]);
}

function has_action(string $hook): bool
{
    return !empty(WpStub::$hooks[$hook]);
}

/** @param mixed ...$args */
function do_action(string $hook, ...$args): void
{
    WpStub::$actionsFired[$hook] = (WpStub::$actionsFired[$hook] ?? 0) + 1;
    $list = WpStub::$hooks[$hook] ?? [];
    ksort($list);
    foreach ($list as $callbacks) {
        foreach ($callbacks as [$callback, $count]) {
            $callback(...array_slice($args, 0, $count));
        }
    }
}

/** @param array<mixed> $args */
function do_action_ref_array(string $hook, array $args): void
{
    do_action($hook, ...$args);
}

function did_action(string $hook): int
{
    return WpStub::$actionsFired[$hook] ?? 0;
}

/**
 * @param mixed $value
 * @param mixed ...$args
 * @return mixed
 */
function apply_filters(string $hook, $value, ...$args)
{
    $list = WpStub::$hooks[$hook] ?? [];
    ksort($list);
    foreach ($list as $callbacks) {
        foreach ($callbacks as [$callback, $count]) {
            $value = $callback(...array_slice([$value, ...$args], 0, max(1, $count)));
        }
    }

    return $value;
}

/** @param mixed $default @return mixed */
function get_option(string $name, $default = false)
{
    return array_key_exists($name, WpStub::$options[WpStub::$blogId] ?? []) ? WpStub::$options[WpStub::$blogId][$name] : $default;
}

/** @param mixed $value */
function update_option(string $name, $value, $autoload = null): bool
{
    WpStub::$options[WpStub::$blogId][$name] = $value;
    if ($autoload !== null) {
        WpStub::$autoload[WpStub::$blogId][$name] = (bool) $autoload;
    }

    return true;
}

/** @param mixed $value */
function add_option(string $name, $value = '', string $deprecated = '', $autoload = true): bool
{
    if (array_key_exists($name, WpStub::$options[WpStub::$blogId] ?? [])) {
        return false;
    }
    WpStub::$options[WpStub::$blogId][$name] = $value;
    WpStub::$autoload[WpStub::$blogId][$name] = (bool) $autoload;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[WpStub::$blogId][$name]);

    return true;
}

/** @param mixed $default @return mixed */
function get_site_option(string $name, $default = false)
{
    return WpStub::$siteOptions[$name] ?? $default;
}

/** @param mixed $value */
function update_site_option(string $name, $value): bool
{
    WpStub::$siteOptions[$name] = $value;

    return true;
}

function delete_site_option(string $name): bool
{
    unset(WpStub::$siteOptions[$name]);

    return true;
}

function wp_using_ext_object_cache(): bool
{
    return WpStub::$objectCache;
}

/** @return string|false */
function is_email(string $email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
}

function sanitize_text_field(string $text): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
}

/** @param mixed $value @return mixed */
function wp_unslash($value)
{
    return is_string($value) ? stripslashes($value) : $value;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function wp_kses_post(string $html): string
{
    return $html;
}

/** @param mixed $data @return string|false */
function wp_json_encode($data, int $flags = 0)
{
    return json_encode($data, $flags);
}

/** @param mixed $selected @param mixed $current */
function selected($selected, $current = true, bool $echo = true): string
{
    return (string) $selected === (string) $current ? ' selected="selected"' : '';
}

/** @param mixed $checked @param mixed $current */
function checked($checked, $current = true, bool $echo = true): string
{
    return $checked === $current ? ' checked="checked"' : '';
}

/**
 * @param array<string, mixed>|string $args
 */
function add_query_arg($args, string $url = ''): string
{
    $sep = str_contains($url, '?') ? '&' : '?';

    return $url . $sep . http_build_query(is_array($args) ? $args : []);
}

function admin_url(string $path = ''): string
{
    return 'https://example.com/wp-admin/' . ltrim($path, '/');
}

function home_url(string $path = ''): string
{
    return 'https://example.com/' . ltrim($path, '/');
}

function get_bloginfo(string $show = ''): string
{
    return $show === 'name' ? (string) get_option('blogname', '') : '';
}

function wp_date(string $format, ?int $timestamp = null): string
{
    return gmdate($format, $timestamp ?? time());
}

function current_user_can(string $cap): bool
{
    return WpStub::$caps[$cap] ?? false;
}

function get_current_user_id(): int
{
    return WpStub::$userId;
}

/** @return int|false */
function wp_verify_nonce(string $nonce, string $action = '')
{
    return WpStub::$nonceValid && $nonce === 'nonce-' . $action ? 1 : false;
}

function wp_create_nonce(string $action = ''): string
{
    return 'nonce-' . $action;
}

function wp_nonce_field(string $action = '', string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="nonce-' . $action . '">';
}

function wp_nonce_url(string $url, string $action = ''): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . '_wpnonce=nonce-' . $action;
}

/** @param mixed $args @return never */
function wp_die(string $message = '', string $title = '', $args = []): void
{
    throw new WPDieException($message);
}

/**
 * @param string|list<string> $to
 * @param string|list<string> $headers
 */
function wp_mail($to, string $subject, string $message, $headers = '', array $attachments = []): bool
{
    WpStub::$mail[] = ['to' => $to, 'subject' => $subject, 'body' => $message, 'headers' => $headers];

    return WpStub::$mailResult;
}

/** @return int|false */
function wp_next_scheduled(string $hook)
{
    return isset(WpStub::$cron[WpStub::$blogId][$hook]) ? WpStub::$cron[WpStub::$blogId][$hook][0] : false;
}

function wp_schedule_event(int $time, string $recurrence, string $hook): bool
{
    WpStub::$cron[WpStub::$blogId][$hook] = [$time, $recurrence];

    return true;
}

function wp_schedule_single_event(int $time, string $hook): bool
{
    if (isset(WpStub::$cron[WpStub::$blogId][$hook])) {
        return false;
    }
    WpStub::$cron[WpStub::$blogId][$hook] = [$time, false];

    return true;
}

/** @return string|false */
function wp_get_schedule(string $hook)
{
    return WpStub::$cron[WpStub::$blogId][$hook][1] ?? false;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $had = isset(WpStub::$cron[WpStub::$blogId][$hook]);
    unset(WpStub::$cron[WpStub::$blogId][$hook]);

    return $had ? 1 : 0;
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

/** @param array<string, mixed> $args @return list<int> */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

function switch_to_blog(int $id): bool
{
    WpStub::switchTo($id);

    return true;
}

function restore_current_blog(): bool
{
    WpStub::restore();

    return true;
}

function register_activation_hook(string $file, callable $callback): void
{
    WpStub::$registered['activation'] = $callback;
}

function register_deactivation_hook(string $file, callable $callback): void
{
    WpStub::$registered['deactivation'] = $callback;
}

/** @return string|false */
function add_management_page(string $page, string $menu, string $cap, string $slug, callable $callback)
{
    WpStub::$registered['page'] = [$cap, $slug];

    return 'tools_page_' . $slug;
}

/** @param array<string, mixed> $args */
function register_setting(string $group, string $name, array $args = []): void
{
    WpStub::$registered['setting'] = [$group, $name];
}

function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . $group . '">';
}

function get_submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true): string
{
    return '<button type="submit" class="button button-' . $type . '">' . $text . '</button>';
}

function wp_add_dashboard_widget(string $id, string $name, callable $callback): void
{
    WpStub::$registered['widget'] = $id;
}

function wp_add_privacy_policy_content(string $name, string $text): void
{
    WpStub::$privacy[] = [$name, $text];
}

function nocache_headers(): void
{
}

function wp_safe_redirect(string $location): bool
{
    WpStub::$redirect = $location;

    return true;
}

/**
 * Calls the scripted fetch_feed() of the current test.
 *
 * @param string|string[] $url
 * @return mixed
 */
function fetch_feed($url)
{
    WpStub::$fetchFeedCalls++;
    if (WpStub::$fetchFeed === null) {
        return new WP_Error('no-feed', 'No scripted feed.');
    }

    return (WpStub::$fetchFeed)(is_array($url) ? (string) reset($url) : $url);
}

/**
 * Simulated HTTP API: tests that reach it fail the "no HTTP" checks.
 *
 * @param array<string, mixed> $args
 * @return array<string, mixed>
 */
function wp_safe_remote_get(string $url, array $args = []): array
{
    WpStub::$httpCalls[] = $url;

    return ['response' => ['code' => 200], 'body' => ''];
}
