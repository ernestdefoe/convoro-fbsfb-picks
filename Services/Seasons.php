<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;
use Convoro\Extensions\Picks\Services\Leagues\Leagues;

/**
 * Seasons and the weeks inside them, and the one question the front page asks:
 * which week is this?
 *
 * 🚨 "Current" is defined once, here, in `currentWeek()`. The Flarum original
 * had this two-step query copy-pasted as raw SQL into five controllers, which
 * is five places for the definition to drift and no way to tell which one a
 * given screen used.
 */
final class Seasons
{
    public const REGULAR = 'regular';
    public const POSTSEASON = 'postseason';

    public function __construct(private readonly Connection $db)
    {
    }

    /* ------------------------------------------------------------- seasons */

    /** @return list<array<string, mixed>> newest first */
    public function all(): array
    {
        return $this->db->table('picks_seasons')->orderByDesc('year')->get();
    }

    /** @return array<string, mixed>|null */
    public function season(int $id): ?array
    {
        return $id < 1 ? null : $this->db->table('picks_seasons')->where('id', $id)->first();
    }

    /**
     * Finds or creates the season for a year IN A LEAGUE, and returns its id.
     *
     * 🚨 The league is part of the identity, not a detail on the row. The NFL
     * and the Premier League both run in 2026, and looking a season up by year
     * alone would hand the football sync the football season and quietly file
     * every fixture in it.
     */
    public function seasonForYear(int $year, string $league = Leagues::DEFAULT): int
    {
        $existing = $this->db->table('picks_seasons')
            ->where('year', $year)
            ->where('league', $league)
            ->first();

        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $definition = (new Leagues())->get($league);

        return (int) $this->db->table('picks_seasons')->insertGetId([
            /*
             * 🚨 The league is in the NAME and the SLUG as well as the column.
             * Two seasons called "2026 Season" on one screen are indistinguishable,
             * and `slug` is unique — the second league's season would be refused
             * outright.
             */
            'name' => $league === Leagues::DEFAULT
                ? $year . ' Season'
                : $definition->name . ' ' . $year,
            'slug' => $league === Leagues::DEFAULT
                ? $year . '-season'
                : $league . '-' . $year,
            'year' => $year,
            'league' => $league,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Whether any season is on a league CollegeFootballData answers for. */
    public function anyOnCfbd(): bool
    {
        $leagues = new Leagues();

        foreach ($this->db->table('picks_seasons')->get(['league']) as $row) {
            if ($leagues->get($row['league'] ?? null)->provider === 'cfbd') {
                return true;
            }
        }

        /*
         * 🚨 True when there are no seasons at all. A fresh install has nothing
         * to go on, and the default league is college football — so the key is
         * still the thing that install needs next.
         */
        return $this->db->table('picks_seasons')->count() === 0;
    }

    /** @return list<array<string, mixed>> the seasons on ESPN-backed leagues */
    public function onEspn(): array
    {
        $leagues = new Leagues();

        return array_values(array_filter(
            $this->all(),
            static fn (array $season): bool => $leagues->get($season['league'] ?? null)->provider === 'espn'
        ));
    }

    /* --------------------------------------------------------------- weeks */

    /**
     * Every week in a season, in the order they are played.
     *
     * 🚨 Regular-season weeks rank ahead of postseason ones within a season,
     * which an ordinary sort by `week_number` gets backwards: bowl season is
     * stored as week 1 of its own type, so it would otherwise lead the list.
     *
     * @return list<array<string, mixed>>
     */
    public function weeks(int $seasonId): array
    {
        return array_map(
            [$this, 'shapeWeek'],
            $this->db->table('picks_weeks')
                ->where('season_id', $seasonId)
                ->orderByRaw("CASE `season_type` WHEN 'regular' THEN 0 ELSE 1 END")
                ->orderBy('week_number')
                ->get()
        );
    }

    /** @return array<string, mixed>|null */
    public function week(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table('picks_weeks')->where('id', $id)->first();

        return $row === null ? null : $this->shapeWeek($row);
    }

    /**
     * The week the front page should show.
     *
     * 🚨 Two steps, and the order matters. The most recent season that still
     * has an unplayed game comes first, then the earliest unplayed week inside
     * it — so a schedule already synced for NEXT season cannot jump ahead of
     * the week actually being played this weekend.
     *
     * 🚨 Falls back to the last week that has games rather than to nothing, so
     * the page still shows the final standings in the closed season instead of
     * an empty screen from January until the next sync.
     *
     * @return array<string, mixed>|null
     */
    public function currentWeek(): ?array
    {
        $weeks = $this->p('picks_weeks');
        $events = $this->p('picks_events');
        $seasons = $this->p('picks_seasons');

        $unplayed = $this->db->table('picks_weeks')
            ->select($weeks . '.id')
            ->join($events, $events . '.week_id', '=', $weeks . '.id')
            ->join($seasons, $seasons . '.id', '=', $weeks . '.season_id')
            ->where($events . '.status', '!=', Games::FINISHED)
            ->orderByDesc($seasons . '.year')
            ->orderByRaw('CASE ' . $weeks . ".`season_type` WHEN 'regular' THEN 0 ELSE 1 END")
            ->orderBy($weeks . '.week_number')
            ->limit(1)
            ->first();

        if ($unplayed !== null) {
            return $this->week((int) $unplayed['id']);
        }

        $last = $this->db->table('picks_weeks')
            ->select($weeks . '.id')
            ->join($events, $events . '.week_id', '=', $weeks . '.id')
            ->orderByDesc($events . '.match_at')
            ->limit(1)
            ->first();

        return $last === null ? null : $this->week((int) $last['id']);
    }

    /**
     * Finds or creates one week, and updates its dates.
     *
     * 🚨 `is_open` is never touched here. The sync runs on a timer and an
     * operator's decision to open or close a round must survive it — a sync
     * that reset the switch would reopen a week that was deliberately closed,
     * some minutes after it was closed, with nothing on any screen to say so.
     */
    public function upsertWeek(
        int $seasonId,
        int $weekNumber,
        string $seasonType,
        string $name,
        ?string $startDate,
        ?string $endDate,
    ): int {
        $seasonType = $seasonType === self::POSTSEASON ? self::POSTSEASON : self::REGULAR;

        $existing = $this->db->table('picks_weeks')
            ->where('season_id', $seasonId)
            ->where('season_type', $seasonType)
            ->where('week_number', $weekNumber)
            ->first();

        $dates = [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($existing !== null) {
            $this->db->table('picks_weeks')->where('id', $existing['id'])->updateAll($dates);

            return (int) $existing['id'];
        }

        return (int) $this->db->table('picks_weeks')->insertGetId($dates + [
            'season_id' => $seasonId,
            'season_type' => $seasonType,
            'week_number' => $weekNumber,
            'name' => mb_substr($name, 0, 100),
            'is_open' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function setOpen(int $weekId, bool $open): void
    {
        $this->db->table('picks_weeks')->where('id', $weekId)->updateAll([
            'is_open' => $open ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function rename(int $weekId, string $name): void
    {
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->db->table('picks_weeks')->where('id', $weekId)->updateAll([
            'name' => mb_substr($name, 0, 100),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * The week after this one, in playing order.
     *
     * Rolls from the last regular week into the postseason, because that is
     * what "next" means to somebody looking at the board in December.
     *
     * @return array<string, mixed>|null
     */
    public function nextWeek(int $weekId): ?array
    {
        $week = $this->week($weekId);

        if ($week === null) {
            return null;
        }

        $next = $this->db->table('picks_weeks')
            ->where('season_id', $week['season_id'])
            ->where('season_type', $week['season_type'])
            ->where('week_number', '>', $week['week_number'])
            ->orderBy('week_number')
            ->first();

        if ($next === null && $week['season_type'] === self::REGULAR) {
            $next = $this->db->table('picks_weeks')
                ->where('season_id', $week['season_id'])
                ->where('season_type', self::POSTSEASON)
                ->orderBy('week_number')
                ->first();
        }

        return $next === null ? null : $this->shapeWeek($next);
    }

    /**
     * Every week that has been opened, newest first.
     *
     * 🚨 What the front page's week picker offers. A week nobody opened is not
     * on it — the board is the operator's to reveal, and a member browsing
     * ahead into an unopened round would see fixtures they cannot act on and a
     * leaderboard of nobody.
     *
     * @return list<array<string, mixed>>
     */
    public function openWeeks(): array
    {
        $weeks = $this->p('picks_weeks');
        $seasons = $this->p('picks_seasons');

        return array_map(
            [$this, 'shapeWeek'],
            $this->db->table('picks_weeks')
                ->select($weeks . '.*', $seasons . '.year AS season_year')
                ->join($seasons, $seasons . '.id', '=', $weeks . '.season_id')
                ->where($weeks . '.is_open', 1)
                ->orderByDesc($seasons . '.year')
                ->orderByRaw('CASE ' . $weeks . ".`season_type` WHEN 'regular' THEN 0 ELSE 1 END")
                ->orderBy($weeks . '.week_number')
                ->get()
        );
    }

    /** @param array<string, mixed> $row */
    private function shapeWeek(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'season_id' => (int) $row['season_id'],
            'name' => (string) $row['name'],
            'week_number' => (int) $row['week_number'],
            'season_type' => (string) $row['season_type'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'is_open' => (int) $row['is_open'] === 1,
            'season_year' => (int) ($row['season_year'] ?? 0),
        ];
    }

    private function p(string $table): string
    {
        return $this->db->prefixed($table);
    }
}
