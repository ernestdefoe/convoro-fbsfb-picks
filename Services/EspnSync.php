<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;
use Convoro\Extensions\Picks\Services\Leagues\League;
use Convoro\Extensions\Picks\Services\Leagues\Leagues;
use Convoro\Extensions\Picks\Services\Sources\EspnGames;

/**
 * Fixtures and scores for every season that is not college football.
 *
 * 🚨 Deliberately a SECOND service rather than a rewrite of `Sync`. That one is
 * CollegeFootballData-shaped down to its bones — a calendar endpoint, a
 * conference filter, teams keyed by provider id, a call budget spread across
 * ticks — and the live site depends on it working exactly as it does.
 * Generalising it would put a season of somebody's picks behind a refactor
 * nobody asked for, to make one code path serve two feeds that genuinely
 * disagree about what a season looks like.
 *
 * The two meet where it matters: the same tables, the same normalised box
 * score, the same recap.
 *
 * 🚨 This is the SIBLING of `Service/EspnSyncService.php` in the Flarum build.
 */
final class EspnSync
{
    public function __construct(
        private Connection $db,
        private EspnGames $espn,
        private Seasons $seasons,
        private Teams $teams,
        private Games $games,
        private Settings $settings,
        private Leagues $leagues = new Leagues(),
    ) {
    }

    /**
     * Bring every ESPN-backed season up to date.
     *
     * @return array<string, mixed> a summary, for the log and for tests
     */
    public function fixtures(): array
    {
        if (!$this->settings->enabled()) {
            return ['skipped' => 'off'];
        }

        $seasons = $this->seasons->onEspn();

        if ($seasons === []) {
            return ['skipped' => 'no espn seasons'];
        }

        $summary = ['seasons' => 0, 'teams' => 0, 'weeks' => 0, 'stored' => 0, 'errors' => []];

        foreach ($seasons as $season) {
            $league = $this->leagues->get($season['league'] ?? null);

            if (!$this->espn->supports($league)) {
                continue;
            }

            $summary['seasons']++;

            $games = $this->espn->games($league, (int) $season['year']);

            if ($games === []) {
                /*
                 * 🚨 Not an error. Out of season, ESPN answers a scoreboard
                 * with nothing on it, which is the ordinary answer for most of
                 * the year — recording it as a failure would leave a red panel
                 * on the health screen every summer.
                 */
                continue;
            }

            $this->store($season, $league, $games, $summary);
        }

        return $summary;
    }

    /* -------------------------------------------------------------- storing */

    /**
     * @param array<string, mixed>       $season
     * @param list<array<string, mixed>> $games
     * @param array<string, mixed>       $summary
     */
    private function store(array $season, League $league, array $games, array &$summary): void
    {
        $seasonId = (int) $season['id'];
        $offset = $this->settings->lockOffsetMinutes();

        /*
         * 🚨 Read once, into memory, before the loop. A season is several
         * hundred games and the naive version fires a lookup per game per
         * side.
         */
        $byName = [];

        foreach ($this->teams->map() as $id => $team) {
            $byName[$this->key((string) $team['name'])] = $id;
        }

        $weeks = [];

        foreach ($games as $game) {
            $matchAt = strtotime((string) $game['start']);

            if ($game['external_id'] === '' || $matchAt === false || $matchAt < 1) {
                continue;
            }

            $homeId = $this->team($byName, (string) $game['home'], $summary);
            $awayId = $this->team($byName, (string) $game['away'], $summary);

            if ($homeId < 1 || $awayId < 1) {
                continue;
            }

            $weekId = $this->week($seasonId, $league, $game, $matchAt, $weeks, $summary);

            if ($weekId < 1) {
                continue;
            }

            [$id, ] = $this->games->upsertFixture([
                'external_id' => $game['external_id'],
                'week_id' => $weekId,
                'home_team_id' => $homeId,
                'away_team_id' => $awayId,
                'neutral_site' => $game['neutral_site'],
                'match_at' => $matchAt,
                'completed' => $game['completed'],
                'home_score' => $game['home_score'],
                'away_score' => $game['away_score'],
            ], $offset);

            if ($id > 0) {
                $summary['stored']++;
            }
        }
    }

    /**
     * A team's id, creating it if this is the first time we have seen it.
     *
     * 🚨 Created rather than skipped, which is the opposite of the college
     * sync's rule and right for the opposite reason. That one is FBS-only, and
     * a fixture against somebody outside it is a game nobody can pick. ESPN
     * answers no separate team list for most of these leagues, so on a first
     * sync every club is unknown — skipping would import nothing and say
     * nothing about why.
     *
     * @param array<string, int>   $byName
     * @param array<string, mixed> $summary
     */
    private function team(array &$byName, string $name, array &$summary): int
    {
        $name = trim($name);

        if ($name === '') {
            return 0;
        }

        $key = $this->key($name);

        if (isset($byName[$key])) {
            return $byName[$key];
        }

        $id = (int) $this->db->table('picks_teams')->insertGetId([
            'name' => mb_substr($name, 0, 190),
            'slug' => $this->slug($name),
            'abbreviation' => mb_strtoupper(mb_substr((string) preg_replace('/[^A-Za-z]/', '', $name), 0, 4)),
            'conference' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($id > 0) {
            $byName[$key] = $id;
            $summary['teams']++;
        }

        return $id;
    }

    /**
     * The week a game belongs to.
     *
     * 🚨 Most sports have no weeks, and this is where that is dealt with.
     * Gridiron numbers its rounds and everything else is played to a date, but
     * the whole model here — a pick deadline, a leaderboard, "this week's
     * games" — is built on weeks existing. So a league without them gets one
     * week per calendar week, which is what a pick'em for those sports is
     * anyway: you pick this week's games.
     *
     * 🚨 ISO weeks, so a week begins on Monday and a Sunday game lands in the
     * week it was played rather than opening the next one. Every league here
     * plays across a weekend, and a Sunday-starting week would split every one
     * of them in half.
     *
     * @param array<string, mixed> $game
     * @param array<string, int>   $weeks
     * @param array<string, mixed> $summary
     */
    private function week(int $seasonId, League $league, array $game, int $matchAt, array &$weeks, array &$summary): int
    {
        $type = (string) ($game['season_type'] ?? Seasons::REGULAR);

        if ($league->hasWeeks && $game['week'] !== null) {
            $number = (int) $game['week'];
            $name = $type === Seasons::POSTSEASON ? 'Postseason' : 'Week ' . $number;
        } else {
            $number = (int) date('W', $matchAt);
            $name = 'Week of ' . date('j M', strtotime('monday this week', $matchAt) ?: $matchAt);
        }

        $key = $type . ':' . $number;

        if (isset($weeks[$key])) {
            return $weeks[$key];
        }

        $existing = $this->db->table('picks_weeks')
            ->where('season_id', $seasonId)
            ->where('season_type', $type)
            ->where('week_number', $number)
            ->first();

        if ($existing !== null) {
            return $weeks[$key] = (int) $existing['id'];
        }

        $id = (int) $this->db->table('picks_weeks')->insertGetId([
            'season_id' => $seasonId,
            'name' => $name,
            'week_number' => $number,
            'season_type' => $type,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($id > 0) {
            $summary['weeks']++;
        }

        return $weeks[$key] = $id;
    }

    /* --------------------------------------------------------------- naming */

    private function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $name)));
    }

    private function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');

        return $slug === '' ? 'team-' . substr(md5($name), 0, 8) : mb_substr($slug, 0, 100);
    }
}
