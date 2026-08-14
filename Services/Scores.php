<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;

/**
 * What a pick is worth, and where that puts somebody on the board.
 *
 * 🚨 THE SCORING RULES, in one place, because they are the product.
 *
 * **Plain mode.** One point per correct pick. `total_points = correct_picks`.
 *
 * **Confidence mode.** A member rates each pick 1–10.
 *   - earned  = SUM(confidence) over their CORRECT picks, a missing rating
 *               counting as 1 — so somebody who never used the slider still
 *               scores exactly what plain mode would have given them.
 *   - penalty = over their INCORRECT picks, and it depends on the setting:
 *       `full` → SUM(confidence)
 *       `half` → SUM(FLOOR(confidence / 2))   — rounded DOWN, in SQL
 *       `none` → 0
 *   - total_points = max(0, earned − penalty). 🚨 Clamped at zero: a
 *     leaderboard with negative numbers on it reads as broken, and a member who
 *     had a bad week should be last rather than in a hole they cannot climb out
 *     of.
 *
 * **Both modes.** `accuracy = round(correct / total × 100, 2)`, over picks that
 * have been SCORED — a pick on a game nobody has played yet counts towards
 * neither, so an active member is not punished for having entered early.
 *
 * **Three scopes**, written by the same upsert called three times: this week,
 * this season, and all time.
 *
 * 🚨 Aggregated IN SQL and never by loading rows into PHP. A member with a few
 * seasons behind them has hundreds of thousands of scored picks, and the
 * all-time scope touches every one of them. Materialising that collection to
 * sum four numbers is the difference between a query and an out-of-memory
 * fatal in a queue worker.
 *
 * 🚨 The scope columns are 0, never NULL — see the migration. That is what
 * makes the unique index cover the all-time row, which is what removes the
 * lock the Flarum original needed to stop concurrent passes inserting it twice.
 */
final class Scores
{
    public function __construct(
        private readonly Connection $db,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Recalculates one member's week, season and all-time totals.
     *
     * 🚨 Idempotent, and it has to be: this runs from the queue, which runs
     * things twice more often than anybody expects. Every figure is derived
     * from the picks table rather than added to what was there, so a second
     * pass writes the same numbers.
     */
    public function recalculate(int $userId, int $weekId, int $seasonId): void
    {
        if ($userId < 1) {
            return;
        }

        if ($weekId > 0 && $seasonId > 0) {
            $this->upsert($userId, $seasonId, $weekId);
        }

        if ($seasonId > 0) {
            $this->upsert($userId, $seasonId, 0);
        }

        $this->upsert($userId, 0, 0);
    }

    /**
     * One scope's row for one member.
     *
     * @param int $seasonId 0 for all time
     * @param int $weekId   0 for a whole season or all time
     */
    public function upsert(int $userId, int $seasonId, int $weekId): void
    {
        $totals = $this->aggregate($userId, $seasonId, $weekId);

        $accuracy = $totals['total'] > 0
            ? round($totals['correct'] / $totals['total'] * 100, 2)
            : 0.0;

        $values = [
            'total_picks' => $totals['total'],
            'correct_picks' => $totals['correct'],
            'total_points' => $totals['points'],
            'accuracy' => $accuracy,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $written = $this->db->table('picks_user_scores')
            ->where('user_id', $userId)
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->updateAll($values);

        if ($written > 0) {
            return;
        }

        /*
         * 🚨 `updateAll()` returning 0 means either "no such row" or "the row
         * was already exactly this", so the insert is attempted and a
         * duplicate-key collision is caught rather than pre-checked. The unique
         * index is the authority; a `SELECT … then INSERT` would have a gap
         * between the two that two queue workers can both walk through.
         */
        try {
            $this->db->table('picks_user_scores')->insertGetId($values + [
                'user_id' => $userId,
                'season_id' => $seasonId,
                'week_id' => $weekId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            $this->db->table('picks_user_scores')
                ->where('user_id', $userId)
                ->where('season_id', $seasonId)
                ->where('week_id', $weekId)
                ->updateAll($values);
        }
    }

    /**
     * The four numbers, computed by the database.
     *
     * @return array{total: int, correct: int, points: int}
     */
    public function aggregate(int $userId, int $seasonId, int $weekId): array
    {
        $picks = $this->p('picks_picks');
        $confidenceMode = $this->settings->confidenceMode();

        $columns = [
            'COUNT(*) AS agg_total',
            'SUM(CASE WHEN ' . $picks . '.`is_correct` = 1 THEN 1 ELSE 0 END) AS agg_correct',
        ];

        if ($confidenceMode) {
            /*
             * 🚨 COALESCE(confidence, 1) on the EARNED side and
             * COALESCE(confidence, 0) on the penalty side, and the difference
             * is deliberate. A member who never touched the slider should score
             * one point for a correct pick — exactly what plain mode gives —
             * and lose nothing at all for a wrong one, because they never
             * staked anything on it.
             */
            $columns[] = 'SUM(CASE WHEN ' . $picks . '.`is_correct` = 1 '
                . 'THEN COALESCE(' . $picks . '.`confidence`, 1) ELSE 0 END) AS agg_earned';

            $columns[] = match ($this->settings->confidencePenalty()) {
                'full' => 'SUM(CASE WHEN ' . $picks . '.`is_correct` = 0 '
                    . 'THEN COALESCE(' . $picks . '.`confidence`, 0) ELSE 0 END) AS agg_penalty',

                // FLOOR in SQL rather than in PHP, so the sum of halves is the
                // sum of the rounded halves — rounding after summing gives a
                // different, larger, and wrong penalty.
                'half' => 'SUM(CASE WHEN ' . $picks . '.`is_correct` = 0 '
                    . 'THEN FLOOR(COALESCE(' . $picks . '.`confidence`, 0) / 2) ELSE 0 END) AS agg_penalty',

                default => '0 AS agg_penalty',
            };
        }

        $query = $this->db->table('picks_picks')
            ->select(...$columns)
            ->where($picks . '.user_id', $userId)

            // 🚨 Scored picks only. A pick on a game nobody has played is
            // neither right nor wrong and must not drag an accuracy down.
            ->whereNotNull($picks . '.is_correct');

        $this->scope($query, $seasonId, $weekId);

        $row = $query->first() ?? [];

        $total = (int) ($row['agg_total'] ?? 0);
        $correct = (int) ($row['agg_correct'] ?? 0);

        if (!$confidenceMode) {
            return ['total' => $total, 'correct' => $correct, 'points' => $correct];
        }

        $points = (int) ($row['agg_earned'] ?? 0) - (int) ($row['agg_penalty'] ?? 0);

        return ['total' => $total, 'correct' => $correct, 'points' => max(0, $points)];
    }

    /* --------------------------------------------------------------- board */

    /**
     * The leaderboard for one scope.
     *
     * @return list<array<string, mixed>>
     */
    public function board(int $seasonId, int $weekId, int $limit = 25): array
    {
        $scores = $this->p('picks_user_scores');
        $users = $this->p('users');

        $rows = $this->db->table('picks_user_scores')
            ->select(
                $scores . '.*',
                /*
                 * 🚨 `username` only. Convoro has no `display_name` column —
                 * that is Flarum's, and selecting it here was a hard SQL error
                 * the moment anybody's picks were revealed. The mapping below
                 * still reads `display_name` first so a future column would be
                 * picked up without another change here.
                 */
                $users . '.username AS username'
            )
            ->leftJoin($users, $users . '.id', '=', $scores . '.user_id')
            ->where($scores . '.season_id', $seasonId)
            ->where($scores . '.week_id', $weekId)
            ->where($scores . '.total_picks', '>', 0)

            // 🚨 Correct picks break a tie on points, which matters in
            // confidence mode: two members on 30 are not equal if one of them
            // got there on six right answers and the other on twelve.
            ->orderByDesc($scores . '.total_points')
            ->orderByDesc($scores . '.correct_picks')
            ->orderBy($users . '.username')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $out = [];
        $rank = 0;

        foreach ($rows as $row) {
            $rank++;
            $previous = (int) $row['previous_rank'];

            $out[] = $this->shape($row) + [
                'rank' => $rank,
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? $row['username'] ?? ''),

                /*
                 * 🚨 null, not 0, when there is no earlier rank to compare to.
                 * A new entrant has not "held station" — nobody knows where
                 * they were — and an arrow saying they did is a fact invented
                 * out of a default value.
                 */
                'movement' => $previous > 0 ? $previous - $rank : null,
            ];
        }

        return $out;
    }

    /**
     * One member's row in one scope, with their rank and the field size.
     *
     * @return array<string, mixed>|null
     */
    public function standing(int $userId, int $seasonId, int $weekId): ?array
    {
        $row = $this->db->table('picks_user_scores')
            ->where('user_id', $userId)
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->first();

        if ($row === null || (int) $row['total_picks'] < 1) {
            return null;
        }

        $shaped = $this->shape($row);

        /*
         * 🚨 Rank is COUNTED, not read from `current_rank`. That column is
         * written by the scoring pass and is a snapshot; this is asked the
         * moment somebody looks at their own page, and between those two things
         * an operator may have entered a result by hand.
         */
        $ahead = $this->db->table('picks_user_scores')
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->where('total_picks', '>', 0)
            ->where('total_points', '>', $shaped['total_points'])
            ->count();

        $shaped['rank'] = $ahead + 1;
        $shaped['players'] = $this->players($seasonId, $weekId);

        return $shaped;
    }

    public function players(int $seasonId, int $weekId): int
    {
        return $this->db->table('picks_user_scores')
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->where('total_picks', '>', 0)
            ->count();
    }

    /**
     * Re-ranks a scope and records where everybody moved from.
     *
     * 🚨 The prior pass's rank rolls into `previous_rank` and this pass's
     * becomes `current_rank`. The Flarum original wrote the FIRST-EVER rank
     * into `previous_rank` and never moved it, so by November every arrow was
     * measuring against week one.
     *
     * 🚨 One statement per chunk, not one per member. A CASE update over the
     * ids that actually moved keeps a 200-player week to a single round trip
     * per scope; core's query builder has no upsert, so this is written out.
     */
    public function reRank(int $seasonId, int $weekId): void
    {
        $rows = $this->db->table('picks_user_scores')
            ->select('id', 'previous_rank', 'current_rank')
            ->where('season_id', $seasonId)
            ->where('week_id', $weekId)
            ->where('total_picks', '>', 0)
            ->orderByDesc('total_points')
            ->orderByDesc('correct_picks')
            ->orderBy('id')
            ->get();

        $moved = [];
        $rank = 0;

        foreach ($rows as $row) {
            $rank++;
            $current = (int) $row['current_rank'];

            // First time anybody has ranked this row: it moved from where it
            // is, so no arrow appears until the pass after this one.
            $previous = $current > 0 ? $current : $rank;

            if ($current === $rank && (int) $row['previous_rank'] === $previous) {
                continue;
            }

            $moved[] = ['id' => (int) $row['id'], 'previous' => $previous, 'current' => $rank];
        }

        foreach (array_chunk($moved, 400) as $chunk) {
            $this->writeRanks($chunk);
        }
    }

    /**
     * Removes every score row, for a reset.
     *
     * 🚨 Separate from clearing the picks, and the caller decides. Somebody
     * changing the confidence penalty wants totals recomputed, not a season of
     * members' picks deleted.
     */
    public function clear(): void
    {
        $this->db->table('picks_user_scores')->deleteAll();
    }

    /**
     * Recomputes every scope this member already has a row in, plus all time.
     *
     * 🚨 The scopes are read from what is stored rather than assumed to be the
     * current week and season. This runs after an operator changes confidence
     * mode or the penalty — settings that change what every stored pick is
     * WORTH — and recomputing only the current week would leave three seasons
     * of history scored under a rule nobody uses any more.
     */
    public function recalculateEverything(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $scopes = $this->db->table('picks_user_scores')
            ->select('season_id', 'week_id')
            ->where('user_id', $userId)
            ->get();

        foreach ($scopes as $scope) {
            $this->upsert($userId, (int) $scope['season_id'], (int) $scope['week_id']);
        }

        // Always, even for a member who somehow has no rows: it is the scope
        // every member belongs to and the one the front page reads first.
        $this->upsert($userId, 0, 0);
    }

    /**
     * The next batch of members who have picked one of these games.
     *
     * 🚨 A cursor rather than a list carried in a job payload. The alternative
     * — pushing every affected member id into the queue row — is a payload that
     * grows with the site and eventually exceeds the column it is stored in,
     * which fails as a job that silently never runs.
     *
     * @param list<int> $gameIds empty means every member who has ever picked
     * @return list<int> ascending, so the last one is the next cursor
     */
    public function playersAfter(int $afterUserId, array $gameIds, int $limit): array
    {
        $query = $this->db->table('picks_picks')
            ->distinct()
            ->select('user_id')
            ->where('user_id', '>', max(0, $afterUserId))
            ->orderBy('user_id')
            ->limit(max(1, $limit));

        if ($gameIds !== []) {
            $query->whereIn('event_id', $gameIds);
        }

        return array_map('intval', array_column($query->get(), 'user_id'));
    }

    /* -------------------------------------------------------------- pieces */

    /**
     * Narrows an aggregate to one week or one season.
     *
     * 🚨 The season scope joins through `picks_weeks` rather than trusting a
     * season id stored on the pick, because there isn't one — a pick knows its
     * game, a game knows its week, and a week knows its season. Denormalising
     * that would be a fourth place for a re-scheduled game to be wrong.
     */
    private function scope(\Convoro\Engine\Database\Query\Builder $query, int $seasonId, int $weekId): void
    {
        $picks = $this->p('picks_picks');
        $events = $this->p('picks_events');
        $weeks = $this->p('picks_weeks');

        if ($weekId > 0) {
            $query->join($events, $events . '.id', '=', $picks . '.event_id')
                ->where($events . '.week_id', $weekId);

            return;
        }

        if ($seasonId > 0) {
            $query->join($events, $events . '.id', '=', $picks . '.event_id')
                ->join($weeks, $weeks . '.id', '=', $events . '.week_id')
                ->where($weeks . '.season_id', $seasonId);
        }
    }

    /** @param list<array{id: int, previous: int, current: int}> $chunk */
    private function writeRanks(array $chunk): void
    {
        if ($chunk === []) {
            return;
        }

        $table = $this->p('picks_user_scores');
        $previousCases = '';
        $currentCases = '';
        $ids = [];
        $bindings = [];

        foreach ($chunk as $row) {
            $previousCases .= ' WHEN ? THEN ?';
            $currentCases .= ' WHEN ? THEN ?';
            $ids[] = '?';
        }

        foreach ($chunk as $row) {
            $bindings[] = $row['id'];
            $bindings[] = $row['previous'];
        }

        foreach ($chunk as $row) {
            $bindings[] = $row['id'];
            $bindings[] = $row['current'];
        }

        foreach ($chunk as $row) {
            $bindings[] = $row['id'];
        }

        $this->db->query(
            'UPDATE `' . $table . '` SET '
            . '`previous_rank` = CASE `id`' . $previousCases . ' END, '
            . '`current_rank` = CASE `id`' . $currentCases . ' END '
            . 'WHERE `id` IN (' . implode(', ', $ids) . ')',
            $bindings
        );
    }

    /** @param array<string, mixed> $row */
    private function shape(array $row): array
    {
        return [
            'user_id' => (int) $row['user_id'],
            'season_id' => (int) $row['season_id'],
            'week_id' => (int) $row['week_id'],
            'total_points' => (int) $row['total_points'],
            'total_picks' => (int) $row['total_picks'],
            'correct_picks' => (int) $row['correct_picks'],
            'accuracy' => (float) $row['accuracy'],
            'previous_rank' => (int) $row['previous_rank'],
            'current_rank' => (int) $row['current_rank'],
        ];
    }

    private function p(string $table): string
    {
        return $this->db->prefixed($table);
    }
}
