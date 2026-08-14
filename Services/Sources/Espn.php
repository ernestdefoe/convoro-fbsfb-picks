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
     * Every game on today's scoreboard that has kicked off.
     *
     * @return array{0: list<array{id: int, home: ?int, away: ?int, completed: bool}>, 1: string}
     */
    public function scoreboard(): array
    {
        [$status, $body] = $this->http->getJson(self::SCOREBOARD);

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

            $out[] = [
                'id' => $id,
                'home' => $home,
                'away' => $away,

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
