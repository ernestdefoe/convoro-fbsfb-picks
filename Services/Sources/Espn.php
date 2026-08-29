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

            /*
             * Who has the ball, as OUR word for a side rather than ESPN's id
             * for a team.
             *
             * 🚨 `situation.possession` is an ESPN team id, and nothing in
             * this database is keyed by one. Resolving it here — against the
             * two competitors we are already reading — means the rest of the
             * system stores "home" or "away", which is what a scoreboard
             * actually needs and cannot drift out of step with anybody's team
             * table.
             */
            $situation = $competition['situation'] ?? [];
            $hasBall = (string) ($situation['possession'] ?? '');
            $possession = '';

            foreach ($competition['competitors'] ?? [] as $competitor) {
                if (!is_array($competitor)) {
                    continue;
                }

                $side = (string) ($competitor['homeAway'] ?? '');

                if ($hasBall !== '' && (string) ($competitor['id'] ?? '') === $hasBall) {
                    $possession = $side;
                }

                if (!isset($competitor['score'])) {
                    continue;
                }

                $score = (int) $competitor['score'];

                if ($side === 'home') {
                    $home = $score;
                }

                if ($side === 'away') {
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
                'possession' => $possession,

                /*
                 * The short form. "2nd & 10" is what belongs on a scoreboard;
                 * "2nd & 10 at TCU 45" is a sentence, and the yard line is
                 * already the least durable thing on the strip.
                 */
                'down' => self::downAndDistance($situation),
                'red_zone' => !empty($situation['isRedZone']),

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

    /**
     * "2nd & 10", from whichever the feed happened to send.
     *
     * 🚨 The text is not always there. ESPN's situation block changes shape
     * through a game — between possessions it can carry `down` and `distance`
     * as bare numbers with no sentence built from them, and after a score it
     * carries `down = 0`, which means there is no down rather than a zeroth one.
     *
     * Nothing is invented from a missing possession, though. Absence there is
     * an answer: it means nobody has settled with the ball, and a football
     * drawn beside a guess is worse than no football at all.
     *
     * @param array<string, mixed> $situation
     */
    private static function downAndDistance(array $situation): string
    {
        $text = trim((string) ($situation['shortDownDistanceText'] ?? ''));

        /*
         * 🚨 A negative distance is refused, not printed.
         *
         * Caught live: ESPN sent "4th & -1" for a minute either side of the
         * half, and it went straight onto the board. A scoreboard reading
         * "4th & -1" is not a scoreboard with a small mistake on it, it is one
         * nobody trusts again — and a feed being briefly wrong is a normal
         * event, not an exceptional one.
         *
         * Showing nothing is the honest answer while the provider disagrees
         * with itself; the score and the clock beside it are unaffected.
         */
        if ($text !== '' && !preg_match('/-\s*\d/', $text)) {
            return $text;
        }

        $down = (int) ($situation['down'] ?? 0);

        if ($down < 1 || $down > 4) {
            return '';
        }

        $distance = (int) ($situation['distance'] ?? 0);
        $ordinal = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'][$down];

        // "& Goal" is what a scoreboard says when the distance IS the end zone.
        return $distance > 0 ? $ordinal . ' & ' . $distance : $ordinal . ' & Goal';
    }
}
