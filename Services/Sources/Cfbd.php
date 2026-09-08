<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services\Sources;

use Convoro\Extensions\Picks\Services\Http;
use Convoro\Extensions\Picks\Services\Settings;

/**
 * CollegeFootballData: who the teams are, how the season is divided, and what
 * the fixtures are.
 *
 * 🚨 Every method returns `[$rows, $error]` and NEVER throws. `$error` empty
 * means the call worked; `$rows` empty with no error means the provider
 * genuinely has nothing for that question. Those are different facts and the
 * sync has to be able to tell them apart, because one of them means "store
 * this" and the other means "leave everything alone and try again later".
 *
 * 🚨 The API key goes in a header and is never in a URL. A query string is in
 * the access log of every proxy between here and them.
 */
final class Cfbd
{
    private const BASE = 'https://api.collegefootballdata.com';

    /** Only the top division. The pick'em is an FBS pick'em. */
    private const CLASSIFICATION = 'fbs';

    public function __construct(
        private readonly Http $http,
        private readonly Settings $settings,
    ) {
    }

    public function configured(): bool
    {
        return $this->settings->hasCfbdKey();
    }

    /**
     * Every FBS team, optionally narrowed to one conference.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function teams(): array
    {
        $query = ['classification' => self::CLASSIFICATION];
        $conference = $this->settings->conference();

        if ($conference !== '') {
            $query['conference'] = $conference;
        }

        [$rows, $error] = $this->fetch('/teams', $query);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            /*
             * 🚨 Filtered again here even though the query asked for FBS. The
             * endpoint has answered with other divisions before, and a Division
             * II team in `picks_teams` is a fixture list nobody can pick and a
             * logo that 404s.
             */
            if (strtolower((string) ($row['classification'] ?? '')) !== self::CLASSIFICATION) {
                continue;
            }

            $name = trim((string) ($row['school'] ?? ''));
            $id = (int) ($row['id'] ?? 0);

            if ($name === '' || $id < 1) {
                continue;
            }

            $out[] = [
                'cfbd_id' => $id,
                'name' => $name,
                'abbreviation' => trim((string) ($row['abbreviation'] ?? '')),
                'conference' => trim((string) ($row['conference'] ?? '')),
                'espn_id' => $this->espnIdFrom($row['logos'] ?? null),
            ];
        }

        return [$out, ''];
    }

    /**
     * The regular-season weeks of one year, with their date ranges.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function calendar(int $year): array
    {
        [$rows, $error] = $this->fetch('/calendar', ['year' => (string) $year]);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row) || strtolower((string) ($row['seasonType'] ?? '')) !== 'regular') {
                continue;
            }

            $week = (int) ($row['week'] ?? 0);

            if ($week < 1) {
                continue;
            }

            $out[] = [
                'week' => $week,
                'start_date' => $this->dateOnly($row['startDate'] ?? null),
                'end_date' => $this->dateOnly($row['endDate'] ?? null),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['week'] <=> $b['week']);

        return [$out, ''];
    }

    /**
     * The fixtures for one week, or for the whole postseason.
     *
     * @return array{0: list<array<string, mixed>>, 1: string}
     */
    public function games(int $year, string $seasonType, ?int $week = null): array
    {
        $query = [
            'year' => (string) $year,
            'seasonType' => $seasonType,
            'classification' => self::CLASSIFICATION,
        ];

        if ($week !== null) {
            $query['week'] = (string) $week;
        }

        $conference = $this->settings->conference();

        if ($conference !== '') {
            $query['conference'] = $conference;
        }

        [$rows, $error] = $this->fetch('/games', $query);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $homeId = (int) ($row['homeId'] ?? 0);
            $awayId = (int) ($row['awayId'] ?? 0);
            $matchAt = $this->epoch($row['startDate'] ?? null);

            if ($id < 1 || $homeId < 1 || $awayId < 1 || $matchAt < 1) {
                continue;
            }

            /*
             * 🚨 A kickoff time the provider marks TBD is pulled back to noon
             * UTC on the day of the game, which is what the Flarum original
             * did and is the right direction to be wrong in. A placeholder time
             * is usually midnight; taking it at face value would set a cutoff
             * before anybody had a chance to pick, and taking it as "no
             * deadline" would leave the game pickable all day.
             */
            if (!empty($row['startTimeTBD'])) {
                $matchAt = (int) strtotime('midday', (int) strtotime(gmdate('Y-m-d', $matchAt) . ' 00:00:00 UTC'));
            }

            $out[] = [
                'cfbd_id' => $id,
                'home_cfbd_id' => $homeId,
                'away_cfbd_id' => $awayId,
                'neutral_site' => !empty($row['neutralSite']),
                'match_at' => $matchAt,
                'completed' => !empty($row['completed']),
                'home_score' => isset($row['homePoints']) && $row['homePoints'] !== null
                    ? (int) $row['homePoints'] : null,
                'away_score' => isset($row['awayPoints']) && $row['awayPoints'] !== null
                    ? (int) $row['awayPoints'] : null,
            ];
        }

        return [$out, ''];
    }

    /**
     * The team box score for every game in a week.
     *
     * 🚨 A WEEK, not a game, and that is a budget decision. This provider spends
     * a monthly allowance per call, and a Saturday has sixty games on it —
     * asking per game is sixty calls for the same answer one call gives. The
     * caller sorts out which games it wanted.
     *
     * Answered as `[cfbd game id => raw teams array]`, still in the provider's
     * shape. Normalising happens once, in `BoxScores`, so this file stays what
     * every other method here is: the place that knows the provider and nothing
     * else does.
     *
     * @return array{0: array<int, list<array<string, mixed>>>, 1: string}
     */
    public function teamBoxScores(int $year, string $seasonType, int $week): array
    {
        return $this->boxScores('/games/teams', $year, $seasonType, $week, 'teams');
    }

    /**
     * The player box score for every game in a week.
     *
     * @return array{0: array<int, list<array<string, mixed>>>, 1: string}
     */
    public function playerBoxScores(int $year, string $seasonType, int $week): array
    {
        return $this->boxScores('/games/players', $year, $seasonType, $week, 'teams');
    }

    /**
     * @return array{0: array<int, list<array<string, mixed>>>, 1: string}
     */
    private function boxScores(string $path, int $year, string $seasonType, int $week, string $key): array
    {
        $query = [
            'year' => (string) $year,
            'seasonType' => $seasonType,
            'week' => (string) $week,
            'classification' => self::CLASSIFICATION,
        ];

        $conference = $this->settings->conference();

        if ($conference !== '') {
            $query['conference'] = $conference;
        }

        [$rows, $error] = $this->fetch($path, $query);

        if ($error !== '') {
            return [[], $error];
        }

        $out = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            $sides = $row[$key] ?? null;

            /*
             * 🚨 A game with no game id, or with one side missing, is dropped
             * rather than half-stored. A box score showing one team's numbers
             * beside a blank column reads as the other team having done
             * nothing, which is worse than showing no box score at all.
             */
            if ($id < 1 || !is_array($sides) || count($sides) < 2) {
                continue;
            }

            $out[$id] = array_values(array_filter($sides, 'is_array'));
        }

        return [$out, ''];
    }

    /**
     * @param array<string, string> $query
     * @return array{0: list<mixed>, 1: string}
     */
    private function fetch(string $path, array $query): array
    {
        $key = $this->settings->cfbdKey();

        if ($key === '') {
            return [[], 'unconfigured'];
        }

        /*
         * 🚨 The budget is checked BEFORE the call, not after it.
         *
         * Counting a call once it has been made tells you the allowance is
         * gone one call too late, which on a monthly quota means the last call
         * of the month is always the provider's refusal rather than ours.
         */
        if ($this->settings->callsLeft() < 1) {
            return [[], 'budget spent'];
        }

        $this->settings->recordCall();

        [$status, $body] = $this->http->getJson(self::BASE . $path, $query, [
            'Authorization' => 'Bearer ' . $key,
        ]);

        if ($status === 0) {
            return [[], 'no answer'];
        }

        /*
         * 🚨 429 is not "cannot reach". It is the provider working perfectly
         * and declining, and it has to be told apart from an outage because
         * the response to it is the opposite one: an outage is retried, a
         * refusal must not be.
         *
         * CFBD spends its allowance per calendar month and says so in the
         * body. Anything else 429 is a rate this site is exceeding, which will
         * pass on its own.
         */
        if ($status === 429) {
            $message = is_array($body) && isset($body['message']) && is_string($body['message'])
                ? $body['message']
                : '';

            return [[], stripos($message, 'monthly') !== false ? 'quota spent' : 'rate limited'];
        }

        /*
         * 🚨 401 is named separately because it is the one failure an operator
         * can actually fix, and "HTTP 401" on a status screen sends them
         * looking at their server instead of at their API key.
         */
        if ($status === 401 || $status === 403) {
            return [[], 'key rejected'];
        }

        if ($status < 200 || $status >= 300) {
            return [[], 'HTTP ' . $status];
        }

        return [array_values($body), ''];
    }

    /**
     * The ESPN team id hidden in a CFBD logo address.
     *
     * `https://a.espncdn.com/i/teamlogos/ncaa/500/333.png` → 333. It is the
     * only place the two providers' identifiers meet, and it is what lets a
     * team's crest be fetched later without a second API call.
     */
    private function espnIdFrom(mixed $logos): int
    {
        if (!is_array($logos)) {
            return 0;
        }

        foreach ($logos as $logo) {
            if (is_string($logo) && preg_match('#/(\d+)\.png#', $logo, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function epoch(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        $stamp = strtotime($value);

        return $stamp === false ? 0 : $stamp;
    }

    private function dateOnly(mixed $value): ?string
    {
        $stamp = $this->epoch($value);

        return $stamp < 1 ? null : gmdate('Y-m-d', $stamp);
    }
}
