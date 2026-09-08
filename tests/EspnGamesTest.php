<?php

declare(strict_types=1);

/*
 * The ESPN adapter, and the league registry it reads.
 *
 * 🚨 Every fixture here is a REAL response, saved off ESPN in September 2026 —
 * a Commanders/Packers game, Timberwolves/Bucks, Braves/Phillies, Stars/Sabres
 * and Everton 2–2 Manchester United. ESPN publishes no documentation for this
 * API, so a hand-written payload would only prove the adapter agrees with
 * whoever imagined it, and the four shape differences the adapter exists to
 * absorb are exactly the ones nobody would think to imagine.
 *
 * 🚨 The SAME fixtures are used by the Flarum build's suite, asserting the same
 * things. The two adapters are ports of one idea, and this is what stops them
 * becoming two ideas.
 *
 * Nothing here touches the network: `Http` is replaced by one that reads a file.
 */

use Convoro\Extensions\Picks\Services\Http;
use Convoro\Extensions\Picks\Services\Leagues\League;
use Convoro\Extensions\Picks\Services\Leagues\Leagues;
use Convoro\Extensions\Picks\Services\Sources\EspnGames;

/**
 * 🚨 Only the wire is replaced. Everything the adapter actually does — the
 * flattening, the pivot, the side matching, the finished-game rule — is the
 * real code, which is the only reason this proves anything.
 */
final class FixtureHttp extends Http
{
    public string $file = '';

    public int $calls = 0;

    public function __construct()
    {
    }

    public function getJson(string $url, array $query = [], array $headers = []): array
    {
        $this->calls++;

        $json = file_get_contents(__DIR__ . '/fixtures/' . $this->file);

        return $json === false ? [404, []] : [200, json_decode($json, true)];
    }
}

$espn = static function (string $file): array {
    $http = new FixtureHttp();
    $http->file = $file;

    return [new EspnGames($http), $http];
};

$leagues = new Leagues();

return [
    'the registry answers, and an unknown league falls back' => function () use ($leagues) {
        /*
         * A season row naming a league that has since been removed is somebody's
         * install, not a programming error — and skipping it beats a scheduled
         * job that dies with every other league's fixtures still unsynced.
         */
        assertSame('cfb', $leagues->get('quidditch')->key);
        assertSame('cfb', $leagues->get(null)->key);
        assertSame('espn', $leagues->get('nfl')->provider);
        assertSame('cfbd', $leagues->get('cfb')->provider);

        /*
         * 🚨 College football and the NFL share a VOCABULARY and not a
         * provider. A recap of either says the same words about yards and
         * turnovers, which is why there is one `gridiron` rather than two
         * identical sports.
         */
        assertSame('gridiron', $leagues->get('cfb')->sport);
        assertSame('gridiron', $leagues->get('nfl')->sport);
        assertSame('soccer', $leagues->get('epl')->sport);

        // Weeks are a gridiron idea. Everything else is played to a date.
        assertTrue($leagues->get('nfl')->hasWeeks);
        assertFalse($leagues->get('nba')->hasWeeks);
        assertFalse($leagues->get('epl')->hasWeeks);
    },

    'a finished game is read from the state, never the status name' => function () use ($espn, $leagues) {
        /*
         * 🚨 THE bug this adapter exists to avoid. Soccer's finished game is
         * `STATUS_FULL_TIME`; baseball's is `STATUS_FINAL`; a match settled on
         * penalties is something else again. Matching on the name works for the
         * one sport it was written against and silently leaves every other
         * league's games permanently "in progress" — a thread that never gets
         * its recap and never says why.
         */
        [$provider] = $espn('espn-scoreboard-soccer.json');

        $games = $provider->games($leagues->get('epl'), 2026);

        assertTrue($games !== []);

        $everton = null;

        foreach ($games as $game) {
            if ($game['home'] === 'Everton') {
                $everton = $game;
            }
        }

        assertTrue($everton !== null, 'the Everton match is missing');
        assertTrue((bool) $everton['completed'], 'a full-time match was not read as finished');
        assertSame('post', $everton['status']);
        assertSame(2, $everton['home_score']);
        assertSame(2, $everton['away_score']);
        assertSame('Manchester United', $everton['away']);

        // A league with no weeks gets no week number invented for it.
        assertSame(null, $everton['week']);
    },

    'a gridiron league keeps its week' => function () use ($espn, $leagues) {
        [$provider] = $espn('espn-scoreboard-nfl.json');

        $games = $provider->games($leagues->get('nfl'), 2026, 1);

        assertTrue($games !== []);
        assertSame(1, $games[0]['week']);
        assertSame('regular', $games[0]['season_type']);
        assertTrue($games[0]['external_id'] !== '');
    },

    'an NFL box score lands in the shape the recap already reads' => function () use ($espn, $leagues) {
        /*
         * 🚨 The whole seam, in one assertion. ESPN's NFL statistic names are
         * IDENTICAL to CollegeFootballData's — `firstDowns`, `totalYards`,
         * `thirdDownEff`, `turnovers`, `possessionTime` — and so are its player
         * labels: `C/ATT`, `YDS`, `TD`, `INT`, `CAR`, `REC`. So an NFL game is
         * described by the gridiron vocabulary that already exists, with no new
         * words written for it at all.
         */
        [$provider] = $espn('espn-summary-nfl.json');

        $box = $provider->boxScore($leagues->get('nfl'), '401772936', 1);

        assertTrue($box !== null);
        assertSame(2, count($box['teams']));

        $stats = [];

        foreach ($box['teams'][0]['stats'] as $entry) {
            $stats[$entry['category']] = $entry['stat'];
        }

        foreach (['firstDowns', 'totalYards', 'thirdDownEff', 'turnovers', 'possessionTime'] as $key) {
            assertTrue(isset($stats[$key]), $key . ' is missing from an NFL box score');
        }

        // The ratio and the clock survive as themselves rather than as numbers.
        assertTrue(str_contains((string) $stats['thirdDownEff'], '-'));
        assertTrue(str_contains((string) $stats['possessionTime'], ':'));

        $passing = null;

        foreach ($box['players'][0]['categories'] as $category) {
            if ($category['name'] === 'passing') {
                $passing = $category;
            }
        }

        assertTrue($passing !== null, 'the passing category is missing');

        $byType = [];

        foreach ($passing['types'] as $type) {
            $byType[$type['name']] = $type['athletes'][0];
        }

        assertSame('Jayden Daniels', $byType['C/ATT']['name']);
        assertSame('24/42', $byType['C/ATT']['stat']);
        assertSame('200', $byType['YDS']['stat']);
        assertSame('2', $byType['TD']['stat']);
    },

    'a baseball box score is prefixed, because three groups say "hits"' => function () use ($espn, $leagues) {
        /*
         * 🚨 The trap the prefix exists for. ESPN answers baseball team
         * statistics as GROUPS, and `hits` appears in batting, pitching AND
         * fielding meaning hits made, hits allowed and hits handled in the
         * field. Flattened onto bare names the last group wins, and a fielding
         * figure is printed as the batting line — a number that looks entirely
         * plausible and is about something else.
         */
        [$provider] = $espn('espn-summary-mlb.json');

        $box = $provider->boxScore($leagues->get('mlb'), '401816843');

        assertTrue($box !== null);

        $stats = [];

        foreach ($box['teams'][0]['stats'] as $entry) {
            $stats[$entry['category']] = $entry['stat'];
        }

        foreach (['batting.hits', 'batting.runs', 'batting.homeRuns', 'pitching.strikeouts', 'fielding.errors'] as $key) {
            assertTrue(isset($stats[$key]), $key . ' is missing from a baseball box score');
        }

        assertFalse(isset($stats['hits']), 'an unprefixed "hits" survived — three groups would have fought over it');
        assertTrue($stats['batting.hits'] !== $stats['fielding.hits'], 'the groups collapsed into one another');

        /*
         * 🚨 And the player groups are named from `type`, not `name` — baseball
         * leaves `name` null and the NFL leaves `type` null. Reading only one of
         * them loses every category in the other half of the sports.
         */
        $names = array_column($box['players'][0]['categories'], 'name');

        assertTrue(in_array('batting', $names, true));
        assertTrue(in_array('pitching', $names, true));
    },

    'a basketball box score has one unnamed group and keeps it' => function () use ($espn, $leagues) {
        [$provider] = $espn('espn-summary-nba.json');

        $box = $provider->boxScore($leagues->get('nba'), '401705718');

        assertTrue($box !== null);

        $stats = [];

        foreach ($box['teams'][0]['stats'] as $entry) {
            $stats[$entry['category']] = $entry['stat'];
        }

        foreach (['fieldGoalPct', 'totalRebounds', 'assists', 'turnovers', 'pointsInPaint'] as $key) {
            assertTrue(isset($stats[$key]), $key . ' is missing from a basketball box score');
        }

        assertTrue(str_contains((string) $stats['fieldGoalsMade-fieldGoalsAttempted'], '-'));

        /*
         * 🚨 Basketball supplies NEITHER `name` NOR `type` on its single player
         * group, because there is only one. Dropping a nameless group would
         * lose every basketball player line there is.
         */
        assertSame(['general'], array_column($box['players'][0]['categories'], 'name'));
    },

    'a hockey box score names its groups from the other field again' => function () use ($espn, $leagues) {
        [$provider] = $espn('espn-summary-nhl.json');

        $box = $provider->boxScore($leagues->get('nhl'), '401803652');

        assertTrue($box !== null);

        $names = array_column($box['players'][0]['categories'], 'name');

        assertTrue(in_array('forwards', $names, true));
        assertTrue(in_array('goalies', $names, true));

        /*
         * 🚨 ESPN answers a FOURTH hockey group, `skaters`, with a full set of
         * column labels and NO athletes in it. Carrying it through would put a
         * heading over nothing in every hockey recap; a group with nobody in it
         * is not a group.
         */
        assertFalse(in_array('skaters', $names, true));
    },

    'the away team\'s players are not filed under the home team' => function () use ($espn, $leagues) {
        /*
         * 🚨 ESPN puts `homeAway` on the TEAM side of a box score and nowhere
         * on the player side, in every sport read. Defaulting both to "home"
         * would name the away team's players under the home team — wrong in a
         * way that reads perfectly plausibly and would never be noticed.
         */
        foreach (['nfl', 'mlb', 'nba', 'nhl'] as $sport) {
            [$provider] = $espn('espn-summary-' . $sport . '.json');

            $box = $provider->boxScore($leagues->get($sport), '1');

            assertTrue($box !== null, $sport . ': no box score');

            $sides = array_column($box['players'], 'homeAway');

            sort($sides);

            assertSame(['away', 'home'], $sides, $sport . ': the two player sides are not one of each');
        }
    },

    'a summary is fetched once per game, and only so many per run' => function () use ($espn, $leagues) {
        /*
         * 🚨 A box score here is ONE CALL PER GAME, unlike CollegeFootballData
         * which answers a whole week at once. A Saturday of college basketball
         * is a hundred and fifty games; without a ceiling, one scheduled job
         * would fire a hundred and fifty outbound requests inside a minute.
         * That has taken a site on this stack down before.
         */
        [$provider, $http] = $espn('espn-summary-nfl.json');
        $league = $leagues->get('nfl');

        $provider->boxScore($league, 'same-game', 1);
        $provider->boxScore($league, 'same-game', 1);

        assertSame(1, $http->calls, 'the same game was fetched twice');

        for ($i = 0; $i < EspnGames::MAX_SUMMARIES_PER_RUN + 10; $i++) {
            $provider->boxScore($league, 'game-' . $i, 1);
        }

        assertSame(EspnGames::MAX_SUMMARIES_PER_RUN, $http->calls, 'the per-run ceiling did not hold');
    },

    'a league no provider covers is skipped, not thrown at' => function () use ($espn) {
        [$provider] = $espn('espn-summary-nfl.json');

        $orphan = new League('orphan', 'Orphan', 'espn', '', 'gridiron', false);

        assertFalse($provider->supports($orphan));
        assertSame([], $provider->games($orphan, 2026));
        assertSame(null, $provider->boxScore($orphan, '1'));
    },
];
