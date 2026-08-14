<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;

/**
 * The picks themselves: making one, changing one, and who is allowed to see it.
 *
 * Two rules live here and both are enforced in this class rather than in a
 * template or a controller, because a rule that is only in a screen is a rule
 * that holds until somebody adds a second screen.
 *
 * 🚨 **The cutoff.** `submit()` and `withdraw()` refuse once `Games::open()`
 * says no, and they are handed a game row read from the database in the same
 * request — never a game id the caller has already reasoned about, and never
 * anything the browser said about whether the game was still open. A pick'em
 * where a member can set their own deadline is not a game.
 *
 * 🚨 **Who may see whose.** Before a game's cutoff, a member sees their own
 * pick and nobody else's; after it, everybody's are on show. This is the whole
 * competitive point — a pick everybody can read before kickoff is not a
 * prediction, it is a copy — and it is decided PER GAME, not per week, because
 * a Tuesday night game locks four days before a Saturday one in the same round.
 *
 * 🚨 Note what "before the cutoff" is measured against: `Games::locked()`, not
 * `!Games::open()`. A week nobody has opened yet has games that are not
 * pickable and whose cutoffs have not passed; reading that as "locked, so show
 * everyone" would publish the whole round early.
 */
final class Picks
{
    /** What `submit()` says when it refuses. Each maps to a lang key. */
    public const OK = '';
    public const NO_SUCH_GAME = 'no_such_game';
    public const CLOSED = 'closed';
    public const BAD_OUTCOME = 'bad_outcome';
    public const BAD_CONFIDENCE = 'bad_confidence';
    public const NOT_PICKED = 'not_picked';

    /** The range a confidence rating may take, inclusive. */
    public const CONFIDENCE_MIN = 1;
    public const CONFIDENCE_MAX = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly Games $games,
    ) {
    }

    /* -------------------------------------------------------------- making */

    /**
     * Enters or changes one member's pick.
     *
     * @param array<string, mixed> $game a row read from Games in this request
     * @return string self::OK, or the reason it was refused
     */
    public function submit(
        int $userId,
        array $game,
        string $outcome,
        ?int $confidence,
        bool $confidenceMode,
        ?int $now = null,
    ): string {
        if ($userId < 1 || (int) ($game['id'] ?? 0) < 1) {
            return self::NO_SUCH_GAME;
        }

        if (!in_array($outcome, Games::OUTCOMES, true)) {
            return self::BAD_OUTCOME;
        }

        /*
         * 🚨 The gate, against a row from the database rather than against
         * anything that came in with the request. Checked before the value is
         * validated and before anything is written, and it is the only thing
         * standing between this game and a member who has seen the first half.
         */
        if (!$this->games->open($game, $now)) {
            return self::CLOSED;
        }

        /*
         * 🚨 Confidence is IGNORED when the mode is off, rather than stored and
         * left dormant. A value kept from a period when nobody was told it
         * counted would start counting the moment an operator switched the mode
         * on, retroactively rescoring picks people made under other rules.
         */
        if (!$confidenceMode) {
            $confidence = null;
        } elseif ($confidence !== null) {
            if ($confidence < self::CONFIDENCE_MIN || $confidence > self::CONFIDENCE_MAX) {
                return self::BAD_CONFIDENCE;
            }
        }

        $gameId = (int) $game['id'];
        $existing = $this->db->table('picks_picks')
            ->where('user_id', $userId)
            ->where('event_id', $gameId)
            ->first();

        if ($existing === null) {
            /*
             * A duplicate here is a member who double-submitted, and the unique
             * index on (user_id, event_id) is what makes that harmless. The
             * insert is attempted and a collision falls through to the update,
             * rather than being prevented by a lock — the constraint is real,
             * so there is nothing to serialise.
             */
            try {
                $this->db->table('picks_picks')->insertGetId([
                    'user_id' => $userId,
                    'event_id' => $gameId,
                    'selected_outcome' => $outcome,
                    'confidence' => $confidence,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);

                return self::OK;
            } catch (\Throwable) {
                // Fall through and update the row the other request inserted.
            }
        }

        $changed = [
            'selected_outcome' => $outcome,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        /*
         * 🚨 A pick that changes has NOT been scored, because the game is still
         * open — but resetting `is_correct` anyway costs nothing and closes the
         * one gap a mis-entered result would otherwise leave: an operator who
         * clears a result and reopens a game must not leave a stale verdict on
         * a pick somebody then changes.
         */
        $changed['is_correct'] = null;

        if ($confidenceMode) {
            $changed['confidence'] = $confidence;
        }

        $this->db->table('picks_picks')
            ->where('user_id', $userId)
            ->where('event_id', $gameId)
            ->updateAll($changed);

        return self::OK;
    }

    /**
     * Takes a member's pick back.
     *
     * 🚨 Behind the same gate as making one. Withdrawing after the cutoff would
     * let somebody delete a losing pick at half time, which costs the game its
     * meaning just as surely as changing one would.
     *
     * @param array<string, mixed> $game
     */
    public function withdraw(int $userId, array $game, ?int $now = null): string
    {
        if ($userId < 1 || (int) ($game['id'] ?? 0) < 1) {
            return self::NO_SUCH_GAME;
        }

        if (!$this->games->open($game, $now)) {
            return self::CLOSED;
        }

        $removed = $this->db->table('picks_picks')
            ->where('user_id', $userId)
            ->where('event_id', (int) $game['id'])
            ->deleteAll();

        return $removed > 0 ? self::OK : self::NOT_PICKED;
    }

    /* ------------------------------------------------------------- reading */

    /**
     * One member's picks across a set of games, keyed by game id.
     *
     * @param list<int> $gameIds
     * @return array<int, array<string, mixed>>
     */
    public function mine(int $userId, array $gameIds): array
    {
        if ($userId < 1 || $gameIds === []) {
            return [];
        }

        $out = [];

        $rows = $this->db->table('picks_picks')
            ->where('user_id', $userId)
            ->whereIn('event_id', $gameIds)
            ->get();

        foreach ($rows as $row) {
            $out[(int) $row['event_id']] = $this->shape($row);
        }

        return $out;
    }

    /**
     * Everybody's picks on the games in this list whose cutoff has passed.
     *
     * 🚨 The `whereIn` is built from `Games::locked()` — the one rule — rather
     * than from a `cutoff_at <= ?` written a second time here. Two copies of a
     * disclosure rule is one copy that eventually disagrees, and the direction
     * it would disagree in is "shows everybody's picks early".
     *
     * 🚨 When no game has locked, this returns without querying at all. An
     * empty `whereIn` compiles to `IN ()`, which is a syntax error in MySQL —
     * and the shape of that bug would be a fatal on the one page that matters.
     *
     * @param list<array<string, mixed>> $games rows from Games
     * @return array<int, list<array<string, mixed>>> game id => picks
     */
    public function revealed(array $games, ?int $now = null): array
    {
        $now ??= time();
        $lockedIds = [];

        foreach ($games as $game) {
            if ($this->games->locked($game, $now)) {
                $lockedIds[] = (int) $game['id'];
            }
        }

        if ($lockedIds === []) {
            return [];
        }

        $picks = $this->p('picks_picks');
        $users = $this->p('users');

        $rows = $this->db->table('picks_picks')
            ->select(
                $picks . '.*',
                /*
                 * 🚨 `username` only. Convoro has no `display_name` column —
                 * that is Flarum's, and selecting it here was a hard SQL error
                 * the moment anybody's picks were revealed. The mapping below
                 * still reads `display_name` first so a future column would be
                 * picked up without another change here.
                 */
                $users . '.username AS username'
            )
            ->leftJoin($users, $users . '.id', '=', $picks . '.user_id')
            ->whereIn($picks . '.event_id', $lockedIds)
            ->orderBy($users . '.username')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $gameId = (int) $row['event_id'];
            $out[$gameId] ??= [];
            $out[$gameId][] = $this->shape($row) + [
                'username' => (string) ($row['username'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? $row['username'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Whether one member may see another's pick on this game.
     *
     * 🚨 Stated as a method so a test can hold it still. Every caller reaches
     * the same answer through `revealed()`; this exists so the rule can be
     * asserted directly rather than inferred from what a query happened to
     * return.
     *
     * @param array<string, mixed> $game
     */
    public function maySee(array $game, int $viewerId, int $pickUserId, ?int $now = null): bool
    {
        if ($viewerId > 0 && $viewerId === $pickUserId) {
            return true;
        }

        return $this->games->locked($game, $now);
    }

    /**
     * How many picked each side, for games whose cutoff has passed.
     *
     * 🚨 Under the same rule as the picks themselves, and for the same reason:
     * "78% are taking the home team" published before kickoff is the consensus
     * everybody then copies, which is the pick'em telling people the answer.
     *
     * @param list<array<string, mixed>> $games
     * @return array<int, array{home: int, away: int}>
     */
    public function tallies(array $games, ?int $now = null): array
    {
        $now ??= time();
        $lockedIds = [];

        foreach ($games as $game) {
            if ($this->games->locked($game, $now)) {
                $lockedIds[] = (int) $game['id'];
            }
        }

        if ($lockedIds === []) {
            return [];
        }

        $out = [];

        $rows = $this->db->table('picks_picks')
            ->select('event_id', 'selected_outcome', 'COUNT(*) AS tally')
            ->whereIn('event_id', $lockedIds)
            ->groupBy('event_id', 'selected_outcome')
            ->get();

        foreach ($rows as $row) {
            $gameId = (int) $row['event_id'];
            $out[$gameId] ??= ['home' => 0, 'away' => 0];
            $side = (string) $row['selected_outcome'];

            if ($side === Games::HOME || $side === Games::AWAY) {
                $out[$gameId][$side] = (int) $row['tally'];
            }
        }

        return $out;
    }

    /**
     * One member's scored picks, newest game first, for their own stats page.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $userId, int $limit = 50): array
    {
        if ($userId < 1) {
            return [];
        }

        $picks = $this->p('picks_picks');
        $events = $this->p('picks_events');

        $rows = $this->db->table('picks_picks')
            ->select(
                $picks . '.*',
                $events . '.home_team_id',
                $events . '.away_team_id',
                $events . '.home_score',
                $events . '.away_score',
                $events . '.status',
                $events . '.match_at',
                $events . '.result'
            )
            ->join($events, $events . '.id', '=', $picks . '.event_id')
            ->where($picks . '.user_id', $userId)
            ->orderByDesc($events . '.match_at')
            ->limit(max(1, min(200, $limit)))
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->shape($row) + [
                'home_team_id' => (int) $row['home_team_id'],
                'away_team_id' => (int) $row['away_team_id'],
                'home_score' => $row['home_score'] === null ? null : (int) $row['home_score'],
                'away_score' => $row['away_score'] === null ? null : (int) $row['away_score'],
                'status' => (string) $row['status'],
                'match_at' => (int) $row['match_at'],
                'result' => (string) $row['result'],
            ];
        }

        return $out;
    }

    /** How many members have entered a pick on any game in this week. */
    public function playersInWeek(int $weekId): int
    {
        $picks = $this->p('picks_picks');
        $events = $this->p('picks_events');

        return $this->db->table('picks_picks')
            ->join($events, $events . '.id', '=', $picks . '.event_id')
            ->where($events . '.week_id', $weekId)
            ->distinct()
            ->count($picks . '.user_id');
    }

    public function countInWeek(int $weekId): int
    {
        $picks = $this->p('picks_picks');
        $events = $this->p('picks_events');

        return $this->db->table('picks_picks')
            ->join($events, $events . '.id', '=', $picks . '.event_id')
            ->where($events . '.week_id', $weekId)
            ->count($picks . '.id');
    }

    /* ------------------------------------------------------------- scoring */

    /**
     * Marks every pick on a game right or wrong.
     *
     * 🚨 Two statements, not one per row. A popular game has hundreds of picks
     * and a loop of single writes is hundreds of round trips inside a queue job
     * that has other games to get to.
     *
     * 🚨 A result of '' — an undecided or cleared game — resets every pick to
     * unscored rather than marking them all wrong. That is the difference
     * between "we do not know yet" and "everybody lost".
     */
    public function score(int $gameId, string $result): void
    {
        if (!in_array($result, Games::OUTCOMES, true)) {
            $this->unscore($gameId);

            return;
        }

        $this->db->table('picks_picks')
            ->where('event_id', $gameId)
            ->where('selected_outcome', $result)
            ->updateAll(['is_correct' => 1, 'updated_at' => date('Y-m-d H:i:s')]);

        $this->db->table('picks_picks')
            ->where('event_id', $gameId)
            ->where('selected_outcome', '!=', $result)
            ->updateAll(['is_correct' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public function unscore(int $gameId): void
    {
        $this->db->table('picks_picks')
            ->where('event_id', $gameId)
            ->updateAll(['is_correct' => null, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Which members picked this game.
     *
     * @return list<int>
     */
    public function playersOn(int $gameId): array
    {
        return array_values(array_unique(array_map(
            'intval',
            $this->db->table('picks_picks')->where('event_id', $gameId)->pluck('user_id')
        )));
    }

    public function anyOn(int $gameId): bool
    {
        return $this->db->table('picks_picks')->where('event_id', $gameId)->exists();
    }

    /* -------------------------------------------------------------- pieces */

    /** @param array<string, mixed> $row */
    private function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'event_id' => (int) $row['event_id'],
            'selected_outcome' => (string) $row['selected_outcome'],

            // 🚨 Kept as null when unscored. Casting it to a bool here would
            // turn "not played yet" into "wrong" on every screen at once.
            'is_correct' => $row['is_correct'] === null ? null : ((int) $row['is_correct'] === 1),
            'confidence' => $row['confidence'] === null ? null : (int) $row['confidence'],
        ];
    }

    private function p(string $table): string
    {
        return $this->db->prefixed($table);
    }
}
