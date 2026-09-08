<?php

declare(strict_types=1);

/*
 * Turning a provider's box score into one this system owns.
 *
 * 🚨 Run against a REAL answer, captured from collegefootballdata.com and kept
 * in `tests/fixtures/cfbd-box-score.json` — Notre Dame 41, Wisconsin 13, week
 * one of 2026. A hand-written fixture only ever proves the normaliser agrees
 * with whoever wrote the fixture, and the shape here is the whole difficulty:
 * a category holds a list of TYPES, and each type holds every athlete's figure
 * for it, so a quarterback's line is scattered across five separate lists.
 *
 * Nothing here touches the network.
 */

use Convoro\Engine\Convoro;
use Convoro\Extensions\Picks\Services\BoxScores;
use Convoro\Extensions\Picks\Services\Settings;
use Convoro\Extensions\Picks\Services\Sources\Cfbd;
use Convoro\Extensions\Picks\Services\Http;

$app = Convoro::getInstance();
$db = $app->make('db');

$fixture = json_decode(
    (string) file_get_contents(__DIR__ . '/fixtures/cfbd-box-score.json'),
    true
);

$service = static function () use ($app, $db): BoxScores {
    $settings = new Settings($db);

    return new BoxScores($db, new Cfbd(new Http(), $settings), $settings);
};

return [
    'both sides of a game come back, home and away' => function () use ($service, $fixture) {
        $document = $service()->normalise(
            (int) $fixture['game'],
            $fixture['teams'],
            $fixture['players'],
        );

        assertTrue($document !== null, 'a game with two sides normalises');
        assertSame('Notre Dame', $document['home']['team']);
        assertSame('Wisconsin', $document['away']['team']);
        assertSame(41, $document['home']['points']);
        assertSame(13, $document['away']['points']);
    },

    'team statistics keep the shape the feed gave them' => function () use ($service, $fixture) {
        $stats = $service()->normalise((int) $fixture['game'], $fixture['teams'], $fixture['players'])['home']['stats'];

        assertSame('350', $stats['totalYards']);
        assertSame('239', $stats['netPassingYards']);
        assertSame('111', $stats['rushingYards']);
        assertSame('20', $stats['firstDowns']);

        /*
         * 🚨 Strings, and these three are why. A ratio, a clock and a count sit
         * in the same list; casting them all to int turns `3-9` into 3 and
         * `31:36` into 31 — both plausible-looking numbers, both wrong, and
         * neither an error anything would catch.
         */
        assertSame('3-9', $stats['thirdDownEff']);
        assertSame('31:36', $stats['possessionTime']);
        assertSame('4-41', $stats['totalPenaltiesYards']);
    },

    'a scattered quarterback becomes one line' => function () use ($service, $fixture) {
        $leaders = $service()->normalise((int) $fixture['game'], $fixture['teams'], $fixture['players'])['home']['leaders'];

        assertSame('C.J. Carr', $leaders['passing']['name']);
        assertSame('19/29', $leaders['passing']['stats']['C/ATT']);
        assertSame('239', $leaders['passing']['stats']['YDS']);
        assertSame('2', $leaders['passing']['stats']['TD']);
        assertSame('0', $leaders['passing']['stats']['INT']);
    },

    'the leader of a category is the one who led it' => function () use ($service, $fixture) {
        $document = $service()->normalise((int) $fixture['game'], $fixture['teams'], $fixture['players']);

        // 90 yards on 20 carries, against three team-mates who had fewer.
        assertSame('Aneyas Williams', $document['home']['leaders']['rushing']['name']);
        assertSame('90', $document['home']['leaders']['rushing']['stats']['YDS']);

        // And the away side gets its own, not the home side's.
        assertTrue($document['away']['leaders']['rushing']['name'] !== 'Aneyas Williams');
    },

    'only the categories worth naming somebody in are kept' => function () use ($service, $fixture) {
        $leaders = $service()->normalise((int) $fixture['game'], $fixture['teams'], $fixture['players'])['home']['leaders'];

        /*
         * The feed carries ten categories including punt returns and fumbles.
         * A recap that names a punt returner is a recap nobody finishes, so
         * four are kept — and the rest are dropped here rather than filtered
         * again by every reader.
         */
        assertSame(['passing', 'rushing', 'receiving', 'defensive'], array_keys($leaders));
    },

    'a game missing a side is refused rather than half-stored' => function () use ($service, $fixture) {
        /*
         * 🚨 A box score with one team's numbers and a blank column beside them
         * reads as the other team having done nothing, which is worse than
         * having no box score at all — and it would satisfy `has()`, so nothing
         * would ever come back for the rest of it.
         */
        assertSame(null, $service()->normalise(
            (int) $fixture['game'],
            [$fixture['teams'][0]],
            $fixture['players'],
        ));
    },

    'a side with no player statistics still gets its team ones' => function () use ($service, $fixture) {
        // Lower-division games routinely arrive with team stats and no player
        // breakdown at all. That is a thinner box score, not a failure.
        $document = $service()->normalise((int) $fixture['game'], $fixture['teams'], []);

        assertTrue($document !== null);
        assertSame([], $document['home']['leaders']);
        assertSame('350', $document['home']['stats']['totalYards']);
    },
];
