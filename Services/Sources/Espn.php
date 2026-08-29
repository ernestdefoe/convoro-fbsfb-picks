<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services\Sources;

use Convoro\Extensions\Picks\Services\Http;

/**
 * ESPN's public scoreboard, for scores while games are being played.
 *
 * 🚨 One call answers for every game happening now, which is why this is the
 * live source and CollegeFootballData is not: a poll that cost one request per
 * game would be thirty requests a tick on a Saturday afternoon.
 *
 * 🚨 The games are matched to ours by CFBD game id, because for these seasons
 * CFBD's identifier IS ESPN's event id. That is a fact about the two providers
 * rather than a guarantee either of them makes, so a game that does not match
 * is skipped in silence and simply keeps its scheduled state — the sync never
 * guesses a fixture from team names.
 *
 * 🚨 Returns `[$games, $error]` and never throws. An empty list with no error
 * means nothing is being played, which is the ordinary answer for most of the
 * week; an error means the scoreboard did not answer, and NOTHING is written.
 * Reading those two as the same would mark a whole afternoon's games as
 * unstarted every time ESPN had a bad minute.
 */
final class Espn
{
    private const SCOREBOARD =
        'https://site.api.espn.com/apis/site/v2/sports/football/college-football/scoreboard';

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * The ESPN calendar runs on US EASTERN time, not UTC and not ours.
     *
     * 🚨 A 10pm Saturday kickoff on the east coast is already Sunday in UTC, so
     * asking for the UTC date would miss the whole of Saturday night — every
     * week, and only the late games, which is the hardest kind of gap to spot.
     * The date asked for is the Eastern one.
     */
    private const CALENDAR_ZONE = 'America/New_York';

    /**
     * Every game on the scoreboard for a given day that has kicked off.
     *
     * 🚨 Ask for a DATE. Called bare, this endpoint answers with a slate of its
     * own choosing — 25 games that did not include the one actually being
     * played when this was found, while `?dates=20260829` returned the eight
     * that were on and ours among them. So a live game could be refreshed all
     * afternoon and never be in the answer.
     *
     * @param int|null $when a moment on the day wanted; now if not given
     * @return array{0: list<array{id: int, home: ?int, away: ?int, completed: bool}>, 1: string}
     */
    public function scoreboard(?int $when = null): array
    {
        $day = (new \DateTimeImmutable('@' . ($when ?? time())))
            ->setTimezone(new \DateTimeZone(self::CALENDAR_ZONE))
            ->format('Ymd');

        [$status, $body] = $this->http->getJson(self::SCOREBOARD, [
            'dates' => $day,
            // Well past a full Saturday, so the answer is never truncated.
            'limit' => 900,
        ]);

        if ($status === 0) {
            return [[], 'no answer'];
        }

        if ($status < 200 || $status >= 300) {
            return [[], 'HTTP ' . $status];
        }

        $events = $body['events'] ?? [];

        if (!is_array($events)) {
            return [[], 'unreadable'];
        }

        $out = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $id = (int) ($event['id'] ?? 0);
            $competition = $event['competitions'][0] ?? null;

            if ($id < 1 || !is_array($competition)) {
                continue;
            }

            $type = $competition['status']['type'] ?? [];
            $state = (string) ($type['state'] ?? 'pre');

            // Not started. Nothing to say about it that our own row does not
            // already say better.
            if ($state === 'pre') {
                continue;
            }

            $home = null;
            $away = null;

            foreach ($competition['competitors'] ?? [] as $competitor) {
                if (!is_array($competitor) || !isset($competitor['score'])) {
                    continue;
                }

                $score = (int) $competitor['score'];

                if (($competitor['homeAway'] ?? '') === 'home') {
                    $home = $score;
                }

                if (($competitor['homeAway'] ?? '') === 'away') {
                    $away = $score;
                }
            }

            /*
             * The clock, for a scoreboard that means to look like one.
             *
             * 🚨 Period is safe to show and the clock is not, on its own:
             * a quarter lasts fifteen minutes and a game clock moves every
             * second, so a number fetched a minute ago is a lie by the time it
             * is read. Both are carried, along with WHEN they were true, and
             * the front end decides what is still worth showing.
             */
            $clock = $competition['status'] ?? [];

            $out[] = [
                'id' => $id,
                'home' => $home,
                'away' => $away,
                'period' => (int) ($clock['period'] ?? 0),
                'clock' => trim((string) ($clock['displayClock'] ?? '')),
                // "2nd Quarter", "Halftime", "End of 3rd" — ESPN's own words,
                // which are better than any we would invent from a number.
                'detail' => trim((string) ($type['shortDetail'] ?? $type['detail'] ?? '')),

                /*
                 * 🚨 `completed`, not `state === 'post'`. ESPN puts a game into
                 * `post` while it is still being reviewed, and a final result
                 * written from that is a result that can change afterwards —
                 * having already scored everybody's picks from it.
                 */
                'completed' => !empty($type['completed']),
            ];
        }

        return [$out, ''];
    }
}
