<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;

/**
 * How this site runs its pick'em, and what the last sync found.
 *
 * Three kinds of value live here and the difference matters.
 *
 *  - Rules of the game an operator set: confidence mode, the penalty, how long
 *    before kickoff a game locks. These change what a score means, so they are
 *    read at scoring time rather than baked into a row.
 *  - One credential: the CollegeFootballData API key. Write-only, blank means
 *    keep, never rendered back into the form.
 *  - What the scheduled sync wrote down, so a page can say how current it is
 *    without asking anybody.
 *
 * 🚨 `save()` writes only keys listed in DEFAULTS. A form field naming
 * something else is ignored rather than becoming a site-wide setting Picks has
 * no business setting.
 */
final class Settings
{
    /**
     * The stored defaults, and the list of keys `save()` will accept.
     *
     * Everything is a string because that is what the settings table holds;
     * the accessors below are the only place a type is decided.
     */
    private const DEFAULTS = [
        'picks_enabled' => '0',

        // 🚨 A credential. Write-only; see save().
        'picks_cfbd_key' => '',

        // The season being synced. One year at a time, because that is what
        // both providers are addressed by.
        'picks_season_year' => '',

        // Blank syncs every FBS team. A conference abbreviation narrows it.
        'picks_conference' => '',

        'picks_sync_regular' => '1',
        'picks_sync_postseason' => '1',

        /*
         * 🚨 How many minutes BEFORE kickoff a game stops taking picks. Zero
         * locks exactly at kickoff. Applied when the fixture is synced and
         * again whenever this value changes, so an operator who raises it does
         * not leave a week of games on the old rule.
         */
        'picks_lock_offset' => '0',

        'picks_confidence_mode' => '0',

        // `none`, `half` or `full`. See Scores.
        'picks_confidence_penalty' => 'none',

        // Open the next week automatically once every game in the current one
        // has a result. Week one is always opened by hand.
        'picks_auto_unlock' => '0',

        // Ask ESPN for live scores while games are being played.
        'picks_live_scores' => '0',

        // Minutes between live-score polls. Floored in pollMinutes().
        'picks_live_interval' => '5',

        /*
         * Written by the sync, read by the screens.
         *
         * 🚨 `_ok_at` only moves when a provider actually answered. Every "as
         * of" line on the site is built from those two numbers, and they are
         * what stops a page presenting a three-day-old score as the present
         * one.
         */
        'picks_fixtures_at' => '0',
        'picks_fixtures_ok_at' => '0',
        'picks_fixtures_status' => '',
        'picks_fixtures_error' => '',

        // Where the capped fixture sync got to, so the next run continues
        // rather than starting again. See Sync.
        'picks_fixtures_cursor' => '0',

        'picks_scores_at' => '0',
        'picks_scores_ok_at' => '0',
        'picks_scores_status' => '',
        'picks_scores_error' => '',

        'picks_teams_ok_at' => '0',

        /*
         * 🚨 The provider's monthly allowance, and what Picks has spent of it.
         *
         * CollegeFootballData counts calls per calendar month and answers 429
         * with "Monthly call quota exceeded" once the allowance is gone — for
         * the rest of the month, whatever you do. Convoro's standing rule is
         * that queued work caps its own outbound calls, and this is that cap:
         * Picks stops one call short of the provider stopping it, so the board
         * degrades to "not refreshed lately" instead of to nothing at all.
         *
         * 🚨 The default is deliberately UNDER the free tier rather than at it.
         * The allowance is shared with anything else using the same key, and a
         * budget that exactly matches the limit is a budget that is discovered
         * to be wrong by running out.
         */
        'picks_monthly_cap' => '900',
        'picks_calls_used' => '0',

        // The month `picks_calls_used` counts, as YYYY-MM. A different month
        // means the counter is stale and starts again.
        'picks_calls_period' => '',

        /*
         * 🚨 Epoch seconds before which fixtures must not be fetched at all.
         *
         * Set when a provider refuses. Without it the hourly schedule keeps
         * asking a provider that has already said no — for thirty-eight hours
         * straight, on the site this was found on — which fixes nothing, and
         * on a per-call plan spends money to be told no again.
         */
        'picks_fixtures_retry_after' => '0',

        // Epoch of the last completed pass over every week. A pass costs one
        // call per week, so the next one waits rather than starting the moment
        // the last finished. See Sync::PASS_GAP.
        'picks_fixtures_pass_at' => '0',
    ];

    /** Keys a form may send but must never be able to blank by omission. */
    private const CREDENTIALS = ['picks_cfbd_key'];

    /**
     * How stale a confirmation may be before a score stops being believed.
     *
     * 🚨 Comfortably longer than the slowest poll plus room for one missed run,
     * and no longer than that. Too short and a game in progress flickers into
     * "not known" between checks; too long and a scoreboard that stopped
     * updating an hour ago keeps presenting itself as live.
     */
    public const STALE_AFTER = 1800;

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function get(string $key): string
    {
        $this->load();

        return $this->values[$key] ?? (self::DEFAULTS[$key] ?? '');
    }

    public function enabled(): bool
    {
        return $this->get('picks_enabled') === '1';
    }

    public function cfbdKey(): string
    {
        return trim($this->get('picks_cfbd_key'));
    }

    /** Whether a key is stored. Never the key itself. */
    public function hasCfbdKey(): bool
    {
        return $this->cfbdKey() !== '';
    }

    /**
     * The season being synced.
     *
     * 🚨 Falls back to the current calendar year rather than to zero, because a
     * fresh install with nothing typed should sync this season rather than
     * silently sync nothing. A college football season is named for the year it
     * starts, so January's bowls belong to the previous year — hence the month
     * test rather than a bare `date('Y')`.
     */
    public function seasonYear(): int
    {
        $stored = (int) $this->get('picks_season_year');

        if ($stored > 1900) {
            return $stored;
        }

        return (int) date('n') < 3 ? (int) date('Y') - 1 : (int) date('Y');
    }

    public function conference(): string
    {
        return trim($this->get('picks_conference'));
    }

    public function syncsRegular(): bool
    {
        return $this->get('picks_sync_regular') === '1';
    }

    public function syncsPostseason(): bool
    {
        return $this->get('picks_sync_postseason') === '1';
    }

    /** Minutes before kickoff that a game stops taking picks. */
    public function lockOffsetMinutes(): int
    {
        return max(0, (int) $this->get('picks_lock_offset'));
    }

    public function confidenceMode(): bool
    {
        return $this->get('picks_confidence_mode') === '1';
    }

    /**
     * `none`, `half` or `full`.
     *
     * 🚨 Anything unrecognised reads as `none`. A stored value nobody expected
     * must not become a penalty nobody chose — the safe direction here is the
     * one that takes no points away.
     */
    public function confidencePenalty(): string
    {
        $value = $this->get('picks_confidence_penalty');

        return in_array($value, ['none', 'half', 'full'], true) ? $value : 'none';
    }

    public function autoUnlock(): bool
    {
        return $this->get('picks_auto_unlock') === '1';
    }

    public function liveScores(): bool
    {
        return $this->get('picks_live_scores') === '1';
    }

    /**
     * Minutes between live-score polls.
     *
     * 🚨 Floored at two. ESPN's scoreboard is public and unauthenticated, which
     * is exactly why it should not be hammered: there is no quota to warn
     * anybody, only an address that eventually stops answering this site.
     */
    public function pollMinutes(): int
    {
        return max(2, (int) $this->get('picks_live_interval'));
    }

    public function fixturesAt(): int
    {
        return (int) $this->get('picks_fixtures_at');
    }

    public function fixturesOkAt(): int
    {
        return (int) $this->get('picks_fixtures_ok_at');
    }

    public function fixturesStatus(): string
    {
        return $this->get('picks_fixtures_status');
    }

    public function fixturesError(): string
    {
        return $this->get('picks_fixtures_error');
    }

    public function fixturesCursor(): int
    {
        return max(0, (int) $this->get('picks_fixtures_cursor'));
    }

    public function scoresAt(): int
    {
        return (int) $this->get('picks_scores_at');
    }

    public function scoresOkAt(): int
    {
        return (int) $this->get('picks_scores_ok_at');
    }

    public function scoresStatus(): string
    {
        return $this->get('picks_scores_status');
    }

    public function scoresError(): string
    {
        return $this->get('picks_scores_error');
    }

    public function teamsOkAt(): int
    {
        return (int) $this->get('picks_teams_ok_at');
    }

    /**
     * How many things are wrong, for the pip in the admin rail.
     *
     * 🚨 Only ever non-zero when Picks is switched on. An extension that is
     * installed and not in use is not a fault, and a permanent badge is how
     * people learn to ignore badges.
     *
     * Reads settings rows and makes no outbound call: this runs on every admin
     * page render.
     */
    public function problems(): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        if (!$this->hasCfbdKey()) {
            return 1;
        }

        return $this->fixturesStatus() === 'unreachable' || $this->scoresStatus() === 'unreachable' ? 1 : 0;
    }

    /**
     * @param array<string, string> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }

            /*
             * 🚨 A blank credential LEAVES THE STORED ONE ALONE, and the rule
             * lives here rather than only in the controller.
             *
             * The form cannot render the key back — a credential printed into a
             * page is a credential in a browser cache, a screenshot, and the
             * support ticket that screenshot is attached to. So a blank box is
             * ambiguous between "I did not touch this" and "clear it", and it
             * has to mean the first: somebody who came to change the season
             * year must not stop every sync on the way out.
             */
            if (in_array($key, self::CREDENTIALS, true) && trim((string) $value) === '') {
                continue;
            }

            $this->put($key, (string) $value);
        }

        $this->values = null;
    }

    /** Clears a credential, which is the deliberate act a blank box is not. */
    public function forget(string $key): void
    {
        if (!in_array($key, self::CREDENTIALS, true)) {
            return;
        }

        $this->put($key, '');
        $this->values = null;
    }

    /**
     * What the last fixture sync did.
     *
     * @param string $status `ok`, `unreachable`, `unconfigured` or `idle`
     */
    public function recordFixtures(string $status, string $error = '', ?int $cursor = null): void
    {
        $this->put('picks_fixtures_at', (string) time());
        $this->put('picks_fixtures_status', $status);
        $this->put('picks_fixtures_error', mb_substr($error, 0, 500));

        if ($status === 'ok') {
            $this->put('picks_fixtures_ok_at', (string) time());
        }

        if ($cursor !== null) {
            $this->put('picks_fixtures_cursor', (string) max(0, $cursor));
        }

        $this->values = null;
    }

    public function recordScores(string $status, string $error = ''): void
    {
        $this->put('picks_scores_at', (string) time());
        $this->put('picks_scores_status', $status);
        $this->put('picks_scores_error', mb_substr($error, 0, 500));

        if ($status === 'ok') {
            $this->put('picks_scores_ok_at', (string) time());
        }

        $this->values = null;
    }

    public function recordTeams(): void
    {
        $this->put('picks_teams_ok_at', (string) time());
        $this->values = null;
    }

    /* --------------------------------------------------------- call budget */

    /** The provider allowance this site is working to, per calendar month. */
    public function monthlyCap(): int
    {
        return max(0, (int) $this->get('picks_monthly_cap'));
    }

    /**
     * Calls spent this month.
     *
     * 🚨 Reads as zero in a new month rather than being reset by a job. There
     * is no monthly tick in Convoro, and a counter that needs one is a counter
     * that reads as full forever on a site whose scheduler stopped.
     */
    public function callsUsed(): int
    {
        if ($this->get('picks_calls_period') !== self::period()) {
            return 0;
        }

        return max(0, (int) $this->get('picks_calls_used'));
    }

    public function callsLeft(): int
    {
        $cap = $this->monthlyCap();

        // No cap set means no budget to enforce. An operator on a paid plan
        // with no monthly limit should not have to invent a number.
        return $cap === 0 ? PHP_INT_MAX : max(0, $cap - $this->callsUsed());
    }

    /** Counts one call against this month, rolling the period when it turns. */
    public function recordCall(): void
    {
        $period = self::period();
        $used = $this->get('picks_calls_period') === $period
            ? max(0, (int) $this->get('picks_calls_used'))
            : 0;

        $this->put('picks_calls_period', $period);
        $this->put('picks_calls_used', (string) ($used + 1));

        $this->values = null;
    }

    public function retryAfter(): int
    {
        return max(0, (int) $this->get('picks_fixtures_retry_after'));
    }

    /** Refuses to fetch fixtures until the given moment. */
    public function holdOffUntil(int $when): void
    {
        $this->put('picks_fixtures_retry_after', (string) max(0, $when));
        $this->values = null;
    }

    public function passAt(): int
    {
        return max(0, (int) $this->get('picks_fixtures_pass_at'));
    }

    public function recordPass(): void
    {
        $this->put('picks_fixtures_pass_at', (string) time());
        $this->values = null;
    }

    /**
     * The first moment of next month, in UTC.
     *
     * What a provider counting per calendar month resets on, and the only
     * honest answer to "when will this work again" once a quota is gone.
     */
    public static function quotaResetsAt(): int
    {
        return (int) (new \DateTimeImmutable('first day of next month 00:00:00', new \DateTimeZone('UTC')))
            ->getTimestamp();
    }

    private static function period(): string
    {
        return gmdate('Y-m');
    }

    private function put(string $key, string $value): void
    {
        $existing = $this->db->table('settings')->where('key', $key)->first();

        $existing === null
            ? $this->db->table('settings')->insertGetId(['key' => $key, 'value' => $value])
            : $this->db->table('settings')->where('key', $key)->updateAll(['value' => $value]);
    }

    private function load(): void
    {
        if ($this->values !== null) {
            return;
        }

        $this->values = [];

        foreach ($this->db->table('settings')->whereLike('key', 'picks\_%')->get() as $row) {
            $this->values[(string) $row['key']] = (string) ($row['value'] ?? '');
        }
    }
}
