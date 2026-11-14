<?php

declare(strict_types=1);

namespace IndianOpinion\FeedHealth\Tests\Unit;

use IndianOpinion\FeedHealth\Logger;
use IndianOpinion\FeedHealth\Notify\Notifier;
use IndianOpinion\FeedHealth\Tests\Support\TestCase;
use IndianOpinion\FeedHealth\Value\Transition;

/**
 * Section 4.10: notifications.
 */
final class NotifyTest extends TestCase
{
    /**
     * Hourly fetches with new items for every key, then one check.
     *
     * @param list<string> $keys
     */
    private function steady(array $keys, int $hours = 48): void
    {
        for ($h = 0; $h < $hours; $h++) {
            foreach ($keys as $key) {
                $this->fetchWithItems($key, 1);
            }
            $this->clock->advance(3600);
        }
        $this->plugin->checker->run();
        \WpStub::$mail = [];
    }

    private function breakFeed(string $key, int $status = 403): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fetch($key, 'http_error', ['http_status' => $status]);
        }
    }

    private function check(): void
    {
        $this->plugin->checker->run();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transitionEvents(string $key): array
    {
        $id = (int) $this->feed($key)['id'];

        return array_values(array_filter($this->events('transition'), static fn (array $e): bool => (int) $e['feed_id'] === $id));
    }

    /** Test 53 (case 62). */
    public function testFeedFailingOverFiveChecksSendsOneAlert(): void
    {
        $this->steady(['a']);
        for ($i = 0; $i < 5; $i++) {
            $this->breakFeed('a');
            $this->check();
            $this->clock->advance(3600);
        }
        self::assertCount(1, \WpStub::$mail);
        self::assertStringContainsString('1 failing', \WpStub::$mail[0]['subject']);
    }

    /** Test 54 (case 63). */
    public function testDefaultAlertSetAndRedundantSwitch(): void
    {
        $this->steady(['f', 'u', 'q', 'r']);
        $this->breakFeed('f');
        $this->check();
        self::assertStringContainsString('1 failing', \WpStub::$mail[0]['subject']);

        // Feed u stops being fetched; q keeps being fetched with nothing new.
        for ($h = 0; $h < 8; $h++) {
            $this->fetch('q', 'not_modified', ['http_status' => 304]);
            $this->fetchWithItems('r', 1);
            $this->clock->advance(3600);
        }
        \WpStub::$mail = [];
        $this->check();
        self::assertSame('unchecked', $this->feed('u')['status']);
        self::assertSame('quiet', $this->feed('q')['status']);
        self::assertCount(1, \WpStub::$mail);
        self::assertStringContainsString('1 unchecked', \WpStub::$mail[0]['subject']);
        self::assertStringContainsString('1 quiet', \WpStub::$mail[0]['subject']);

        // Feed r turns redundant: every new item is already known elsewhere.
        $this->steady(['z'], 1);
        for ($i = 0; $i < 12; $i++) {
            $url = 'https://news.example.com/shared-' . $i;
            $this->fetch('z', 'ok', [], false);
            $this->item('z', $url);
            $this->plugin->recorder->flush();
        }
        for ($i = 0; $i < 12; $i++) {
            $this->fetch('r', 'ok', [], false);
            $this->item('r', 'https://news.example.com/shared-' . $i);
            $this->plugin->recorder->flush();
        }
        $this->settings(['redundant_min_fresh' => 10, 'redundant_percent' => 10]);
        \WpStub::$mail = [];
        $this->check();
        self::assertSame('redundant', $this->feed('r')['status']);
        self::assertSame([], \WpStub::$mail, 'redundant does not alert by default');

        $this->settings(['alert_redundant' => true]);
        $this->plugin->operator->mute((int) $this->feed('r')['id'], 1);
        $this->plugin->operator->unmute((int) $this->feed('r')['id'], 1);
        $this->check();
        self::assertSame('redundant', $this->feed('r')['status']);
        self::assertStringContainsString('1 redundant', \WpStub::$mail[0]['subject']);
    }

    /** Test 55 (case 64). */
    public function testRecoveryNoticeAndSwitch(): void
    {
        $this->steady(['a']);
        $this->breakFeed('a');
        $this->check();
        \WpStub::$mail = [];
        $this->fetchWithItems('a', 1);
        $this->check();
        self::assertContains($this->feed('a')['status'], ['healthy', 'degraded']);
        self::assertStringContainsString('1 recovered', \WpStub::$mail[0]['subject']);
        self::assertStringContainsString('RECOVERED: Feed a', \WpStub::$mail[0]['body']);

        $this->settings(['recovery' => false, 'cooldown_hours' => 0]);
        $this->breakFeed('a');
        $this->check();
        \WpStub::$mail = [];
        $this->fetchWithItems('a', 1);
        $this->check();
        self::assertSame([], \WpStub::$mail);
    }

    /** Test 56 (case 65). */
    public function testSecondTransitionIntoSameStatusInsideCooldownIsRecordedNotSent(): void
    {
        $this->steady(['a']);
        $this->breakFeed('a');
        $this->check();
        $this->fetchWithItems('a', 1);
        $this->check();
        \WpStub::$mail = [];
        $this->clock->advance(3600);
        $this->breakFeed('a');
        $this->check();
        self::assertSame('failing', $this->feed('a')['status']);
        self::assertSame([], \WpStub::$mail);
        $last = $this->transitionEvents('a');
        $last = end($last);
        self::assertSame(Notifier::SUPPRESSED, $last['sent']);
        self::assertStringContainsString('[cooldown]', (string) $last['message']);
    }

    /** Test 57 (case 66). */
    public function testReminderAfter72HoursMarkedStill(): void
    {
        $this->steady(['a']);
        $this->breakFeed('a');
        $this->check();
        \WpStub::$mail = [];
        for ($h = 0; $h < 73; $h += 6) {
            $this->fetch('a', 'http_error', ['http_status' => 403]);
            $this->clock->advance(6 * 3600);
            $this->check();
        }
        self::assertCount(1, \WpStub::$mail);
        self::assertStringContainsString('still failing', \WpStub::$mail[0]['subject']);
        self::assertStringContainsString('STILL FAILING', \WpStub::$mail[0]['body']);

        $this->setUp();
        $this->settings(['reminder_hours' => 0]);
        $this->steady(['a']);
        $this->breakFeed('a');
        $this->check();
        \WpStub::$mail = [];
        for ($h = 0; $h < 80; $h += 6) {
            $this->fetch('a', 'http_error', ['http_status' => 403]);
            $this->clock->advance(6 * 3600);
            $this->check();
        }
        self::assertSame([], \WpStub::$mail);
    }

    /** Test 58 (case 67). */
    public function testFlappingFeedIsMarkedAndHeldUntilItSettles(): void
    {
        $this->settings(['cooldown_hours' => 0]);
        $this->steady(['a']);
        $sent = [];
        for ($i = 0; $i < 6; $i++) {
            \WpStub::$mail = [];
            if ($i % 2 === 0) {
                $this->breakFeed('a');
            } else {
                $this->fetchWithItems('a', 1);
            }
            $this->clock->advance(600);
            $this->check();
            $sent[] = count(\WpStub::$mail) > 0 ? \WpStub::$mail[0]['body'] : '';
        }
        // new>healthy from the steady history, then six changes in under two hours.
        self::assertCount(7, $this->transitionEvents('a'));
        self::assertNotSame('', $sent[2], 'the fourth change in 24 h still alerts');
        self::assertStringNotContainsString('FLAPPING', $sent[2]);
        self::assertStringContainsString('FLAPPING', $sent[3], 'the fifth change is shown as flapping');
        self::assertSame('', $sent[4], 'held while flapping');
        self::assertSame('', $sent[5], 'held while flapping');
        $state = Notifier::alertState($this->feed('a')['alert_state']);
        self::assertNotNull($state['flapping_since']);

        for ($h = 0; $h < 3; $h++) {
            $this->clock->advance(3600);
            $this->fetchWithItems('a', 1);
        }
        $this->check();
        self::assertSame('degraded', $this->feed('a')['status']);
        self::assertNotNull(Notifier::alertState($this->feed('a')['alert_state'])['flapping_since'], 'still held before 6 h');
        for ($h = 0; $h < 4; $h++) {
            $this->clock->advance(3600);
            $this->fetchWithItems('a', 1);
        }
        $this->check();
        self::assertSame('degraded', $this->feed('a')['status']);
        self::assertNull(Notifier::alertState($this->feed('a')['alert_state'])['flapping_since'], 'cleared after holding one status for 6 h');
    }

    /** Test 59 (case 68). */
    public function testThreeTransitionsInOneCheckProduceOneEmailWithCounts(): void
    {
        $this->steady(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
        $this->breakFeed('a');
        $this->breakFeed('b');
        $this->clock->advance(7 * 3600);
        foreach (['a', 'b', 'd', 'e', 'f', 'g', 'h'] as $k) {
            $this->fetchWithItems($k, 1);
        }
        $this->breakFeed('a');
        $this->breakFeed('b');
        $this->check();
        self::assertCount(1, \WpStub::$mail);
        self::assertSame('Feed health on Example Site: 2 failing, 1 unchecked', \WpStub::$mail[0]['subject']);
        $notify = array_filter($this->events('notify'), static fn (array $e): bool => str_starts_with((string) $e['message'], 'failing:') || str_starts_with((string) $e['message'], 'unchecked:'));
        self::assertCount(3, $notify, 'one log line per feed');
    }

    /** Test 60 (case 69). */
    public function testSystemicFailureProducesOneAlert(): void
    {
        $keys = ['k0', 'k1', 'k2', 'k3', 'k4', 'k5', 'k6', 'k7', 'k8', 'k9'];
        $this->steady($keys);
        foreach (array_slice($keys, 0, 6) as $k) {
            $this->breakFeed($k);
        }
        $this->check();
        self::assertCount(1, \WpStub::$mail);
        $mail = \WpStub::$mail[0];
        self::assertStringContainsString('systemic failure', $mail['subject']);
        self::assertStringContainsString('SYSTEMIC FAILURE', $mail['body']);
        self::assertStringContainsString('network', $mail['body']);
        self::assertStringNotContainsString('FAILING: Feed', $mail['body']);
        self::assertStringContainsString('Feed k5: 3 failures in a row', $mail['body']);

        $this->setUp();
        $this->steady($keys);
        foreach (array_slice($keys, 0, 2) as $k) {
            $this->breakFeed($k);
        }
        $this->check();
        self::assertStringNotContainsString('SYSTEMIC', \WpStub::$mail[0]['body']);
        self::assertStringContainsString('FAILING: Feed k0', \WpStub::$mail[0]['body']);
    }

    /** Test 61 (case 70). */
    public function testNewToFailingAlerts(): void
    {
        $this->breakFeed('fresh');
        $this->check();
        self::assertSame('failing', $this->feed('fresh')['status']);
        self::assertCount(1, \WpStub::$mail);
        self::assertStringContainsString('(was new)', \WpStub::$mail[0]['body']);
    }

    /** Test 62 (case 71). */
    public function testMutedAndSnoozedTransitionsAreRecordedNotSent(): void
    {
        $this->steady(['m', 's']);
        $this->plugin->operator->snooze((int) $this->feed('s')['id'], 1, '1d');
        $this->breakFeed('s');
        $this->check();
        self::assertSame('failing', $this->feed('s')['status'], 'snooze keeps the status');
        self::assertSame([], \WpStub::$mail);
        $events = $this->transitionEvents('s');
        self::assertSame(Notifier::SUPPRESSED, end($events)['sent']);

        $this->plugin->operator->mute((int) $this->feed('m')['id'], 1);
        $this->breakFeed('m');
        $this->check();
        self::assertSame('paused', $this->feed('m')['status']);
        self::assertSame([], \WpStub::$mail);
    }

    /** Test 63 (case 72). */
    public function testEleventhEmailInADayIsHeldAndReportedNextDay(): void
    {
        $keys = [];
        for ($i = 0; $i < 12; $i++) {
            $keys[] = 'c' . $i;
        }
        $this->steady($keys, 30);
        $today = gmdate('Y-m-d', $this->clock->now());
        for ($i = 0; $i < 11; $i++) {
            $this->breakFeed($keys[$i]);
            $this->check();
            $this->clock->advance(60);
        }
        self::assertSame($today, gmdate('Y-m-d', $this->clock->now()));
        self::assertCount(10, \WpStub::$mail);
        self::assertSame(1, $this->counter('emails_held'));
        self::assertNotSame([], array_filter($this->events('notify'), static fn (array $e): bool => str_contains((string) $e['message'], 'email cap reached')));

        $this->clock->set((int) strtotime($today . ' 00:00:00 UTC') + 86400 + 60);
        foreach ($keys as $k) {
            $this->fetchWithItems($k, 1);
        }
        $this->breakFeed($keys[11]);
        $this->check();
        self::assertCount(11, \WpStub::$mail);
        self::assertStringContainsString('1 notification was held back by the daily email cap', \WpStub::$mail[10]['body']);
    }

    /** Test 64 (case 73). */
    public function testEmailBodyIsPlainTextWithEveryField(): void
    {
        $this->steady(['a']);
        for ($i = 0; $i < 3; $i++) {
            $this->fetch('a', 'http_error', ['http_status' => 403, 'feed_url' => 'https://feeds.example.com/a?token=s3cret&cat=1']);
        }
        $this->check();
        $mail = \WpStub::$mail[0];
        self::assertSame(['admin@example.com'], $mail['to']);
        self::assertStringContainsString('Content-Type: text/plain', implode("\n", (array) $mail['headers']));
        $body = $mail['body'];
        self::assertStringContainsString('FAILING: Feed a', $body);
        self::assertStringContainsString('Status: failing (was healthy)', $body);
        self::assertStringContainsString('Reason: 3 failures in a row: HTTP 403 blocked', $body);
        self::assertStringContainsString('Last HTTP status: 403', $body);
        self::assertMatchesRegularExpression('/Last success: \d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $body);
        self::assertStringContainsString('Details: https://example.com/wp-admin/tools.php?page=feed-health&feed=' . $this->feed('a')['id'], $body);
        self::assertStringContainsString('token=***', $body);
        self::assertStringNotContainsString('s3cret', $body);
        self::assertSame(strip_tags($body), $body);
    }

    public function testRecipientsSettingAndFilter(): void
    {
        $this->settings(['recipients' => 'ops@example.com, not-an-email, editor@example.com']);
        self::assertSame(['ops@example.com', 'editor@example.com'], $this->plugin->settings->recipients());
        add_filter('feed_health_alert_recipients', static fn (array $l): array => ['pager@example.com']);
        self::assertSame(['pager@example.com'], $this->plugin->settings->recipients());
    }

    /** Test 65 (case 74). */
    public function testFailedMailIsLoggedAndRetriedThreeTimesAtMost(): void
    {
        $this->steady(['a']);
        \WpStub::$mailResult = false;
        $this->breakFeed('a');
        for ($i = 0; $i < 5; $i++) {
            $this->check();
            $this->clock->advance(3600);
        }
        self::assertCount(3, \WpStub::$mail, 'three tries, then give up');
        self::assertCount(4, $this->events('mail_failed'), 'three failures and one final line');
        $events = $this->transitionEvents('a');
        self::assertSame(Notifier::GAVE_UP, end($events)['sent']);
        self::assertSame(3, $this->counter('emails_failed'));

        \WpStub::$mailResult = true;
        \WpStub::$mail = [];
        $this->setUp();
        $this->steady(['a']);
        \WpStub::$mailResult = false;
        $this->breakFeed('a');
        $this->check();
        \WpStub::$mailResult = true;
        $this->check();
        self::assertCount(2, \WpStub::$mail, 'the retry went out');
        $events = $this->transitionEvents('a');
        self::assertSame(Notifier::SENT, end($events)['sent']);
    }

    /** Test 66 (case 75). */
    public function testLogChannelWritesOneEventPerTransitionAndPhpLogOnlyWhenOn(): void
    {
        $this->settings(['channel_email' => false]);
        $this->steady(['a', 'b', 'c', 'd', 'e', 'f', 'g']);
        $this->breakFeed('a');
        $this->breakFeed('b');
        $this->check();
        $lines = array_filter($this->events('notify'), static fn (array $e): bool => str_starts_with((string) $e['message'], 'failing:'));
        self::assertCount(2, $lines);
        self::assertStringNotContainsString(Logger::PREFIX . 'failing', $this->phpLog());

        $this->settings(['php_error_log' => true]);
        $this->breakFeed('c');
        $this->check();
        self::assertStringContainsString(Logger::PREFIX . 'failing: Feed c', $this->phpLog());
    }

    /** Test 67 (case 76). */
    public function testNotifyActionFiresWithTransitionsAndResults(): void
    {
        $seen = [];
        add_action('feed_health_notify', static function (array $entries, array $results) use (&$seen): void {
            $seen[] = [$entries, $results];
        }, 10, 2);
        $transitions = [];
        add_action('feed_health_transition', static function (Transition $t) use (&$transitions): void {
            $transitions[] = $t;
        });
        $this->steady(['a']);
        $this->breakFeed('a');
        $this->check();
        $last = end($seen);
        self::assertNotFalse($last);
        self::assertInstanceOf(Transition::class, $last[0][0]);
        self::assertSame('failing', $last[0][0]->to);
        self::assertSame(['log' => 'written', 'email' => 'sent'], $last[1]);
        self::assertSame('failing', end($transitions)->to);
    }

    /** Test 68 (case 77). */
    public function testTestAlertReportsEachChannel(): void
    {
        $results = $this->plugin->notifier->testAlert(7);
        self::assertSame('written', $results['log']);
        self::assertSame('sent to admin@example.com', $results['email']);
        self::assertStringStartsWith('[test] ', \WpStub::$mail[0]['subject']);
        \WpStub::$mailResult = false;
        $results = $this->plugin->notifier->testAlert(7);
        self::assertStringStartsWith('failed', $results['email']);
        $this->settings(['channel_email' => false, 'channel_log' => false]);
        self::assertSame(['log' => 'disabled', 'email' => 'disabled'], $this->plugin->notifier->testAlert(7));
        $operator = $this->events('operator');
        self::assertSame(7, end($operator)['user_id']);
    }

    public function testNetworkCapHoldsMailAcrossSites(): void
    {
        \WpStub::$multisite = true;
        update_site_option(Notifier::NETWORK_OPTION, ['cap' => 1]);
        $this->steady(['a', 'b', 'c', 'd', 'e']);
        $this->breakFeed('a');
        $this->check();
        $this->clock->advance(60);
        $this->breakFeed('b');
        $this->check();
        self::assertCount(1, \WpStub::$mail);
        self::assertSame(1, $this->counter('emails_held'));
    }
}
