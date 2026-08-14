<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;
use Convoro\Engine\Database\Query\Builder;
use Convoro\Engine\Database\Query\Expression;

/**
 * The games, and the two questions everything else asks about them.
 *
 * 🚨 `open()` is the first question and it is the whole integrity of this game.
 *
 * A pick may be made, changed or withdrawn only while the game is open, and a
 * game is open only while its own cutoff is in the future AND its week has been
 * opened by an operator AND it has not already started. That rule is written
 * once, here, and every write path calls it against a row it has just read from
 * the database. Nothing in a form, a URL or a session is trusted to say whether
 * a game is still open: the browser is the thing being defended against, so a
 * hidden `can_pick` field would be the member setting their own deadline.
 *
 * 🚨 `state()` is the second, and it has FOUR answers, not three. A game is
 * scheduled, live, final, or **not known** — because `home_score` is a record
 * of what a provider last said, not a fact about the present. A scoreboard that
 * goes away mid-afternoon leaves a game at 14–10 for ever, so the age of the
 * last confirmation decides whether that score is still worth showing. A stale
 * score presented as live is the worst thing this extension could put on a page.
 *
 * 🚨 Teams are attached from an in-memory map rather than joined. Two joins
 * onto the same table need aliases, and this database layer wraps a join target
 * in backticks — `picks_teams AS home_team` would become a table name with a
 * space in it. See Teams.
 */
final class Games
{
    public const SCHEDULED = 'scheduled';
    public const CLOSED = 'closed';
    public const IN_PROGRESS = 'in_progress';
    public const FINISHED = 'finished';

    public const STATUSES = [self::SCHEDULED, self::CLOSED, self::IN_PROGRESS, self::FINISHED];

    public const HOME = 'home';
    public const AWAY = 'away';

    /** The two things a pick may say. Anything else is not an outcome. */
    public const OUTCOMES = [self::HOME, self::AWAY];

    public function __construct(
        private readonly Connection $db,
        private readonly Teams $teams,
    ) {
    }

    /* ---------------------------------------------------------- the rules */

    /**
     * May a pick be entered, changed or withdrawn on this game right now?
     *
     * 🚨 The one gate. All three tests are needed and they answer different
     * questions: the week switch is the operator saying this round is running
     * at all, the status is the provider saying the game has moved on, and the
     * cutoff is this particular kickoff. A game in a closed week is not
     * pickable however far away it is; a game in an open week is not pickable
     * once its cutoff has gone.
     *
     * @param array<string, mixed> $game a row from this class, carrying
     *                                   `week_is_open`
     */
    public function open(array $game, ?int $now = null): bool
    {
        $now ??= time();

        if ((int) ($game['week_is_open'] ?? 0) !== 1) {
            return false;
        }

        /*
         * A game a provider has already moved off `scheduled` is under way or
         * over. Checked as well as the clock rather than instead of it: a
         * kickoff brought forward is a real thing, and the status is the only
         * warning of it anybody here gets.
         */
        if ((string) ($game['status'] ?? '') !== self::SCHEDULED) {
            return false;
        }

        return $this->cutoff($game) > $now;
    }

    /**
     * When this game stops taking picks, in epoch seconds.
     *
     * 🚨 Falls back to the kickoff when no cutoff was stored, and to zero when
     * neither is known — which reads as "already passed" and therefore refuses
     * the pick. A missing deadline must fail CLOSED. Treating an absent cutoff
     * as "no deadline" is a game that can be picked after it has been played.
     *
     * @param array<string, mixed> $game
     */
    public function cutoff(array $game): int
    {
        $cutoff = (int) ($game['cutoff_at'] ?? 0);

        return $cutoff > 0 ? $cutoff : (int) ($game['match_at'] ?? 0);
    }

    /**
     * Whether the deadline has gone, independently of everything else.
     *
     * 🚨 This is what decides whose picks a member may see, and it is
     * deliberately NOT `!open()`. A game in a week nobody has opened is not
     * pickable and its cutoff has not passed, so nobody's picks are on show
     * yet — `!open()` there would have revealed every one of them early.
     *
     * @param array<string, mixed> $game
     */
    public function locked(array $game, ?int $now = null): bool
    {
        return $this->cutoff($game) <= ($now ?? time());
    }

    /**
     * What this game is doing, as far as anybody here can honestly say.
     *
     * @param array<string, mixed> $game
     * @return string `scheduled`, `live`, `final` or `unknown`
     */
    public function state(array $game, ?int $now = null): string
    {
        $now ??= time();
        $status = (string) ($game['status'] ?? self::SCHEDULED);

        if ($status === self::FINISHED) {
            return 'final';
        }

        if ($status !== self::IN_PROGRESS) {
            return 'scheduled';
        }

        /*
         * 🚨 In progress according to a row somebody wrote down. Believed only
         * while the confirmation behind it is fresh — a scoreboard that stopped
         * answering half an hour ago is not evidence about this minute.
         */
        $confirmed = (int) ($game['confirmed_at'] ?? 0);

        return $confirmed > 0 && ($now - $confirmed) <= Settings::STALE_AFTER ? 'live' : 'unknown';
    }

    /**
     * Whether a score is worth putting on a page.
     *
     * A final score is a fact and never goes stale. A live one is only as good
     * as its last confirmation.
     *
     * @param array<string, mixed> $game
     */
    public function scoreIsCurrent(array $game, ?int $now = null): bool
    {
        if (($game['home_score'] ?? null) === null || ($game['away_score'] ?? null) === null) {
            return false;
        }

        return $this->state($game, $now) !== 'unknown';
    }

    /**
     * Who won, from the scores. '' while undecided.
     *
     * 🚨 College football does not end in a draw, so equal scores mean the data
     * is wrong or incomplete rather than that the game was tied — and the
     * honest answer to that is no result at all rather than a coin toss. Every
     * pick on the game stays unscored until somebody fixes it.
     *
     * @param array<string, mixed> $game
     */
    public function resultFrom(array $game): string
    {
        $home = $game['home_score'] ?? null;
        $away = $game['away_score'] ?? null;

        if ($home === null || $away === null || (int) $home === (int) $away) {
            return '';
        }

        return (int) $home > (int) $away ? self::HOME : self::AWAY;
    }

    /* ------------------------------------------------------------- reading */

    /**
     * One game, with its week's switch and both teams attached.
     *
     * 🚨 The week switch comes back on the row rather than being looked up
     * afterwards, because `open()` needs it — and a caller that forgot to fetch
     * it would get a silent, permanent answer in whichever direction the
     * default happened to fall.
     *
     * @return array<string, mixed>|null
     */
    public function byId(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $rows = $this->hydrate(
            $this->baseQuery()->where($this->p('picks_events') . '.id', $id)->limit(1)->get()
        );

        return $rows[0] ?? null;
    }

    /**
     * Every game in a week, in kickoff order.
     *
     * @return list<array<string, mixed>>
     */
    public function forWeek(int $weekId): array
    {
        if ($weekId < 1) {
            return [];
        }

        $events = $this->p('picks_events');

        return $this->hydrate(
            $this->baseQuery()
                ->where($events . '.week_id', $weekId)
                ->orderBy($events . '.match_at')
                ->orderBy($events . '.id')
                ->get()
        );
    }

    /**
     * Games an operator is looking through, filtered and paged.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function browse(int $weekId, string $status, string $search, int $page, int $perPage): array
    {
        $events = $this->p('picks_events');
        $perPage = max(1, min(200, $perPage));

        $total = $this->filtered($weekId, $status, $search)->count($events . '.id');

        $rows = $this->filtered($weekId, $status, $search)
            ->orderBy($events . '.match_at')
            ->orderBy($events . '.id')
            ->limit($perPage)
            ->offset(max(0, ($page - 1) * $perPage))
            ->get();

        return ['rows' => $this->hydrate($rows), 'total' => $total];
    }

    /**
     * The game ids in a week, cheaply.
     *
     * @return list<int>
     */
    public function idsInWeek(int $weekId): array
    {
        return array_map(
            'intval',
            $this->db->table('picks_events')->where('week_id', $weekId)->pluck('id')
        );
    }

    /** Whether any game is being played, or is about to be. */
    public function anyActive(?int $now = null): bool
    {
        $now ??= time();

        if ($this->db->table('picks_events')->where('status', self::IN_PROGRESS)->exists()) {
            return true;
        }

        /*
         * 🚨 A window either side of now, not "today". A game kicking off at
         * 23:30 runs into tomorrow, and a date comparison stops polling it at
         * midnight with a quarter still to play. Six hours back covers the
         * longest game anybody has played; two hours forward starts the poll
         * before kickoff so the first score is not the second one.
         */
        return $this->db->table('picks_events')
            ->where('status', '!=', self::FINISHED)
            ->whereBetween('match_at', $now - 21600, $now + 7200)
            ->exists();
    }

    /**
     * Whether every game in a week has a result.
     *
     * Used by the automatic unlock. A week with no games at all is not
     * complete — otherwise an empty week would unlock the next one for ever.
     */
    public function weekIsComplete(int $weekId): bool
    {
        $total = $this->db->table('picks_events')->where('week_id', $weekId)->count();

        if ($total === 0) {
            return false;
        }

        return $this->db->table('picks_events')
            ->where('week_id', $weekId)
            ->where('status', '!=', self::FINISHED)
            ->count() === 0;
    }

    /**
     * Counts by status for one week, for the admin screen.
     *
     * @return array<string, int>
     */
    public function statusCounts(int $weekId): array
    {
        $out = array_fill_keys(self::STATUSES, 0);

        $rows = $this->db->table('picks_events')
            ->select('status', 'COUNT(*) AS tally')
            ->where('week_id', $weekId)
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['tally'];
        }

        return $out;
    }

    /* ------------------------------------------------------------- writing */

    /**
     * Creates or updates one fixture from a provider.
     *
     * @param array<string, mixed> $values
     * @return array{0: int, 1: bool} the game's id, and whether it is new
     */
    public function upsertFixture(array $values, int $lockOffsetMinutes): array
    {
        $cfbdId = (int) ($values['cfbd_id'] ?? 0);
        $homeId = (int) ($values['home_team_id'] ?? 0);
        $awayId = (int) ($values['away_team_id'] ?? 0);
        $matchAt = (int) ($values['match_at'] ?? 0);

        if ($cfbdId < 1 || $homeId < 1 || $awayId < 1 || $matchAt < 1) {
            return [0, false];
        }

        $existing = $this->db->table('picks_events')->where('cfbd_id', $cfbdId)->first();

        $changed = [
            'week_id' => (int) ($values['week_id'] ?? 0),
            'home_team_id' => $homeId,
            'away_team_id' => $awayId,
            'neutral_site' => empty($values['neutral_site']) ? 0 : 1,
            'match_at' => $matchAt,
            'cutoff_at' => max(0, $matchAt - max(0, $lockOffsetMinutes) * 60),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        /*
         * 🚨 A finished game's scores are updated; a game the provider has not
         * finished leaves whatever is stored alone. Blanking a score because
         * this particular response did not carry one is how a completed game
         * loses its result the next time the schedule is re-fetched.
         */
        if (!empty($values['completed'])
            && ($values['home_score'] ?? null) !== null
            && ($values['away_score'] ?? null) !== null) {
            $changed['home_score'] = max(0, (int) $values['home_score']);
            $changed['away_score'] = max(0, (int) $values['away_score']);
            $changed['status'] = self::FINISHED;
            $changed['result'] = $this->resultFrom($changed);
            $changed['confirmed_at'] = time();
        }

        if ($existing === null) {
            $id = (int) $this->db->table('picks_events')->insertGetId($changed + [
                'cfbd_id' => $cfbdId,
                'status' => $changed['status'] ?? self::SCHEDULED,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return [$id, true];
        }

        $this->db->table('picks_events')->where('id', $existing['id'])->updateAll($changed);

        return [(int) $existing['id'], false];
    }

    /**
     * Records a result an operator typed in.
     *
     * 🚨 Confirmed now, because somebody just looked at a scoreboard and told
     * us. That is exactly as good a confirmation as a provider's, and without
     * it a hand-entered final would render under "not known".
     */
    public function recordResult(int $id, ?int $home, ?int $away): bool
    {
        if ($this->db->table('picks_events')->where('id', $id)->first() === null) {
            return false;
        }

        if ($home === null || $away === null) {
            /*
             * Clearing a result. The status goes back to `scheduled` rather
             * than to `closed` so `closePassed()` can decide, and the picks
             * scored from it are reset by the caller — this method owns the
             * game and nothing else.
             */
            $this->db->table('picks_events')->where('id', $id)->updateAll([
                'home_score' => null,
                'away_score' => null,
                'result' => '',
                'status' => self::SCHEDULED,
                'confirmed_at' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return true;
        }

        $scores = ['home_score' => max(0, $home), 'away_score' => max(0, $away)];

        $this->db->table('picks_events')->where('id', $id)->updateAll($scores + [
            'result' => $this->resultFrom($scores),
            'status' => self::FINISHED,
            'confirmed_at' => time(),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * What a provider just said about a game.
     *
     * 🚨 Called only when something ANSWERED. Silence never reaches here, so an
     * outage cannot end a game or blank a score: the row is left exactly as it
     * was and its confirmation simply ages, which is what turns the front end's
     * answer into "not known" rather than into a lie.
     *
     * @return bool whether this call newly finished the game
     */
    public function recordFromSource(int $id, ?int $home, ?int $away, bool $completed, ?int $now = null): bool
    {
        $now ??= time();
        $game = $this->db->table('picks_events')->where('id', $id)->first();

        if ($game === null) {
            return false;
        }

        $wasFinished = (string) $game['status'] === self::FINISHED;

        $changed = ['confirmed_at' => $now, 'updated_at' => date('Y-m-d H:i:s')];

        if ($home !== null && $away !== null) {
            $changed['home_score'] = max(0, $home);
            $changed['away_score'] = max(0, $away);
        }

        if ($completed) {
            $changed['status'] = self::FINISHED;
            $changed['result'] = $this->resultFrom([
                'home_score' => $changed['home_score'] ?? $game['home_score'],
                'away_score' => $changed['away_score'] ?? $game['away_score'],
            ]);
        } elseif (!$wasFinished) {
            $changed['status'] = self::IN_PROGRESS;
        }

        $this->db->table('picks_events')->where('id', $id)->updateAll($changed);

        return $completed && !$wasFinished;
    }

    /**
     * Closes games whose cutoff has passed while they were still scheduled.
     *
     * 🚨 Housekeeping, not enforcement. `open()` already refuses a pick on a
     * game whose cutoff has gone, whatever the status column says, because a
     * deadline that only holds once a timer has run is not a deadline. This
     * exists so the admin listing reads truthfully between syncs.
     */
    public function closePassed(?int $now = null): int
    {
        return $this->db->table('picks_events')
            ->where('status', self::SCHEDULED)
            ->where('cutoff_at', '>', 0)
            ->where('cutoff_at', '<=', $now ?? time())
            ->updateAll(['status' => self::CLOSED, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Re-applies the lock offset to every game that has not started.
     *
     * 🚨 Run when an operator changes the offset. Without it the new rule would
     * apply only to fixtures synced afterwards, so a week already on the board
     * would keep locking on the old setting and no screen would say why.
     */
    public function reapplyOffset(int $offsetMinutes, ?int $now = null): int
    {
        $seconds = max(0, $offsetMinutes) * 60;

        return $this->db->table('picks_events')
            ->where('status', self::SCHEDULED)
            ->where('match_at', '>', $now ?? time())
            ->updateAll([
                'cutoff_at' => new Expression('GREATEST(0, `match_at` - ' . $seconds . ')'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /* -------------------------------------------------------------- pieces */

    private function baseQuery(): Builder
    {
        $events = $this->p('picks_events');
        $weeks = $this->p('picks_weeks');

        return $this->db->table('picks_events')
            ->select(
                $events . '.*',
                $weeks . '.is_open AS week_is_open',
                $weeks . '.name AS week_name',
                $weeks . '.season_id AS season_id'
            )
            ->leftJoin($weeks, $weeks . '.id', '=', $events . '.week_id');
    }

    private function filtered(int $weekId, string $status, string $search): Builder
    {
        $events = $this->p('picks_events');
        $query = $this->baseQuery();

        if ($weekId > 0) {
            $query->where($events . '.week_id', $weekId);
        }

        if (in_array($status, self::STATUSES, true)) {
            $query->where($events . '.status', $status);
        }

        if (trim($search) !== '') {
            /*
             * 🚨 Resolved to ids in memory and bound, rather than sent as a
             * LIKE against a joined table. It cannot be a join — see the note
             * at the top — and it should not be a LIKE anyway: the team list is
             * already loaded, and a `%` somebody typed would otherwise become a
             * wildcard and a table scan.
             */
            $ids = $this->teams->idsMatching($search);

            if ($ids === []) {
                $query->whereRaw('1 = 0');
            } else {
                $holes = implode(', ', array_fill(0, count($ids), '?'));

                $query->whereRaw(
                    '(' . $events . '.home_team_id IN (' . $holes . ') OR '
                    . $events . '.away_team_id IN (' . $holes . '))',
                    [...$ids, ...$ids]
                );
            }
        }

        return $query;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $rows): array
    {
        $teams = $this->teams->map();
        $blank = [
            'id' => 0, 'name' => '', 'abbreviation' => '', 'conference' => '',
            'logo_path' => '', 'logo_dark_path' => '', 'forum_id' => 0, 'forum_slug' => '',
        ];

        foreach ($rows as $i => $row) {
            $rows[$i]['id'] = (int) $row['id'];
            $rows[$i]['week_id'] = (int) $row['week_id'];
            $rows[$i]['season_id'] = (int) ($row['season_id'] ?? 0);
            $rows[$i]['week_name'] = (string) ($row['week_name'] ?? '');
            $rows[$i]['week_is_open'] = (int) ($row['week_is_open'] ?? 0);
            $rows[$i]['match_at'] = (int) $row['match_at'];
            $rows[$i]['cutoff_at'] = (int) $row['cutoff_at'];
            $rows[$i]['confirmed_at'] = (int) $row['confirmed_at'];
            $rows[$i]['status'] = (string) $row['status'];
            $rows[$i]['result'] = (string) $row['result'];
            $rows[$i]['home_score'] = $row['home_score'] === null ? null : (int) $row['home_score'];
            $rows[$i]['away_score'] = $row['away_score'] === null ? null : (int) $row['away_score'];
            $rows[$i]['neutral_site'] = (int) $row['neutral_site'] === 1;
            $rows[$i]['home'] = $teams[(int) $row['home_team_id']] ?? $blank;
            $rows[$i]['away'] = $teams[(int) $row['away_team_id']] ?? $blank;
        }

        return $rows;
    }

    private function p(string $table): string
    {
        return $this->db->prefixed($table);
    }
}
