<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services\Sources;

use Convoro\Extensions\Picks\Services\Http;
use Convoro\Extensions\Picks\Services\Leagues\League;

/**
 * ESPN — the NFL, the NBA, MLB, the NHL and league football, in one shape.
 *
 * 🚨 A SECOND class beside `Espn`, not an extension of it. That one polls the
 * college-football scoreboard for live scores and is tuned for exactly that: a
 * fixed URL, an Eastern-time calendar day, and a contract that returns
 * `[$games, $error]` and never throws. Bolting fixtures and box scores for
 * eight other leagues onto it would give one class two jobs that disagree about
 * what an error means.
 *
 * 🚨 The single most useful fact about this API: every sport answers the same
 * two endpoints with the same envelope. A scoreboard is
 * `{events: [{id, date, competitions: [{competitors, status}]}]}` whether it is
 * the Premier League or MLB, which is why one adapter covers every league and
 * adding another is a line in the registry.
 *
 * 🚨 It needs NO API KEY, which is why it is the multi-sport backbone.
 * CollegeFootballData stays where it is used because it is richer for college
 * football, not because ESPN could not answer.
 *
 * 🚨 This is the SIBLING of `Service/Providers/EspnProvider.php` in the Flarum
 * build of Picks. Everything below was read off live responses in September
 * 2026 rather than from documentation, because ESPN publishes none — and both
 * builds absorb the same four shape differences, from the same captured
 * payloads.
 */
final class EspnGames
{
    private const BASE = 'https://site.api.espn.com/apis/site/v2/sports';

    /**
     * 🚨 A hard ceiling on summary fetches per run, because a box score here is
     * ONE CALL PER GAME — unlike CollegeFootballData, which answers a whole
     * week at once. A full MLB day is fifteen games and a Saturday of college
     * basketball is a hundred and fifty; without this, one scheduled job would
     * fire a hundred and fifty outbound requests inside a minute. That has
     * caused a real outage on this stack before.
     *
     * Whatever is not fetched this run is fetched the next one. A box score
     * arriving an hour later is invisible; a queue worker taken out by its own
     * traffic is not.
     */
    public const MAX_SUMMARIES_PER_RUN = 25;

    /** @var array<string, array<string, mixed>> summaries already fetched this process */
    private array $summaries = [];

    private int $fetched = 0;

    public function __construct(private readonly Http $http)
    {
    }

    public function supports(League $league): bool
    {
        return $league->espnPath !== '';
    }

    /**
     * The fixtures.
     *
     * @return list<array<string, mixed>>
     */
    public function games(League $league, int $year, ?int $week = null, string $seasonType = 'regular'): array
    {
        if (!$this->supports($league)) {
            return [];
        }

        $params = ['limit' => '1000', 'dates' => (string) $year];

        /*
         * 🚨 Weeks and dates are not interchangeable, and asking for the wrong
         * one returns TODAY rather than an error. ESPN understands `week` for
         * the sports that have them and silently ignores it for the rest — so a
         * basketball season asked for "week 3" would answer with tonight's
         * games, and the sync would happily store them as week 3.
         */
        if ($league->hasWeeks && $week !== null) {
            $params['week'] = (string) $week;
            $params['seasontype'] = $seasonType === 'postseason' ? '3' : '2';
        }

        $body = $this->get($league->espnPath . '/scoreboard', $params);

        if ($body === null) {
            return [];
        }

        $games = [];

        foreach ((array) ($body['events'] ?? []) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $game = $this->game($event, $league);

            if ($game !== null) {
                $games[] = $game;
            }
        }

        return $games;
    }

    /**
     * One game's box score, in the shape `BoxScores::normalise()` reads.
     *
     * 🚨 Per GAME, not per week, even though the CFBD endpoints are per week.
     * The caller knows which games are missing a box score; asking game by game
     * is what lets it stop after twenty-five rather than re-fetching a whole
     * week every hour for the rest of the season.
     *
     * @return array{teams: list<array<string, mixed>>, players: list<array<string, mixed>>}|null
     */
    public function boxScore(League $league, string $externalId, ?int $week = null): ?array
    {
        if (!$this->supports($league) || $externalId === '') {
            return null;
        }

        if ($this->fetched >= self::MAX_SUMMARIES_PER_RUN && !isset($this->summaries[$externalId])) {
            return null;
        }

        $summary = $this->summary($league, $externalId);

        if ($summary === null) {
            return null;
        }

        $box = (array) ($summary['boxscore'] ?? []);
        $teams = $this->teamSides((array) ($box['teams'] ?? []));

        // No team statistics is no box score. Half of one is worse than none.
        if (count($teams) < 2) {
            return null;
        }

        return [
            'teams' => $teams,
            'players' => $this->playerSides((array) ($box['players'] ?? []), (array) ($box['teams'] ?? [])),
        ];
    }

    /* ------------------------------------------------------------- fixtures */

    /** @return array<string, mixed>|null */
    private function game(array $event, League $league): ?array
    {
        $competition = $event['competitions'][0] ?? null;

        if (!is_array($competition)) {
            return null;
        }

        $home = null;
        $away = null;

        foreach ((array) ($competition['competitors'] ?? []) as $side) {
            if (!is_array($side)) {
                continue;
            }

            if (($side['homeAway'] ?? '') === 'home') {
                $home = $side;
            } elseif (($side['homeAway'] ?? '') === 'away') {
                $away = $side;
            }
        }

        if ($home === null || $away === null) {
            return null;
        }

        $type = (array) (($competition['status'] ?? $event['status'] ?? [])['type'] ?? []);

        /*
         * 🚨 Finished is read from `completed` and `state`, NEVER from the
         * status name. Soccer's finished game is `STATUS_FULL_TIME`, baseball's
         * is `STATUS_FINAL`, and a match settled on penalties is something else
         * again — matching on the name works for the sport it was written
         * against and silently leaves every other league's games permanently
         * "in progress", which is a thread that never gets its recap and never
         * says why.
         */
        $completed = (bool) ($type['completed'] ?? false) || ($type['state'] ?? '') === 'post';

        return [
            'external_id' => (string) ($event['id'] ?? ''),
            'week' => $league->hasWeeks ? $this->weekNumber($event) : null,
            'season_type' => ((int) (($event['season'] ?? [])['type'] ?? 2)) === 3 ? 'postseason' : 'regular',
            'start' => (string) ($event['date'] ?? ''),
            'home' => (string) (($home['team'] ?? [])['displayName'] ?? ''),
            'away' => (string) (($away['team'] ?? [])['displayName'] ?? ''),
            /*
             * 🚨 The crest comes back with the FIXTURE, and taking it here is
             * what saves a second endpoint entirely. A pick'em whose teams have
             * no logo is a board of grey squares — the first version of this
             * shipped exactly that, and it looked broken rather than unfinished.
             */
            'home_team' => $this->club($home),
            'away_team' => $this->club($away),
            'home_score' => isset($home['score']) ? (int) $home['score'] : null,
            'away_score' => isset($away['score']) ? (int) $away['score'] : null,
            'completed' => $completed,
            'status' => (string) ($type['state'] ?? 'pre'),
            'neutral_site' => (bool) ($competition['neutralSite'] ?? false),
        ];
    }

    /**
     * The club itself, as much of it as a scoreboard carries.
     *
     * @param  array<string, mixed> $side
     * @return array<string, string>
     */
    private function club(array $side): array
    {
        $team = is_array($side['team'] ?? null) ? $side['team'] : [];

        return [
            'external_id' => (string) ($team['id'] ?? ''),
            'name' => (string) ($team['displayName'] ?? ''),
            'abbreviation' => (string) ($team['abbreviation'] ?? ''),
            'logo' => (string) ($team['logo'] ?? ''),
            'color' => (string) ($team['color'] ?? ''),
        ];
    }

    private function weekNumber(array $event): ?int
    {
        $week = $event['week'] ?? null;

        if (is_array($week) && isset($week['number'])) {
            return (int) $week['number'];
        }

        return is_numeric($week) ? (int) $week : null;
    }

    /* ----------------------------------------------------------- box scores */

    /** @return list<array<string, mixed>> */
    private function teamSides(array $teams): array
    {
        $out = [];

        foreach ($teams as $side) {
            if (!is_array($side)) {
                continue;
            }

            $out[] = [
                'homeAway' => (string) ($side['homeAway'] ?? 'home'),
                'team' => (string) (($side['team'] ?? [])['displayName'] ?? ''),
                'points' => null,
                'stats' => $this->flatten((array) ($side['statistics'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * 🚨 ESPN answers team statistics in TWO different shapes and it is not
     * documented which sport uses which.
     *
     * Football, basketball and hockey answer a flat list of
     * `{name, displayValue}`. Baseball answers GROUPS — batting, pitching,
     * fielding — each with its own `stats[]` and no `displayValue` of its own.
     *
     * The grouped ones are prefixed, and that is not cosmetic: `hits` appears
     * in all three baseball groups meaning hits made, hits allowed, and hits
     * handled in the field. Flattening onto bare names would keep whichever
     * came last and print a pitcher's line as the batting figure — a number
     * that looks entirely plausible and is about somebody else.
     *
     * @return list<array{category: string, stat: string}>
     */
    private function flatten(array $statistics, string $prefix = ''): array
    {
        $out = [];

        foreach ($statistics as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = (string) ($entry['name'] ?? '');

            if ($name === '') {
                continue;
            }

            if (isset($entry['stats']) && is_array($entry['stats'])) {
                foreach ($this->flatten($entry['stats'], $prefix . $name . '.') as $nested) {
                    $out[] = $nested;
                }

                continue;
            }

            $out[] = [
                'category' => $prefix . $name,
                'stat' => (string) ($entry['displayValue'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Players, pivoted onto the shape the normaliser reads.
     *
     * 🚨 ESPN's player box score is PARALLEL ARRAYS — a group carries `labels[]`
     * and every athlete carries `stats[]` in the same order, with nothing tying
     * a figure to its label except position. Zipping them here, once, is the
     * whole job; every reader downstream would otherwise zip them again and one
     * of them would eventually get the offset wrong.
     *
     * 🚨 And the group's own name lives in a DIFFERENT FIELD per sport. The NFL
     * puts it in `name` ("passing"), baseball in `type` ("batting"), hockey in
     * `name` ("forwards"), and basketball supplies neither because it has only
     * one group. All four were read off live responses.
     *
     * @return list<array<string, mixed>>
     */
    private function playerSides(array $players, array $teams): array
    {
        $out = [];

        foreach ($players as $side) {
            if (!is_array($side)) {
                continue;
            }

            $categories = [];

            foreach ((array) ($side['statistics'] ?? []) as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $name = (string) ($group['name'] ?? $group['type'] ?? '');
                $name = $name === '' ? 'general' : $name;

                $labels = array_values(array_filter(
                    (array) ($group['labels'] ?? $group['names'] ?? []),
                    'is_string'
                ));

                $athletes = (array) ($group['athletes'] ?? []);

                if ($labels === [] || $athletes === []) {
                    continue;
                }

                $types = [];

                foreach ($labels as $index => $label) {
                    $entries = [];

                    foreach ($athletes as $athlete) {
                        if (!is_array($athlete)) {
                            continue;
                        }

                        $stats = array_values((array) ($athlete['stats'] ?? []));

                        // A short row is a row, not a reason to drop the athlete.
                        if (!array_key_exists($index, $stats)) {
                            continue;
                        }

                        $entries[] = [
                            'name' => (string) (($athlete['athlete'] ?? [])['displayName'] ?? ''),
                            'stat' => (string) $stats[$index],
                        ];
                    }

                    if ($entries !== []) {
                        $types[] = ['name' => $label, 'athletes' => $entries];
                    }
                }

                if ($types !== []) {
                    $categories[] = ['name' => $name, 'types' => $types];
                }
            }

            $out[] = [
                'homeAway' => $this->whichSide($side, $teams, count($out)),
                'categories' => $categories,
            ];
        }

        return $out;
    }

    /**
     * Which side a player group belongs to.
     *
     * 🚨 ESPN does not put `homeAway` on the player side in any sport read so
     * far — it is on the TEAM side of the same box score. Defaulting to "home"
     * would file the away team's leaders under the home team, which is wrong in
     * a way that reads perfectly plausibly and would never be noticed.
     *
     * 🚨 Matched by TEAM ID rather than by position. The two lists have been in
     * the same order — away, then home — in every sport checked, and relying on
     * that would work until the day it did not, at which point every recap would
     * name the wrong team's players and still look right.
     *
     * @param array<string, mixed>       $side
     * @param list<array<string, mixed>> $teams
     */
    private function whichSide(array $side, array $teams, int $position): string
    {
        $id = (string) (($side['team'] ?? [])['id'] ?? '');

        if ($id !== '') {
            foreach ($teams as $team) {
                if (is_array($team) && (string) (($team['team'] ?? [])['id'] ?? '') === $id) {
                    return ($team['homeAway'] ?? '') === 'away' ? 'away' : 'home';
                }
            }
        }

        return $position === 0 ? 'away' : 'home';
    }

    /* -------------------------------------------------------------- the wire */

    /** @return array<string, mixed>|null */
    private function summary(League $league, string $eventId): ?array
    {
        if (isset($this->summaries[$eventId])) {
            return $this->summaries[$eventId];
        }

        $this->fetched++;

        $body = $this->get($league->espnPath . '/summary', ['event' => $eventId]);

        if ($body === null) {
            /*
             * 🚨 Not cached as a miss. A game whose summary failed once should
             * be asked again next hour — that is the difference between a slow
             * publish and a game the provider never covered, and only time can
             * tell them apart.
             */
            return null;
        }

        return $this->summaries[$eventId] = $body;
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>|null null when nobody answered or the answer was not usable
     */
    private function get(string $path, array $params): ?array
    {
        [$status, $body] = $this->http->getJson(self::BASE . '/' . ltrim($path, '/'), $params);

        if ($status < 200 || $status >= 300 || !is_array($body)) {
            return null;
        }

        return $body;
    }
}
