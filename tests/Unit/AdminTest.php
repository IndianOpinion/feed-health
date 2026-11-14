<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Admin\AdminPage;
use IndianOpinion\FeedHealth\Admin\Dashboard;
use IndianOpinion\FeedHealth\Admin\Widget;
use IndianOpinion\FeedHealth\Exception\InvalidValue;
use IndianOpinion\FeedHealth\Storage\Schema;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;

/**
 * Section 4.11 (dashboard), 4.12 (redaction on output), 8 (security).
 */
final class AdminTest extends TestCase
{
    private AdminPage $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = new AdminPage($this->plugin);
        $this->plugin->state->set('last_check', $this->clock->now());
    }

    /** Test 69 (cases 78, 79). */
    public function testCapabilityAndBanners(): void
    {
        $this->fetch('a');
        \WpStub::$caps['manage_options'] = false;
        $html = $this->page->html([]);
        self::assertStringContainsString('You do not have permission', $html);
        self::assertStringNotContainsString('Feed a', $html);
        \WpStub::$caps['manage_options'] = true;

        $html = $this->page->html([]);
        self::assertStringContainsString('fh-summary', $html);
        self::assertStringContainsString('Last check:', $html);
        self::assertStringContainsString('Last recorded fetch:', $html);
        self::assertStringNotContainsString('switched off', $html);
        self::assertStringNotContainsString('Checks are not running', $html);

        $this->settings(['enabled' => false]);
        self::assertStringContainsString('feed-health is switched off', $this->page->html([]));
        $this->settings(['enabled' => true]);

        $this->plugin->state->set('last_incomplete_run', ['run_id' => 'r9', 'adapter' => 'test', 'at' => $this->clock->now(), 'missed' => 2]);
        self::assertStringContainsString('An import run (r9, adapter test) started and never finished; 2 listed feed(s)', $this->page->html([]));

        $this->plugin->state->set('last_systemic', $this->clock->now());
        self::assertStringContainsString('Systemic failure in the last check', $this->page->html([]));

        add_filter('feed_health_capability', static fn (): string => 'edit_posts');
        self::assertStringContainsString('You do not have permission', $this->page->html([]));
    }

    /** Test 52 (case 61). */
    public function testBannerWhenNoCheckForThreeIntervals(): void
    {
        $this->clock->advance(3 * 3600 - 60);
        self::assertStringNotContainsString('Checks are not running', $this->page->html([]));
        $this->clock->advance(120);
        $html = $this->page->html([]);
        self::assertStringContainsString('Checks are not running', $html);
        self::assertStringContainsString('wp feed-health check', $html);
    }

    /** Test 70 (case 80). */
    public function testTableSortsFiltersSearchesPaginatesAndEscapes(): void
    {
        $this->fetch('evil', 'ok', ['feed_label' => '<script>alert(1)</script>']);
        for ($i = 0; $i < 55; $i++) {
            $this->fetch(sprintf('f%02d', $i), 'ok', ['feed_label' => sprintf('Feed %02d', $i)]);
        }
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('f07', 'http_error', ['http_status' => 500, 'feed_label' => 'Feed 07']);
        }
        $this->plugin->checker->run();
        $html = $this->page->html([]);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('56 feed(s). Page 1 of 2.', $html);
        self::assertSame(50, substr_count($this->page->table([]), '<tr><td><a href'));

        $page2 = $this->page->table(['paged' => '2']);
        self::assertSame(6, substr_count($page2, '<tr><td><a href'));

        $failing = $this->page->table(['status' => 'failing']);
        self::assertStringContainsString('Feed 07', $failing);
        self::assertSame(1, substr_count($failing, '<tr><td><a href'));

        self::assertSame(1, substr_count($this->page->table(['s' => 'feed 12']), '<tr><td><a href'));
        self::assertSame(0, substr_count($this->page->table(['adapter' => 'core']), '<tr><td><a href'));

        $dash = new Dashboard($this->plugin);
        $sorted = $dash->filter($dash->rows(), ['orderby' => 'label', 'order' => 'desc']);
        self::assertSame('Feed 54', $sorted[0]['label']);
        self::assertSame('<script>alert(1)</script>', end($sorted)['label']);
        $byStatus = $dash->filter($dash->rows(), []);
        self::assertSame('failing', $byStatus[0]['status']);
        $byFailures = $dash->filter($dash->rows(), ['orderby' => 'failures_24h', 'order' => 'desc']);
        self::assertSame('Feed 07', $byFailures[0]['label']);
    }

    /** Test 71 (case 81). */
    public function testDetailShowsFiftyAttemptsAndThresholdDerivation(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->fetchWithItems('a', 1);
            $this->clock->advance(3600);
        }
        $this->plugin->checker->run();
        $html = $this->page->html(['feed' => (string) $this->feed('a')['id']]);
        self::assertSame(50, substr_count($html, '<tr><td>2026-'));
        self::assertStringContainsString('90th percentile gap 60 min x 2, raised to the floor', $html);
        self::assertStringContainsString('Threshold 6 h', $html);
        self::assertStringContainsString('Mute', $html);
        self::assertStringContainsString('Forget feed', $html);
        self::assertStringContainsString('Per-feed thresholds', $html);
        self::assertStringContainsString('Status history', $html);
        self::assertStringContainsString('new to healthy', $html);
        self::assertStringNotContainsString('Test fetch now', $html, 'the test adapter has no test fetch');
    }

    /** Test 72 (case 82). */
    public function testCsvExportRespectsFiltersAndRedacts(): void
    {
        $this->fetch('a', 'ok', ['feed_url' => 'https://u:p@feeds.example.com/a?key=SECRET1']);
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('b', 'http_error', ['http_status' => 404, 'feed_url' => 'https://feeds.example.com/b?token=SECRET2']);
        }
        $this->fetch('c', 'ok', ['feed_label' => '=HYPERLINK("x")']);
        $this->plugin->checker->run();
        $csv = $this->page->exportCsv(['_wpnonce' => 'nonce-feed_health_export', 'status' => 'failing']);
        $lines = array_values(array_filter(explode("\n", $csv)));
        self::assertCount(2, $lines);
        self::assertStringContainsString('token=***', $lines[1]);
        self::assertStringNotContainsString('SECRET', $csv);

        $all = $this->page->exportCsv(['_wpnonce' => 'nonce-feed_health_export']);
        self::assertStringNotContainsString('SECRET', $all);
        self::assertStringNotContainsString('u:p@', $all);
        self::assertStringContainsString("'=HYPERLINK", $all);

        $this->expectException(\WPDieException::class);
        $this->page->exportCsv(['_wpnonce' => 'wrong']);
    }

    /** Test 73 (case 83). */
    public function testWidgetShowsCountsAndCanBeTurnedOff(): void
    {
        $this->fetch('a');
        $widget = new Widget($this->plugin);
        $widget->setup();
        self::assertSame('feed_health_widget', \WpStub::$registered['widget'] ?? null);
        self::assertStringContainsString('new: 1', $widget->html());
        unset(\WpStub::$registered['widget']);
        $this->settings(['dashboard_widget' => false]);
        $widget->setup();
        self::assertArrayNotHasKey('widget', \WpStub::$registered);
        $this->settings(['dashboard_widget' => true]);
        \WpStub::$caps['manage_options'] = false;
        $widget->setup();
        self::assertArrayNotHasKey('widget', \WpStub::$registered);
    }

    /** Test 74 (case 84): dashboard, email, log lines and CSV; stored URL unchanged. */
    public function testRedactionEverywhereAndStoredUrlUnchanged(): void
    {
        $url = 'https://reader:hunter2@feeds.example.com/a?token=SECRETTOKEN&cat=1';
        $this->settings(['php_error_log' => true]);
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('a', 'http_error', ['http_status' => 401, 'feed_url' => $url, 'error_message' => 'denied for ' . $url]);
        }
        $this->plugin->checker->run();
        self::assertSame($url, $this->feed('a')['url'], 'stored URL unchanged');
        $html = $this->page->html([]) . $this->page->html(['feed' => (string) $this->feed('a')['id']]) . $this->page->html(['view' => 'events']);
        $csv = $this->page->exportCsv(['_wpnonce' => 'nonce-feed_health_export']);
        $mail = implode("\n", array_column(\WpStub::$mail, 'body'));
        foreach (['dashboard' => $html, 'csv' => $csv, 'email' => $mail, 'php log' => $this->phpLog()] as $where => $text) {
            self::assertStringNotContainsString('SECRETTOKEN', $text, $where);
            self::assertStringNotContainsString('hunter2', $text, $where);
        }
        self::assertStringContainsString('token=***', $html);
        self::assertStringContainsString('token=***', $mail);
    }

    /** Test 75 (case 85). */
    public function testRemoteErrorWithHtmlIsStoredAsTextAndEscaped(): void
    {
        $this->fetch('a', 'transport_error', ['error_message' => '<b>Bad</b> gateway <img src=x onerror=alert(1)> & more']);
        $row = $this->rows(Schema::FETCHES)[0];
        self::assertSame('Bad gateway & more', $row['error_message']);
        $html = $this->page->html(['feed' => (string) $this->feed('a')['id']]);
        self::assertStringContainsString('Bad gateway &amp; more', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /** Test 77, repair part (case 87). */
    public function testTablesMissingBannerAndRepairAction(): void
    {
        $this->plugin->repository->dropAll();
        $html = $this->page->html([]);
        self::assertStringContainsString('Tables missing', $html);
        self::assertStringContainsString('Repair tables', $html);
        $message = $this->page->perform('repair', ['_wpnonce' => 'nonce-feed_health_repair'], 'POST', 1);
        self::assertSame('Tables created.', $message);
        self::assertTrue($this->plugin->repository->tablesExist());
    }

    public function testActionsNeedCapabilityNoncePostAndConfirmation(): void
    {
        $this->fetch('a');
        $id = (string) $this->feed('a')['id'];
        try {
            $this->page->perform('mute', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_mute'], 'GET', 1);
            self::fail('GET must be refused');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('form submission', $e->getMessage());
        }
        try {
            $this->page->perform('mute', ['feed' => $id, '_wpnonce' => 'bad'], 'POST', 1);
            self::fail('bad nonce must be refused');
        } catch (\WPDieException $e) {
            self::assertStringContainsString('expired', $e->getMessage());
        }
        \WpStub::$caps['manage_options'] = false;
        try {
            $this->page->perform('mute', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_mute'], 'POST', 1);
            self::fail('no capability must be refused');
        } catch (\WPDieException $e) {
            self::assertStringContainsString('permission', $e->getMessage());
        }
        \WpStub::$caps['manage_options'] = true;
        self::assertSame('Feed muted.', $this->page->perform('mute', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_mute'], 'POST', 4));
        try {
            $this->page->perform('forget', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_forget'], 'POST', 4);
            self::fail('forget without confirm must be refused');
        } catch (InvalidValue $e) {
            self::assertNotNull($this->plugin->repository->feed((int) $id));
        }
        try {
            $this->page->perform('reset', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_reset'], 'POST', 4);
            self::fail('reset without confirm must be refused');
        } catch (InvalidValue $e) {
            self::assertStringContainsString('confirmation', $e->getMessage());
        }
        self::assertSame('Feed forgotten.', $this->page->perform('forget', ['feed' => $id, '_wpnonce' => 'nonce-feed_health_forget', 'confirm' => '1'], 'POST', 4));
        self::assertStringStartsWith('Check ok', $this->page->perform('check', ['_wpnonce' => 'nonce-feed_health_check'], 'POST', 4));
        self::assertStringStartsWith('Test alert: log: written', $this->page->perform('test_alert', ['_wpnonce' => 'nonce-feed_health_test_alert'], 'POST', 4));
    }

    public function testSettingsFormAndSanitize(): void
    {
        $html = $this->page->html(['view' => 'settings']);
        self::assertStringContainsString('name="feed_health_settings[daily_email_cap]"', $html);
        self::assertStringContainsString('option_page', $html);
        $clean = \IndianOpinion\FeedHealth\Settings::sanitize(['daily_email_cap' => '-5', 'quiet_multiplier' => '99', 'recipients' => 'a@example.com, nope', 'bogus' => 1, 'check_interval' => 'weekly', 'enabled' => '0']);
        self::assertSame(0, $clean['daily_email_cap']);
        self::assertSame(20.0, $clean['quiet_multiplier']);
        self::assertSame(['a@example.com'], $clean['recipients']);
        self::assertArrayNotHasKey('bogus', $clean);
        self::assertSame('hourly', $clean['check_interval']);
        self::assertFalse($clean['enabled']);
    }

    public function testSparklineIsInlineSvg(): void
    {
        $svg = Dashboard::sparkline([0, 1, 2, 3, 0, 0, 5, 1, 1, 1, 1, 1, 1, 1]);
        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('<polyline', $svg);
        self::assertStringNotContainsString('http', str_replace('http://www.w3.org', '', $svg));
    }
}
