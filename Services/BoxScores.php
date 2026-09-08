<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;
use Convoro\Extensions\Picks\Services\Sources\Cfbd;

/**
 * What happened in a game, kept in a shape this system owns.
 *
 * 🚨 Picks fetches; everything else reads. Game Day's own comment says it
 * reaches nothing off the machine and reads what Picks has synced, and that
 * stays true with statistics in the picture — the provider relationship, the
 * API key and the monthly call budget all live here, in one place, and one
 * place is what makes the budget enforceable at all.
 *
 * 🚨 Fetched a WEEK at a time. A Saturday has sixty games on it and the
 * provider spends a monthly allowance per call, so asking per game is sixty
 * calls for what one call answers. Two calls cover a whole week: the team box
 * score and the player box score.
 */
final class BoxScores
{
    /**
     * How long after a game finishes to keep trying for its box score.
     *
     * 🚨 There is a gap between a final score and a published box score —
     * minutes usually, sometimes longer — so the first pass after a game often
     * finds nothing, and giving up immediately would mean never having one.
     * Two days is generous enough to survive a scheduler that was off for a
     * night, and short enough that a game the provider simply never covered
     * stops being asked about.
     */
    public const KEEP_TRYING_HOURS = 48;

    /** Weeks fetched in one pass, so a backfill cannot run long. */
    private const WEEKS_PER_PASS = 2;

    public function __construct(
        private Connection $db,
        private Cfbd $cfbd,
        private Settings $settings,
    ) {
    }

    /**
     * Fetches box scores for finished games that have none yet.
     *
     * @return array{fetched: int, weeks: int, error: string}
     */
    public function sync(?int $now = null): array
    {
        $now ??= time();

        if (!$this->cfbd->configured()) {
            return ['fetched' => 0, 'weeks' => 0, 'error' => 'unconfigured'];
        }

        $weeks = $this->weeksNeeding($now);

        if ($weeks === []) {
            return ['fetched' => 0, 'weeks' => 0, 'error' => ''];
        }

        $fetched = 0;
        $error = '';

        foreach (array_slice($weeks, 0, self::WEEKS_PER_PASS) as $week) {
            [$count, $problem] = $this->fetchWeek($week, $now);
            $fetched += $count;

            if ($problem !== '') {
                /*
                 * 🚨 Stops at the first refusal rather than working through the
                 * rest. A spent budget or a rejected key applies to every call
                 * that would follow, and asking again is how an outage becomes
                 * an outage plus a wasted allowance.
                 */
                $error = $problem;
                break;
            }
        }

        return ['fetched' => $fetched, 'weeks' => count($weeks), 'error' => $error];
    }

    /**
     * The normalised box score for a game, or null when there is none.
     *
     * @return array<string, mixed>|null
     */
    public function forEvent(int $eventId): ?array
    {
        $row = $this->db->table('picks_box_scores')->where('event_id', $eventId)->first();

        if ($row === null) {
            return null;
        }

        $document = json_decode((string) $row['payload'], true);

        if (!is_array($document)) {
            return null;
        }

        // The row's own timestamp rather than one inside the document, so a
        // screen saying how old this is cannot disagree with the table.
        $document['fetched_at'] = (string) $row['fetched_at'];

        return $document;
    }

    public function has(int $eventId): bool
    {
        return $this->db->table('picks_box_scores')->where('event_id', $eventId)->exists();
    }

    /* ------------------------------------------------------------- fetching */

    /**
     * @param array{season_id: int, week_id: int, year: int, week_number: int, season_type: string} $week
     * @return array{0: int, 1: string}
     */
    private function fetchWeek(array $week, int $now): array
    {
        [$teams, $error] = $this->cfbd->teamBoxScores(
            $week['year'],
            $week['season_type'],
            $week['week_number'],
        );

        if ($error !== '') {
            return [0, $error];
        }

        if ($teams === []) {
            // Answered, and had nothing. Not a failure — the provider simply
            // has not published this week's box scores yet.
            return [0, ''];
        }

        [$players, $playerError] = $this->cfbd->playerBoxScores(
            $week['year'],
            $week['season_type'],
            $week['week_number'],
        );

        if ($playerError !== '') {
            /*
             * 🚨 The team half is NOT stored on its own. A box score that
             * arrives without its leaders would satisfy `has()`, and nothing
             * would ever come back for the rest of it — a half-written row is
             * how a gap becomes permanent.
             */
            return [0, $playerError];
        }

        $stored = 0;

        foreach ($this->finishedInWeek((int) $week['week_id'], $now) as $event) {
            $gameId = (int) $event['cfbd_id'];

            if (!isset($teams[$gameId])) {
                continue;
            }

            $document = $this->normalise($gameId, $teams[$gameId], $players[$gameId] ?? []);

            if ($document === null) {
                continue;
            }

            $this->store((int) $event['id'], $document);
            $stored++;
        }

        return [$stored, ''];
    }

    /**
     * Weeks holding a finished game that still wants a box score.
     *
     * @return list<array{season_id: int, week_id: int, year: int, week_number: int, season_type: string}>
     */
    private function weeksNeeding(int $now): array
    {
        $events = $this->db->prefixed('picks_events');
        $weeks = $this->db->prefixed('picks_weeks');
        $seasons = $this->db->prefixed('picks_seasons');
        $box = $this->db->prefixed('picks_box_scores');

        $rows = $this->db->select(
            "SELECT DISTINCT w.`id` AS week_id, w.`week_number`, w.`season_type`,
                    s.`id` AS season_id, s.`year`
               FROM `{$events}` e
               JOIN `{$weeks}` w ON w.`id` = e.`week_id`
               JOIN `{$seasons}` s ON s.`id` = w.`season_id`
          LEFT JOIN `{$box}` b ON b.`event_id` = e.`id`
              WHERE e.`status` = 'finished'
                AND e.`cfbd_id` > 0
                AND b.`id` IS NULL
                AND e.`match_at` > ?
           ORDER BY w.`week_number` DESC",
            [$now - (self::KEEP_TRYING_HOURS * 3600)]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'season_id' => (int) $row['season_id'],
                'week_id' => (int) $row['week_id'],
                'year' => (int) $row['year'],
                'week_number' => (int) $row['week_number'],
                'season_type' => (string) $row['season_type'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function finishedInWeek(int $weekId, int $now): array
    {
        return $this->db->table('picks_events')
            ->where('week_id', $weekId)
            ->where('status', 'finished')
            ->where('match_at', '>', $now - (self::KEEP_TRYING_HOURS * 3600))
            ->get(['id', 'cfbd_id', 'home_team_id', 'away_team_id']);
    }

    /** @param array<string, mixed> $document */
    private function store(int $eventId, array $document): void
    {
        $now = date('Y-m-d H:i:s');
        $table = $this->db->prefixed('picks_box_scores');

        $this->db->query(
            "INSERT INTO `{$table}` (`event_id`, `payload`, `fetched_at`, `created_at`, `updated_at`)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                `payload` = VALUES(`payload`),
                `fetched_at` = VALUES(`fetched_at`),
                `updated_at` = VALUES(`updated_at`)",
            [$eventId, json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $now, $now, $now]
        );
    }

    /* ---------------------------------------------------------- normalising */

    /**
     * The provider's two answers, turned into one document of our own shape.
     *
     * @param list<array<string, mixed>> $teamSides
     * @param list<array<string, mixed>> $playerSides
     * @return array<string, mixed>|null
     */
    public function normalise(int $gameId, array $teamSides, array $playerSides): ?array
    {
        $document = ['game' => $gameId];

        foreach ($teamSides as $side) {
            $where = ($side['homeAway'] ?? '') === 'away' ? 'away' : 'home';

            $document[$where] = [
                'team' => (string) ($side['team'] ?? ''),
                'points' => isset($side['points']) ? (int) $side['points'] : null,
                'stats' => $this->teamStats($side['stats'] ?? []),
                'leaders' => [],
            ];
        }

        // Both halves or nothing — see fetchWeek().
        if (!isset($document['home'], $document['away'])) {
            return null;
        }

        foreach ($playerSides as $side) {
            $where = ($side['homeAway'] ?? '') === 'away' ? 'away' : 'home';

            if (!isset($document[$where])) {
                continue;
            }

            $document[$where]['leaders'] = $this->leaders($side['categories'] ?? []);
        }

        return $document;
    }

    /**
     * `[{category, stat}]` as a map.
     *
     * 🚨 Values stay STRINGS. The feed mixes counts ("20"), ratios ("3-9"),
     * averages ("8.2") and clock times ("31:36") in one list, and a numeric cast
     * turns three of those four into a wrong number rather than an error —
     * `31:36` becomes 31, which reads as a plausible possession figure and is
     * not one. Whoever wants a number asks for one.
     *
     * @param mixed $stats
     * @return array<string, string>
     */
    private function teamStats(mixed $stats): array
    {
        $out = [];

        foreach (is_array($stats) ? $stats : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $category = (string) ($entry['category'] ?? '');

            if ($category === '') {
                continue;
            }

            $out[$category] = (string) ($entry['stat'] ?? '');
        }

        return $out;
    }

    /**
     * The one player worth naming in each category.
     *
     * 🚨 The provider's shape is inside out for this: a category holds a list of
     * TYPES, and each type holds every athlete's figure for it — so a
     * quarterback's line is scattered across five lists rather than sitting
     * together. It is pivoted here, once, into a player per category with their
     * own figures, because every reader would otherwise pivot it again.
     *
     * The leader is picked on yards, or tackles for a defence — the figure the
     * category is about — and ties go to whoever the feed listed first, which is
     * the order it considers most notable.
     *
     * @param mixed $categories
     * @return array<string, array{name: string, stats: array<string, string>}>
     */
    private function leaders(mixed $categories): array
    {
        $decidedBy = [
            'passing' => 'YDS',
            'rushing' => 'YDS',
            'receiving' => 'YDS',
            'defensive' => 'TOT',
        ];

        $out = [];

        foreach (is_array($categories) ? $categories : [] as $category) {
            if (!is_array($category)) {
                continue;
            }

            $name = (string) ($category['name'] ?? '');

            if (!isset($decidedBy[$name])) {
                continue;
            }

            $players = [];
            $order = [];

            foreach ((array) ($category['types'] ?? []) as $type) {
                if (!is_array($type)) {
                    continue;
                }

                $figure = (string) ($type['name'] ?? '');

                foreach ((array) ($type['athletes'] ?? []) as $athlete) {
                    if (!is_array($athlete)) {
                        continue;
                    }

                    $who = (string) ($athlete['name'] ?? '');

                    if ($who === '') {
                        continue;
                    }

                    $players[$who][$figure] = (string) ($athlete['stat'] ?? '');
                    $order[$who] ??= count($order);
                }
            }

            if ($players === []) {
                continue;
            }

            $best = null;
            $bestScore = null;

            foreach ($players as $who => $figures) {
                $score = (float) ($figures[$decidedBy[$name]] ?? 0);

                if ($bestScore === null
                    || $score > $bestScore
                    || ($score === $bestScore && $order[$who] < $order[$best])
                ) {
                    $best = $who;
                    $bestScore = $score;
                }
            }

            $out[$name] = ['name' => (string) $best, 'stats' => $players[$best]];
        }

        return $out;
    }
}
