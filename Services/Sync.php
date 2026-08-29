<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Convoro;
use Convoro\Extensions\Picks\Services\Sources\Cfbd;
use Convoro\Extensions\Picks\Services\Sources\Espn;

/**
 * The only code here that talks to anybody else, and the only code that runs on
 * a timer.
 *
 * Two jobs, on two cadences, for two different questions.
 *
 * **`fixtures()` — hourly.** Who the teams are, how the season divides into
 * weeks, and what the fixtures are. Slow-moving facts.
 *
 * 🚨 **Capped, with a cursor.** A full FBS season is seventeen weeks and each
 * one is its own request; fetching them all in a run would be a queue worker
 * held for three minutes on a good day and rather longer on a bad one. Six
 * calls a run, and where it got to is written down, so a fresh install has its
 * whole season within about three hours and every run after that is short.
 * This is the shape the Flarum original did not have: it fetched every week in
 * the admin request and died on `max_execution_time` halfway through, leaving
 * the schedule half-synced and nothing on screen to say which half.
 *
 * **`scores()` — minutely, gated.** What the score is. Almost every run does
 * nothing: it returns immediately unless live scores are switched on, the
 * configured interval has elapsed, and there is actually a game being played.
 * One request answers for every game at once.
 *
 * 🚨 An outage writes NOTHING. Both jobs treat "nobody answered" as a fact
 * about the network rather than a fact about football: the rows are left
 * exactly as they were and their confirmation ages, which is what turns the
 * front end's answer into "not known" instead of into a lie. A pick'em that
 * read silence as news would blank a Saturday's scores.
 */
final class Sync
{
    /** CFBD calls one `fixtures()` run may make. See the note above. */
    public const CALL_BUDGET = 6;

    /**
     * 🚨 How long to wait after finishing a pass over every week.
     *
     * Without this the cursor wrapped straight back to zero and the whole
     * season was re-fetched every few hours, for ever. On the site this was
     * found on that was six calls an hour, ~4,300 a month, against a provider
     * allowance a fraction of that — the quota was gone by the 20th and the
     * board went dark a week before the season opened.
     *
     * Fixtures do move, so a pass still has to come round again; they do not
     * move hourly. Twelve hours is two passes a day over the weeks that can
     * still change, which is more than enough to catch a rescheduled kickoff
     * and roughly a tenth of the calls.
     */
    public const PASS_GAP = 43200;

    /**
     * 🚨 How long after its last game a week stops being re-fetched.
     *
     * A played week is history: the fixtures cannot change and the scores come
     * from ESPN, not from here. Two days rather than zero because a provider
     * corrects the odd result late, and because a game postponed into the
     * following week must not freeze the week it left.
     */
    public const SETTLED_AFTER = 172800;

    /** Members re-scored inline before the rest are handed to another job. */
    public const RESCORE_BATCH = 100;

    public function __construct(
        private readonly Convoro $app,
        private readonly Settings $settings,
        private readonly Teams $teams,
        private readonly Seasons $seasons,
        private readonly Games $games,
        private readonly Picks $picks,
        private readonly Scores $scores,
        private readonly Cfbd $cfbd,
        private readonly Espn $espn,
    ) {
    }

    /* ------------------------------------------------------------ fixtures */

    /**
     * Teams, weeks and fixtures, a few calls at a time.
     *
     * @return array<string, mixed> a summary, for the log and for tests
     */
    public function fixtures(): array
    {
        if (!$this->settings->enabled()) {
            $this->settings->recordFixtures('idle');

            return ['skipped' => 'off'];
        }

        if (!$this->cfbd->configured()) {
            $this->settings->recordFixtures('unconfigured');

            return ['skipped' => 'unconfigured'];
        }

        /*
         * 🚨 A provider that has already refused is not asked again until the
         * moment it said, and the status is left EXACTLY as it was.
         *
         * Recording anything here would overwrite the reason with a fresher,
         * emptier one every hour, and the health screen would lose the only
         * sentence that explains what an operator is looking at.
         */
        if ($this->settings->retryAfter() > time()) {
            return ['skipped' => 'holding off'];
        }

        // Housekeeping that needs no network and must happen whatever else
        // does: a game past its cutoff should read as closed on every screen.
        $closed = $this->games->closePassed();

        $year = $this->settings->seasonYear();
        $seasonId = $this->seasons->seasonForYear($year);
        $cursor = $this->settings->fixturesCursor();
        $budget = self::CALL_BUDGET;

        $summary = ['closed' => $closed, 'teams' => 0, 'weeks' => 0, 'games' => 0];

        if ($cursor === 0) {
            [$done, $spent, $error] = $this->syncTeamsAndCalendar($seasonId, $year, $budget, $summary);

            $budget -= $spent;

            if ($error !== '') {
                // 🚨 The cursor is NOT advanced. A failed step is retried next
                // hour rather than skipped, which is the difference between a
                // provider hiccup and a week of fixtures nobody ever fetches.
                $this->settings->holdOffUntil(self::holdFor($error));
                $this->settings->recordFixtures(self::statusFor($error), $error);

                return $summary + ['error' => $error];
            }

            if (!$done) {
                $this->settings->recordFixtures('ok', '', 0);

                return $summary;
            }

            $cursor = 1;
        }

        /*
         * 🚨 Only the weeks that can still change. A settled week is skipped
         * for the rest of the season, which is what takes a pass from "every
         * week, for ever" down to the handful that are still moving.
         */
        $weekNumbers = $this->regularWeekNumbers($seasonId);
        $steps = count($weekNumbers) + 1; // the weeks, then the postseason

        /*
         * Between passes, do the housekeeping and stop. `closePassed()` above
         * has already run — that is the part that must happen every hour, and
         * it needs nobody's network.
         */
        if ($cursor === 0 && $this->settings->passAt() + self::PASS_GAP > time()) {
            return $summary + ['skipped' => 'between passes'];
        }

        while ($budget > 0 && $cursor <= $steps) {
            $isPostseason = $cursor > count($weekNumbers);

            $error = $isPostseason
                ? $this->syncPostseason($seasonId, $year, $summary)
                : $this->syncRegularWeek($seasonId, $year, $weekNumbers[$cursor - 1], $summary);

            $budget--;

            if ($error !== '') {
                $this->settings->holdOffUntil(self::holdFor($error));
                $this->settings->recordFixtures(self::statusFor($error), $error, $cursor);

                return $summary + ['error' => $error];
            }

            $cursor++;
        }

        // 🚨 Wraps rather than stopping. The fixture list keeps changing all
        // season — kickoffs move, games are added — so a sync that ran once and
        // considered itself finished would leave a stale board by October.
        if ($cursor > $steps) {
            $this->settings->recordPass();
        }

        $this->settings->recordFixtures('ok', '', $cursor > $steps ? 0 : $cursor);

        $this->maybeUnlockNext();

        return $summary;
    }

    /**
     * @param array<string, mixed> $summary
     * @return array{0: bool, 1: int, 2: string} done, calls spent, error
     */
    private function syncTeamsAndCalendar(int $seasonId, int $year, int $budget, array &$summary): array
    {
        $spent = 0;

        if ($budget < 2) {
            return [false, 0, ''];
        }

        [$teams, $error] = $this->cfbd->teams();
        $spent++;

        if ($error !== '') {
            return [false, $spent, $error];
        }

        foreach ($teams as $team) {
            if ($this->teams->upsert($team) > 0) {
                $summary['teams']++;
            }
        }

        $this->settings->recordTeams();

        // 🚨 No network. Reads `forums` and writes only `picks_teams`.
        $this->teams->linkForums();

        if (!$this->settings->syncsRegular()) {
            return [true, $spent, ''];
        }

        [$calendar, $error] = $this->cfbd->calendar($year);
        $spent++;

        if ($error !== '') {
            return [false, $spent, $error];
        }

        foreach ($calendar as $entry) {
            $this->seasons->upsertWeek(
                $seasonId,
                (int) $entry['week'],
                Seasons::REGULAR,
                'Week ' . (int) $entry['week'],
                $entry['start_date'],
                $entry['end_date'],
            );

            $summary['weeks']++;
        }

        return [true, $spent, ''];
    }

    /** @param array<string, mixed> $summary */
    private function syncRegularWeek(int $seasonId, int $year, int $weekNumber, array &$summary): string
    {
        if (!$this->settings->syncsRegular()) {
            return '';
        }

        [$fixtures, $error] = $this->cfbd->games($year, Seasons::REGULAR, $weekNumber);

        if ($error !== '') {
            return $error;
        }

        $weekId = $this->seasons->upsertWeek(
            $seasonId,
            $weekNumber,
            Seasons::REGULAR,
            'Week ' . $weekNumber,
            null,
            null,
        );

        $summary['games'] += $this->storeFixtures($fixtures, $weekId);

        return '';
    }

    /** @param array<string, mixed> $summary */
    private function syncPostseason(int $seasonId, int $year, array &$summary): string
    {
        if (!$this->settings->syncsPostseason()) {
            return '';
        }

        [$fixtures, $error] = $this->cfbd->games($year, Seasons::POSTSEASON);

        if ($error !== '') {
            return $error;
        }

        if ($fixtures === []) {
            return '';
        }

        /*
         * Every bowl in one week, which is how the provider numbers them and
         * how anybody talks about them. The dates come from the fixtures
         * themselves because the calendar endpoint does not cover them.
         */
        $dates = array_map(static fn (array $f): int => (int) $f['match_at'], $fixtures);

        $weekId = $this->seasons->upsertWeek(
            $seasonId,
            1,
            Seasons::POSTSEASON,
            'Bowl Season',
            gmdate('Y-m-d', min($dates)),
            gmdate('Y-m-d', max($dates)),
        );

        $summary['games'] += $this->storeFixtures($fixtures, $weekId);

        return '';
    }

    /**
     * @param list<array<string, mixed>> $fixtures
     * @return int how many were stored
     */
    private function storeFixtures(array $fixtures, int $weekId): int
    {
        if ($weekId < 1) {
            return 0;
        }

        // The provider's team ids, mapped to ours, once for the whole week.
        $byCfbd = [];

        foreach ($this->teams->map() as $id => $team) {
            if ((int) $team['cfbd_id'] > 0) {
                $byCfbd[(int) $team['cfbd_id']] = $id;
            }
        }

        $offset = $this->settings->lockOffsetMinutes();
        $stored = 0;

        foreach ($fixtures as $fixture) {
            $homeId = $byCfbd[(int) $fixture['home_cfbd_id']] ?? 0;
            $awayId = $byCfbd[(int) $fixture['away_cfbd_id']] ?? 0;

            /*
             * 🚨 A game either of whose teams we do not hold is SKIPPED, not
             * stored with a blank side. The pick'em is FBS-only and these are
             * the games against everybody else; a fixture with half a matchup
             * is a row on the board nobody can pick and a logo that will not
             * load.
             */
            if ($homeId < 1 || $awayId < 1) {
                continue;
            }

            [$id, ] = $this->games->upsertFixture([
                'cfbd_id' => $fixture['cfbd_id'],
                'week_id' => $weekId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'neutral_site' => $fixture['neutral_site'],
                'match_at' => $fixture['match_at'],
                'completed' => $fixture['completed'],
                'home_score' => $fixture['home_score'],
                'away_score' => $fixture['away_score'],
            ], $offset);

            if ($id > 0) {
                $stored++;
            }
        }

        return $stored;
    }

    /** @return list<int> */
    private function regularWeekNumbers(int $seasonId): array
    {
        $out = [];
        $settledBefore = time() - self::SETTLED_AFTER;

        foreach ($this->seasons->weeks($seasonId) as $week) {
            if ($week['season_type'] !== Seasons::REGULAR || $week['week_number'] < 1) {
                continue;
            }

            /*
             * 🚨 A week whose last game finished days ago is not fetched again
             * for the rest of the season. Its fixtures cannot change, and its
             * scores come from ESPN rather than from here — so re-fetching it
             * spends the provider allowance to learn nothing.
             *
             * A week with no end_date is NEVER treated as settled. That is a
             * week the calendar has not described yet, and guessing it is over
             * would quietly drop it from the board.
             */
            $end = (string) ($week['end_date'] ?? '');

            if ($end !== '' && strtotime($end . ' 23:59:59 UTC') < $settledBefore) {
                continue;
            }

            $out[] = $week['week_number'];
        }

        sort($out);

        return $out;
    }

    /**
     * The status a failure is recorded under.
     *
     * 🚨 Every failure used to be `unreachable`, which put "Cannot fetch" and
     * a red Needs-fixing chip in front of an operator for something no
     * operator can fix. What the screen says and what the sync does both
     * follow from this, so they cannot drift apart.
     */
    private static function statusFor(string $error): string
    {
        return match ($error) {
            'quota spent' => 'quota',
            'budget spent' => 'budget',
            'rate limited' => 'rate_limited',
            'key rejected' => 'key_rejected',
            default => 'unreachable',
        };
    }

    /**
     * What to do about an error the provider returned.
     *
     * 🚨 A refusal and an outage are different events wearing the same shape.
     * An outage is retried on the next tick, because it may already be over. A
     * refusal must NOT be retried, because the next call gets the same answer
     * and, on a metered plan, is charged for it.
     *
     * @return int the moment fixtures may be fetched again, 0 for "right away"
     */
    private static function holdFor(string $error): int
    {
        return match ($error) {
            // Gone until the provider's month turns. Nothing an operator does
            // brings it back, so asking again before then is pure noise.
            'quota spent' => Settings::quotaResetsAt(),

            // This site's own cap. Same reset, and reaching it means the cap
            // is doing its job.
            'budget spent' => Settings::quotaResetsAt(),

            // A rate, not an allowance: it passes. An hour is longer than any
            // per-minute window and short enough to catch the same day.
            'rate limited' => time() + 3600,

            // 🚨 A rejected key is not retried on a timer either — it is fixed
            // by a person. Half an hour keeps the log readable while somebody
            // is actually pasting a new one in.
            'key rejected' => time() + 1800,

            default => 0,
        };
    }

    /* -------------------------------------------------------------- scores */

    /**
     * What the score is, for games being played now.
     *
     * @return array<string, mixed>
     */
    public function scores(): array
    {
        if (!$this->settings->enabled() || !$this->settings->liveScores()) {
            return ['skipped' => 'off'];
        }

        $now = time();

        /*
         * 🚨 The interval gate, and the reason a minutely cadence is
         * affordable. The scheduler fires every minute; this decides whether
         * that tick does anything, so an operator who chose fifteen minutes
         * gets fifteen minutes rather than a setting the scheduler ignores.
         */
        if ($now - $this->settings->scoresAt() < $this->settings->pollMinutes() * 60) {
            return ['skipped' => 'too soon'];
        }

        if (!$this->games->anyActive($now)) {
            $this->settings->recordScores('idle');

            return ['skipped' => 'nothing playing'];
        }

        /*
         * 🚨 The same window `anyActive()` uses, asked of ESPN's calendar.
         *
         * ESPN answers per DATE, in US Eastern. A game kicking off at 23:30 in
         * California is already tomorrow there and still today here, so asking
         * only about the current moment's date loses the tail of every long
         * Saturday — the late games, which is the hardest gap to notice.
         *
         * Six hours back is the same reach `anyActive()` allows a game to keep
         * running, so these two agree by construction rather than by luck. It
         * is one call on all but a couple of hours a week, and two then.
         */
        $days = [];
        $live = [];
        $error = '';

        foreach ([$now - 21600, $now] as $moment) {
            [$slate, $failure] = $this->espn->scoreboard($moment);

            $day = (new \DateTimeImmutable('@' . $moment))
                ->setTimezone(new \DateTimeZone('America/New_York'))
                ->format('Ymd');

            if (isset($days[$day])) {
                continue;
            }

            $days[$day] = true;

            if ($failure !== '') {
                $error = $failure;
                break;
            }

            foreach ($slate as $entry) {
                $live[(int) $entry['id']] = $entry;
            }
        }

        $live = array_values($live);

        if ($error !== '') {
            // 🚨 Nothing is written. Every game keeps what it had, and its
            // confirmation ages into "not known".
            $this->settings->recordScores('unreachable', $error);

            return ['error' => $error];
        }

        $this->settings->recordScores('ok');

        if ($live === []) {
            return ['updated' => 0, 'finished' => 0];
        }

        $ours = $this->matchToOurGames($live);
        $updated = 0;
        $finished = [];

        foreach ($live as $entry) {
            $gameId = $ours[(int) $entry['id']] ?? 0;

            if ($gameId < 1) {
                continue;
            }

            $justFinished = $this->games->recordFromSource(
                $gameId,
                $entry['home'],
                $entry['away'],
                (bool) $entry['completed'],
                $now,
            );

            $updated++;

            if ($justFinished) {
                $finished[] = $gameId;
            }
        }

        $players = $this->settleFinished($finished);

        return ['updated' => $updated, 'finished' => count($finished), 'players' => $players];
    }

    /**
     * Our game ids, keyed by the provider's.
     *
     * @param list<array{id: int, home: ?int, away: ?int, completed: bool}> $live
     * @return array<int, int>
     */
    private function matchToOurGames(array $live): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $e): int => (int) $e['id'], $live)));

        if ($ids === []) {
            return [];
        }

        $out = [];

        // One query for the whole scoreboard rather than a lookup per game. On
        // a Saturday that is thirty-odd indexed selects a tick, every tick.
        foreach ($this->app->make('db')->table('picks_events')
            ->select('id', 'cfbd_id')
            ->whereIn('cfbd_id', $ids)
            ->get() as $row) {
            $out[(int) $row['cfbd_id']] = (int) $row['id'];
        }

        return $out;
    }

    /* ------------------------------------------------------------- settling */

    /**
     * Marks every pick on games that have just finished, then starts the
     * re-scoring.
     *
     * 🚨 Members are deduplicated ACROSS the games in this run rather than
     * re-scored once per game. Thirty bowls finishing in the same hour with two
     * hundred players is six thousand recalculations done game by game, or two
     * hundred done once — and each recalculation is three scopes of aggregate.
     *
     * @param list<int> $gameIds
     * @return int members re-scored in this run
     */
    public function settleFinished(array $gameIds): int
    {
        if ($gameIds === []) {
            return 0;
        }

        $scopes = [];
        $settled = [];

        foreach ($gameIds as $gameId) {
            $game = $this->games->byId($gameId);

            if ($game === null) {
                continue;
            }

            /*
             * 🚨 A game whose result is '' — equal scores, or a score that has
             * been cleared — leaves every pick UNSCORED rather than marking
             * them all wrong. See Picks::score().
             */
            $this->picks->score($gameId, (string) $game['result']);
            $settled[] = $gameId;

            $season = (int) $game['season_id'];
            $week = (int) $game['week_id'];
            $scopes[$season . ':' . $week] = ['s' => $season, 'w' => $week];
        }

        return $this->rescore([
            'games' => $settled,
            'scopes' => array_values($scopes),
            'after' => 0,
        ]);
    }

    /**
     * Recalculates one batch of members, then queues the next.
     *
     * 🚨 A CURSOR, not a list of members carried in the job payload. A payload
     * holding every affected member grows with the site and eventually exceeds
     * the column it is stored in — which fails as a job that silently never
     * runs, on the site where it matters most because it is the biggest one.
     *
     * 🚨 The batch is capped, so a handler cannot run long enough to overlap
     * its own next tick. The ranking pass waits until the last batch: ranking a
     * scope halfway through re-scoring it would publish a leaderboard ordered
     * by a mixture of old and new totals.
     *
     * @param array<string, mixed> $payload games, scopes, after; or all => true
     * @return int members re-scored in this run
     */
    public function rescore(array $payload): int
    {
        $gameIds = array_map('intval', (array) ($payload['games'] ?? []));
        $everything = !empty($payload['all']);
        $after = (int) ($payload['after'] ?? 0);

        $players = $this->scores->playersAfter(
            $after,
            $everything ? [] : $gameIds,
            self::RESCORE_BATCH,
        );

        foreach ($players as $userId) {
            if ($everything) {
                $this->scores->recalculateEverything($userId);

                continue;
            }

            foreach ((array) ($payload['scopes'] ?? []) as $scope) {
                $this->scores->recalculate(
                    $userId,
                    (int) ($scope['w'] ?? 0),
                    (int) ($scope['s'] ?? 0),
                );
            }
        }

        // A short batch means that was the last of them.
        if (count($players) >= self::RESCORE_BATCH) {
            try {
                $this->app->make('queue')->push('picks.rescore', [
                    'games' => $gameIds,
                    'scopes' => (array) ($payload['scopes'] ?? []),
                    'all' => $everything,
                    'after' => $players[count($players) - 1],
                ]);

                return count($players);
            } catch (\Throwable) {
                /*
                 * The queue is unwritable. Fall through and rank what has been
                 * scored: a partially-ordered board is worse than a stale one,
                 * but a board that never re-ranks at all is worse than either.
                 */
            }
        }

        $this->rankScopes($everything ? [] : (array) ($payload['scopes'] ?? []));
        $this->maybeUnlockNext();

        return count($players);
    }

    /**
     * Re-ranks the scopes a re-scoring touched, plus the two it always touches.
     *
     * @param list<array{s: int, w: int}> $scopes
     */
    private function rankScopes(array $scopes): void
    {
        $seasons = [];

        foreach ($scopes as $scope) {
            $season = (int) ($scope['s'] ?? 0);
            $week = (int) ($scope['w'] ?? 0);

            if ($season > 0 && $week > 0) {
                $this->scores->reRank($season, $week);
            }

            if ($season > 0) {
                $seasons[$season] = true;
            }
        }

        /*
         * When the caller named no scopes — a full recalculation — every season
         * and every week has to be re-ranked, because every total moved.
         */
        if ($scopes === []) {
            foreach ($this->seasons->all() as $season) {
                $seasonId = (int) $season['id'];
                $seasons[$seasonId] = true;

                foreach ($this->seasons->weeks($seasonId) as $week) {
                    $this->scores->reRank($seasonId, (int) $week['id']);
                }
            }
        }

        foreach (array_keys($seasons) as $seasonId) {
            $this->scores->reRank($seasonId, 0);
        }

        $this->scores->reRank(0, 0);
    }

    /**
     * Opens the next week once the current one is finished, if asked to.
     *
     * 🚨 Week one is never opened automatically, because there is no previous
     * week to have completed. That is deliberate: the first round is the one an
     * operator opens when they are ready for the season to start, and a
     * pick'em that opened itself the moment it was installed would take picks
     * nobody knew were being taken.
     */
    public function maybeUnlockNext(): int
    {
        if (!$this->settings->autoUnlock()) {
            return 0;
        }

        $opened = 0;

        foreach ($this->seasons->openWeeks() as $week) {
            if (!$this->games->weekIsComplete((int) $week['id'])) {
                continue;
            }

            $next = $this->seasons->nextWeek((int) $week['id']);

            if ($next === null || $next['is_open']) {
                continue;
            }

            $this->seasons->setOpen((int) $next['id'], true);
            $opened++;
        }

        return $opened;
    }
}
