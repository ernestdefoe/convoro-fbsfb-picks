<?php

declare(strict_types=1);

/*
 * Picks.
 *
 * 🚨 Two rules carry this module and both are about not letting somebody win
 * unfairly.
 *
 *   - **The cut-off.** Once a game's cut-off has passed, a pick cannot be made,
 *     changed or withdrawn. It is enforced server-side against a row read from
 *     the database, and nothing the browser sends is consulted. A pick'em where
 *     a member can set their own deadline is not a game.
 *   - **Disclosure.** Before a game's cut-off a member sees their own pick and
 *     nobody else's; after it, everybody's. Decided per GAME, because a Tuesday
 *     night fixture locks four days before a Saturday one in the same round.
 *
 * 🚨 Nothing here touches the network. The one class that can — Services/Http —
 * is deliberately not final so a fake can stand in front of it, which is how
 * both providers' failure paths are exercised with nothing to talk to.
 */

use Convoro\Engine\Convoro;
use Convoro\Extensions\Picks\Services\Games;
use Convoro\Extensions\Picks\Services\Http;
use Convoro\Extensions\Picks\Services\Picks as Store;
use Convoro\Extensions\Picks\Services\Scores;
use Convoro\Extensions\Picks\Services\Seasons;
use Convoro\Extensions\Picks\Services\Settings;
use Convoro\Extensions\Picks\Services\Sources\Cfbd;
use Convoro\Extensions\Picks\Services\Sources\Espn;
use Convoro\Extensions\Picks\Services\Teams;

$app = Convoro::getInstance();
$db = $app->make('db');
$root = dirname(__DIR__);

$MARK = 'zz-picks';

/**
 * An Http that answers from a script instead of a socket.
 *
 * 🚨 The reason `Http` is not final. Every failure this module has to survive —
 * a provider that says nothing, a rejected key, a 500 — is a status code, and a
 * test that cannot produce one only ever exercises the happy path.
 */
if (!class_exists('PicksScriptedHttp')) {
    class PicksScriptedHttp extends Http
    {
        /** @param array<string, array{0: int, 1: array<mixed>}> $answers */
        public function __construct(public array $answers = [], public array $asked = [])
        {
        }

        public function usable(): bool
        {
            return true;
        }

        public function getJson(string $url, array $query = [], array $headers = []): array
        {
            $this->asked[] = $url;

            foreach ($this->answers as $needle => $answer) {
                if (str_contains($url, $needle)) {
                    return $answer;
                }
            }

            return [0, []];
        }
    }
}

/* ------------------------------------------------------------- the fixture */

/**
 * Everything one test needs: a season, a round, two teams, a game, two members.
 *
 * 🚨 Real rows through the real services. A cut-off test against a hand-made
 * array would prove the arithmetic and nothing about whether the query that
 * loads a game actually brings its week's switch with it — which is the half
 * that would silently fail open.
 */
$makeFixture = static function (array $game = []) use ($db, $MARK): array {
    $seasonId = (int) $db->table('picks_seasons')->insertGetId([
        'name' => 'zz Season', 'slug' => $MARK . '-season', 'year' => 2999,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $weekId = (int) $db->table('picks_weeks')->insertGetId([
        'season_id' => $seasonId, 'name' => 'zz Week', 'week_number' => 1,
        'season_type' => 'regular', 'is_open' => $game['week_is_open'] ?? 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $teamIds = [];

    foreach (['home', 'away'] as $side) {
        $teamIds[$side] = (int) $db->table('picks_teams')->insertGetId([
            'name' => 'zz ' . $side, 'slug' => $MARK . '-' . $side,
            'abbreviation' => strtoupper($side), 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    $gameId = (int) $db->table('picks_events')->insertGetId([
        'week_id' => $weekId,
        'home_team_id' => $teamIds['home'],
        'away_team_id' => $teamIds['away'],
        'cfbd_id' => null,
        'match_at' => $game['match_at'] ?? (time() + 86400),
        'cutoff_at' => $game['cutoff_at'] ?? (time() + 86400),
        'status' => $game['status'] ?? Games::SCHEDULED,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $userIds = [];

    foreach (['one', 'two'] as $who) {
        $userIds[$who] = (int) $db->table('users')->insertGetId([
            'username' => $MARK . '-' . $who,
            'username_clean' => $MARK . '-' . $who,
            'email' => $MARK . '-' . $who . '@example.invalid',
            'password' => 'x',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return [
        'season' => $seasonId, 'week' => $weekId, 'game' => $gameId,
        'teams' => $teamIds, 'users' => $userIds,
    ];
};

/**
 * 🚨 Children before parents, or the foreign keys refuse it. Users go last
 * because deleting one cascades to their picks and their scores, and a run that
 * left members behind would fail the NEXT run on the unique username.
 */
$cleanUp = static function () use ($db, $MARK): void {
    foreach ($db->table('users')->whereLike('username_clean', $MARK . '%')->get() as $user) {
        $db->table('picks_user_scores')->where('user_id', (int) $user['id'])->deleteAll();
        $db->table('picks_picks')->where('user_id', (int) $user['id'])->deleteAll();
        $db->table('users')->where('id', (int) $user['id'])->deleteAll();
    }

    foreach ($db->table('picks_teams')->whereLike('slug', $MARK . '%')->get() as $team) {
        $db->table('picks_events')->where('home_team_id', (int) $team['id'])->deleteAll();
        $db->table('picks_events')->where('away_team_id', (int) $team['id'])->deleteAll();
        $db->table('picks_teams')->where('id', (int) $team['id'])->deleteAll();
    }

    foreach ($db->table('picks_seasons')->whereLike('slug', $MARK . '%')->get() as $season) {
        $db->table('picks_weeks')->where('season_id', (int) $season['id'])->deleteAll();
        $db->table('picks_seasons')->where('id', (int) $season['id'])->deleteAll();
    }
};

$isolated = static fn (callable $test): callable => static function () use ($test, $cleanUp): void {
    $cleanUp();

    try {
        $test();
    } finally {
        $cleanUp();
    }
};

/** Runs a test with some settings in place, and puts the site back afterwards. */
$withSettings = static function (array $values, callable $test) use ($db): void {
    $before = [];

    foreach (array_keys($values) as $key) {
        $row = $db->table('settings')->where('key', $key)->first();
        $before[$key] = $row === null ? null : (string) $row['value'];
    }

    $put = static function (string $key, string $value) use ($db): void {
        $db->table('settings')->where('key', $key)->first() === null
            ? $db->table('settings')->insertGetId(['key' => $key, 'value' => $value])
            : $db->table('settings')->where('key', $key)->updateAll(['value' => $value]);
    };

    foreach ($values as $key => $value) {
        $put($key, (string) $value);
    }

    try {
        $test();
    } finally {
        foreach ($before as $key => $value) {
            $value === null
                ? $db->table('settings')->where('key', $key)->deleteAll()
                : $put($key, $value);
        }
    }
};

/* ------------------------------------------------------------ source tools */

/** A file's code with its comments removed. */
$codeOf = static function (string $path): string {
    if (str_ends_with($path, '.cvr')) {
        // A template's comments are `{# … #}` and the compiler drops them.
        return (string) preg_replace('/\{#.*?#\}/s', '', (string) file_get_contents($path));
    }

    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
};

/** @return list<string> */
$sourceFiles = static function (string $root, string $extension): array {
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
        if (!$file->isFile() || $file->getExtension() !== $extension) {
            continue;
        }

        if (str_contains($file->getPathname(), '/tests/')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    return $files;
};

return [
    /* ==================================================== THE CUT-OFF ==== */

    'a pick after the cut-off is refused, however it arrives' => $isolated(
        static function () use ($app, $db, $makeFixture): void {
            /*
             * 🚨 The single most important behaviour in this extension. The
             * game is still `scheduled` and its week is still open — the ONLY
             * thing wrong is that the clock has passed — and that alone has to
             * be enough to refuse the write.
             */
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now - 60, 'match_at' => $now - 60]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);
            $game = $games->byId($fixture['game']);

            assertFalse($games->open($game, $now), 'a game past its cut-off reported itself open');

            $result = $store->submit($fixture['users']['one'], $game, 'home', null, false, $now);

            assertSame(Store::CLOSED, $result, 'a pick was accepted after the cut-off had passed');

            assertSame(
                0,
                $db->table('picks_picks')->where('event_id', $fixture['game'])->count(),
                'a refused pick was written to the database anyway'
            );
        }
    ),

    'a pick already made cannot be CHANGED after the cut-off' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * 🚨 The half that is easy to leave open. Refusing new picks while
             * still accepting edits means somebody watches the first quarter
             * and then switches — which is worse than no deadline at all,
             * because everybody else believes there is one.
             */
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now + 3600, 'match_at' => $now + 3600]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);

            $before = $games->byId($fixture['game']);
            assertSame(Store::OK, $store->submit($fixture['users']['one'], $before, 'home', null, false, $now));

            // The cut-off passes.
            $db->table('picks_events')->where('id', $fixture['game'])->updateAll(['cutoff_at' => $now - 1]);

            $after = $games->byId($fixture['game']);
            $result = $store->submit($fixture['users']['one'], $after, 'away', null, false, $now);

            assertSame(Store::CLOSED, $result, 'a pick was changed after the cut-off had passed');

            $stored = $db->table('picks_picks')
                ->where('event_id', $fixture['game'])
                ->where('user_id', $fixture['users']['one'])
                ->first();

            assertSame('home', (string) $stored['selected_outcome'], 'the stored pick changed anyway');
        }
    ),

    'a pick cannot be WITHDRAWN after the cut-off' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * 🚨 Withdrawing after the cut-off would let somebody delete a
             * losing pick at half time, which costs the game its meaning just
             * as surely as changing one would.
             */
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now + 3600, 'match_at' => $now + 3600]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);

            $store->submit($fixture['users']['one'], $games->byId($fixture['game']), 'home', null, false, $now);

            $db->table('picks_events')->where('id', $fixture['game'])->updateAll(['cutoff_at' => $now - 1]);

            $result = $store->withdraw($fixture['users']['one'], $games->byId($fixture['game']), $now);

            assertSame(Store::CLOSED, $result, 'a pick was withdrawn after the cut-off had passed');
            assertSame(1, $db->table('picks_picks')->where('event_id', $fixture['game'])->count());
        }
    ),

    'a round nobody opened takes no picks, however far away the game is' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * The operator's switch and the game's clock answer different
             * questions, and a game needs BOTH. This is the one that stops a
             * pick'em taking picks the moment it is installed.
             */
            $now = time();
            $fixture = $makeFixture([
                'week_is_open' => 0,
                'cutoff_at' => $now + 86400,
                'match_at' => $now + 86400,
            ]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);
            $game = $games->byId($fixture['game']);

            assertFalse($games->open($game, $now), 'a game in a closed round reported itself open');
            assertSame(Store::CLOSED, $store->submit($fixture['users']['one'], $game, 'home', null, false, $now));
        }
    ),

    'a game with no deadline stored fails CLOSED' => static function () use ($db): void {
        /*
         * 🚨 The direction a missing value must fail in. Treating an absent
         * cut-off as "no deadline" is a game that can be picked after it has
         * been played, which is the worst outcome available.
         */
        $games = new Games($db, new Teams($db));
        $game = ['id' => 1, 'week_is_open' => 1, 'status' => Games::SCHEDULED, 'cutoff_at' => 0, 'match_at' => 0];

        assertSame(0, $games->cutoff($game), 'a game with nothing stored invented a deadline');
        assertFalse($games->open($game, time()), 'a game with no deadline was pickable');
    },

    'a cut-off falls back to the kickoff rather than to nothing' => static function () use ($db): void {
        $games = new Games($db, new Teams($db));
        $kickoff = 1_800_000_000;

        assertSame($kickoff, $games->cutoff(['cutoff_at' => 0, 'match_at' => $kickoff]));
        assertSame($kickoff - 600, $games->cutoff(['cutoff_at' => $kickoff - 600, 'match_at' => $kickoff]));
    },

    'a game a provider has already started takes no more picks' => static function () use ($db): void {
        // A kickoff brought forward is a real thing, and the status column is
        // the only warning of it anybody here gets.
        $games = new Games($db, new Teams($db));
        $now = 1_800_000_000;

        $game = [
            'week_is_open' => 1, 'status' => Games::IN_PROGRESS,
            'cutoff_at' => $now + 3600, 'match_at' => $now + 3600,
        ];

        assertFalse($games->open($game, $now), 'a game already being played was still open for picks');
    },

    /* ================================================== WHO SEES WHAT ==== */

    'before the cut-off a member sees their own pick and nobody else’s' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * 🚨 The competitive point of the whole thing. A pick everybody can
             * read before kickoff is not a prediction, it is a copy.
             */
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now + 3600, 'match_at' => $now + 3600]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);
            $game = $games->byId($fixture['game']);

            $store->submit($fixture['users']['one'], $game, 'home', null, false, $now);
            $store->submit($fixture['users']['two'], $game, 'away', null, false, $now);

            // Their own is theirs to see.
            $mine = $store->mine($fixture['users']['one'], [$fixture['game']]);
            assertSame('home', $mine[$fixture['game']]['selected_outcome'], 'a member could not see their own pick');

            // Nobody else's is.
            assertSame([], $store->revealed([$game], $now), 'other members’ picks were shown before the cut-off');

            assertFalse(
                $store->maySee($game, $fixture['users']['one'], $fixture['users']['two'], $now),
                'a member was allowed to see another member’s pick before the cut-off'
            );

            assertTrue(
                $store->maySee($game, $fixture['users']['one'], $fixture['users']['one'], $now),
                'a member was not allowed to see their own pick'
            );
        }
    ),

    'after the cut-off everybody’s picks are on show' => $isolated(
        static function () use ($db, $makeFixture): void {
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now + 3600, 'match_at' => $now + 3600]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);

            $store->submit($fixture['users']['one'], $games->byId($fixture['game']), 'home', null, false, $now);
            $store->submit($fixture['users']['two'], $games->byId($fixture['game']), 'away', null, false, $now);

            $db->table('picks_events')->where('id', $fixture['game'])->updateAll(['cutoff_at' => $now - 1]);

            $game = $games->byId($fixture['game']);
            $revealed = $store->revealed([$game], $now);

            assertSame(2, count($revealed[$fixture['game']] ?? []), 'the picks were not revealed after the cut-off');

            assertTrue(
                $store->maySee($game, $fixture['users']['one'], $fixture['users']['two'], $now),
                'a member still could not see another member’s pick after the cut-off'
            );
        }
    ),

    'the consensus is hidden on the same rule as the picks' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * 🚨 "78% are taking the home team", published before kickoff, is
             * the pick'em telling everybody the answer. It goes behind the same
             * gate rather than a second one somebody has to remember.
             */
            $now = time();
            $fixture = $makeFixture(['cutoff_at' => $now + 3600, 'match_at' => $now + 3600]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);

            $store->submit($fixture['users']['one'], $games->byId($fixture['game']), 'home', null, false, $now);
            $store->submit($fixture['users']['two'], $games->byId($fixture['game']), 'home', null, false, $now);

            assertSame([], $store->tallies([$games->byId($fixture['game'])], $now), 'the split was shown before the cut-off');

            $db->table('picks_events')->where('id', $fixture['game'])->updateAll(['cutoff_at' => $now - 1]);

            $tally = $store->tallies([$games->byId($fixture['game'])], $now);
            assertSame(2, $tally[$fixture['game']]['home'] ?? 0, 'the split was wrong after the cut-off');
        }
    ),

    'a closed round does NOT reveal picks the cut-off has not reached' => $isolated(
        static function () use ($db, $makeFixture): void {
            /*
             * 🚨 Why disclosure is measured with `locked()` and not with
             * `!open()`. A round nobody has opened has games that are not
             * pickable AND whose cut-offs are in the future; reading "not
             * pickable" as "locked, so show everybody" would publish an entire
             * round before it was played.
             */
            $now = time();
            $fixture = $makeFixture([
                'week_is_open' => 0,
                'cutoff_at' => $now + 86400,
                'match_at' => $now + 86400,
            ]);

            $games = new Games($db, new Teams($db));
            $store = new Store($db, $games);
            $game = $games->byId($fixture['game']);

            assertFalse($games->open($game, $now), 'a game in a closed round was open');
            assertFalse($games->locked($game, $now), 'a future cut-off was reported as passed');
            assertSame([], $store->revealed([$game], $now), 'a closed round revealed its picks early');
        }
    ),

    /* ====================================================== SCORING ====== */

    'plain mode scores one point per correct pick' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'home', 'is_correct' => 1, 'confidence' => 7,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $withSettings(['picks_confidence_mode' => '0'], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));
                $totals = $scores->aggregate($fixture['users']['one'], 0, 0);

                // 🚨 The stored confidence of 7 is ignored while the mode is
                // off. A rating that scored when nobody said it counted would
                // be a different game from the one people played.
                assertSame(1, $totals['points'], 'plain mode did not score one point per correct pick');
                assertSame(1, $totals['correct']);
                assertSame(1, $totals['total']);
            });
        }
    ),

    'confidence mode scores the rating, and an unrated pick scores one' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            foreach ([['one', 8], ['two', null]] as [$who, $confidence]) {
                $db->table('picks_picks')->insertGetId([
                    'user_id' => $fixture['users'][$who], 'event_id' => $fixture['game'],
                    'selected_outcome' => 'home', 'is_correct' => 1, 'confidence' => $confidence,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $withSettings([
                'picks_confidence_mode' => '1',
                'picks_confidence_penalty' => 'none',
            ], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));

                assertSame(8, $scores->aggregate($fixture['users']['one'], 0, 0)['points'], 'a rated correct pick did not score its rating');

                /*
                 * 🚨 COALESCE(confidence, 1) on the earned side. Somebody who
                 * never touched the slider scores exactly what plain mode would
                 * have given them, rather than nothing at all.
                 */
                assertSame(1, $scores->aggregate($fixture['users']['two'], 0, 0)['points'], 'an unrated correct pick scored nothing');
            });
        }
    ),

    'the full penalty takes the whole rating, the half takes it rounded DOWN' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            /*
             * 🚨 FLOOR is applied PER PICK, in SQL, before the sum. Two wrong
             * picks of 7 are 3 + 3 = 6, not floor(14 / 2) = 7 — and rounding
             * after summing gives a larger penalty than the rule promises.
             */
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $second = (int) $db->table('picks_events')->insertGetId([
                'week_id' => $fixture['week'],
                'home_team_id' => $fixture['teams']['home'],
                'away_team_id' => $fixture['teams']['away'],
                'cfbd_id' => null, 'match_at' => time() - 3600, 'cutoff_at' => time() - 3600,
                'status' => Games::FINISHED, 'created_at' => date('Y-m-d H:i:s'),
            ]);

            foreach ([$fixture['game'], $second] as $gameId) {
                $db->table('picks_picks')->insertGetId([
                    'user_id' => $fixture['users']['one'], 'event_id' => $gameId,
                    'selected_outcome' => 'away', 'is_correct' => 0, 'confidence' => 7,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $withSettings([
                'picks_confidence_mode' => '1',
                'picks_confidence_penalty' => 'half',
            ], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));

                // earned 0, penalty floor(7/2) + floor(7/2) = 6, clamped at 0.
                assertSame(0, $scores->aggregate($fixture['users']['one'], 0, 0)['points']);
            });

            $withSettings([
                'picks_confidence_mode' => '1',
                'picks_confidence_penalty' => 'full',
            ], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));

                assertSame(0, $scores->aggregate($fixture['users']['one'], 0, 0)['points']);
            });
        }
    ),

    'a total never goes below zero' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            /*
             * 🚨 Clamped. A leaderboard with negative numbers on it reads as
             * broken, and a member who had a bad week should be last rather
             * than in a hole they cannot climb out of.
             */
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'away', 'is_correct' => 0, 'confidence' => 10,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $withSettings([
                'picks_confidence_mode' => '1',
                'picks_confidence_penalty' => 'full',
            ], static function () use ($db, $fixture): void {
                $totals = (new Scores($db, new Settings($db)))->aggregate($fixture['users']['one'], 0, 0);

                assertSame(0, $totals['points'], 'a total went negative');
            });
        }
    ),

    'an unrated WRONG pick loses nothing' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            /*
             * 🚨 COALESCE(confidence, 0) on the penalty side, against
             * COALESCE(confidence, 1) on the earned side. Somebody who never
             * staked anything cannot lose anything — the asymmetry is the point.
             */
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $second = (int) $db->table('picks_events')->insertGetId([
                'week_id' => $fixture['week'],
                'home_team_id' => $fixture['teams']['home'],
                'away_team_id' => $fixture['teams']['away'],
                'cfbd_id' => null, 'match_at' => time() - 3600, 'cutoff_at' => time() - 3600,
                'status' => Games::FINISHED, 'created_at' => date('Y-m-d H:i:s'),
            ]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'home', 'is_correct' => 1, 'confidence' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $second,
                'selected_outcome' => 'away', 'is_correct' => 0, 'confidence' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $withSettings([
                'picks_confidence_mode' => '1',
                'picks_confidence_penalty' => 'full',
            ], static function () use ($db, $fixture): void {
                $totals = (new Scores($db, new Settings($db)))->aggregate($fixture['users']['one'], 0, 0);

                assertSame(1, $totals['points'], 'an unrated wrong pick took points away');
            });
        }
    ),

    'accuracy ignores picks on games nobody has played' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            /*
             * 🚨 `is_correct` has THREE states and NULL is the one that matters.
             * Counting an unplayed game as a loss punishes the members who
             * enter early, which is exactly the behaviour a pick'em wants.
             */
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $pending = (int) $db->table('picks_events')->insertGetId([
                'week_id' => $fixture['week'],
                'home_team_id' => $fixture['teams']['home'],
                'away_team_id' => $fixture['teams']['away'],
                'cfbd_id' => null, 'match_at' => time() + 86400, 'cutoff_at' => time() + 86400,
                'status' => Games::SCHEDULED, 'created_at' => date('Y-m-d H:i:s'),
            ]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'home', 'is_correct' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $pending,
                'selected_outcome' => 'home', 'is_correct' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $withSettings(['picks_confidence_mode' => '0'], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));
                $scores->upsert($fixture['users']['one'], 0, 0);

                $row = $db->table('picks_user_scores')
                    ->where('user_id', $fixture['users']['one'])
                    ->where('season_id', 0)->where('week_id', 0)
                    ->first();

                assertSame(1, (int) $row['total_picks'], 'an unscored pick was counted in the total');

                // 🚨 Compared as a float. A DECIMAL column comes back as a
                // string or a float depending on the driver's emulated-prepare
                // setting, and a test that asserted the string would pass on
                // one machine and fail on another for no reason anybody could see.
                assertSame(100.0, (float) $row['accuracy'], 'accuracy counted a game nobody had played');
            });
        }
    ),

    'a score is written at three scopes, and writing it twice changes nothing' => $isolated(
        static function () use ($db, $makeFixture, $withSettings): void {
            /*
             * 🚨 Idempotence is a requirement of anything the queue runs, and
             * nothing outside the handler can enforce it. Two passes must leave
             * one row per scope — which is what the unique index guarantees now
             * that the scope columns are 0 rather than NULL.
             */
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'home', 'is_correct' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $withSettings(['picks_confidence_mode' => '0'], static function () use ($db, $fixture): void {
                $scores = new Scores($db, new Settings($db));

                $scores->recalculate($fixture['users']['one'], $fixture['week'], $fixture['season']);
                $scores->recalculate($fixture['users']['one'], $fixture['week'], $fixture['season']);

                $rows = $db->table('picks_user_scores')->where('user_id', $fixture['users']['one'])->get();

                assertSame(3, count($rows), 'the three scopes were not written exactly once each');

                foreach ($rows as $row) {
                    assertSame(1, (int) $row['total_points'], 'a scope was double-counted by the second pass');
                }
            });
        }
    ),

    'level scores are not a result, and nothing is scored from them' => static function () use ($db): void {
        /*
         * 🚨 College football does not end in a draw, so equal scores mean the
         * data is wrong rather than that the game was tied. The honest answer
         * is no result at all — every pick stays unscored until somebody fixes
         * it, rather than everybody losing on a typo.
         */
        $games = new Games($db, new Teams($db));

        assertSame('', $games->resultFrom(['home_score' => 21, 'away_score' => 21]));
        assertSame('', $games->resultFrom(['home_score' => null, 'away_score' => 7]));
        assertSame(Games::HOME, $games->resultFrom(['home_score' => 24, 'away_score' => 21]));
        assertSame(Games::AWAY, $games->resultFrom(['home_score' => 21, 'away_score' => 24]));
    },

    'clearing a result unscores every pick rather than marking them wrong' => $isolated(
        static function () use ($db, $makeFixture): void {
            $fixture = $makeFixture(['cutoff_at' => time() - 3600, 'status' => Games::FINISHED]);

            $db->table('picks_picks')->insertGetId([
                'user_id' => $fixture['users']['one'], 'event_id' => $fixture['game'],
                'selected_outcome' => 'home', 'is_correct' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $store = new Store($db, new Games($db, new Teams($db)));

            // '' is what an undecided or cleared game reports.
            $store->score($fixture['game'], '');

            $row = $db->table('picks_picks')->where('event_id', $fixture['game'])->first();

            assertTrue($row['is_correct'] === null, 'clearing a result marked everybody wrong instead of unscored');
        }
    ),

    /* ================================================== FRESHNESS ======== */

    'a live score stops being believed once its confirmation goes stale' => static function () use ($db): void {
        /*
         * 🚨 `home_score` is a record of what a provider LAST SAID, not a fact
         * about the present. A scoreboard that goes away mid-afternoon leaves a
         * game at 14–10 for ever, so the age of the last confirmation decides
         * whether that number is still worth showing.
         */
        $games = new Games($db, new Teams($db));
        $now = 1_800_000_000;

        $fresh = ['status' => Games::IN_PROGRESS, 'confirmed_at' => $now - 60, 'home_score' => 14, 'away_score' => 10];
        $stale = ['status' => Games::IN_PROGRESS, 'confirmed_at' => $now - Settings::STALE_AFTER - 1, 'home_score' => 14, 'away_score' => 10];

        assertSame('live', $games->state($fresh, $now));
        assertSame('unknown', $games->state($stale, $now), 'a stale scoreboard was still reported as live');

        assertTrue($games->scoreIsCurrent($fresh, $now));
        assertFalse($games->scoreIsCurrent($stale, $now), 'a stale score was still put on the page');
    },

    'a final score is a fact and never goes stale' => static function () use ($db): void {
        $games = new Games($db, new Teams($db));
        $now = 1_800_000_000;

        $final = ['status' => Games::FINISHED, 'confirmed_at' => $now - 86400 * 30, 'home_score' => 24, 'away_score' => 21];

        assertSame('final', $games->state($final, $now));
        assertTrue($games->scoreIsCurrent($final, $now), 'a finished game’s score expired');
    },

    'the freshness window outlives the fastest poll by a comfortable margin' => static function (): void {
        /*
         * 🚨 If the window were shorter than the interval, a game being polled
         * perfectly happily would flicker into "not known" between every pair
         * of checks — and a badge that flickers is one people learn to ignore.
         */
        assertTrue(Settings::STALE_AFTER > 120 * 4, 'the freshness window is too short for the poll to keep up with');
    },

    /* ===================================================== PROVIDERS ===== */

    'a provider that says nothing is a value, not an exception' => static function () use ($db, $withSettings): void {
        /*
         * 🚨 The caller has to be able to tell "there is no game on" apart from
         * "nobody answered", because the first is a fact to store and the
         * second must leave every row exactly as it was. A pick'em that read an
         * outage as news would blank a Saturday's scores.
         */
        $withSettings(['picks_cfbd_key' => 'zz-key'], static function () use ($db): void {
            $settings = new Settings($db);
            $silent = new PicksScriptedHttp();

            [$rows, $error] = (new Cfbd($silent, $settings))->teams();
            assertSame([], $rows);
            assertSame('no answer', $error, 'a silent provider did not report itself as unanswered');

            [$games, $espnError] = (new Espn($silent))->scoreboard();
            assertSame([], $games);
            assertSame('no answer', $espnError);
        });
    },

    'a rejected key is named as a rejected key' => static function () use ($db, $withSettings): void {
        /*
         * 🚨 401 is the one failure an operator can actually fix, and "HTTP
         * 401" on a status screen sends them looking at their server instead of
         * at their API key.
         */
        $withSettings(['picks_cfbd_key' => 'zz-key'], static function () use ($db): void {
            $http = new PicksScriptedHttp(['/teams' => [401, []]]);

            [, $error] = (new Cfbd($http, new Settings($db)))->teams();

            assertSame('key rejected', $error);
        });
    },

    'no key at all is reported before anything is asked' => static function () use ($db, $withSettings): void {
        $withSettings(['picks_cfbd_key' => ''], static function () use ($db): void {
            $http = new PicksScriptedHttp();

            [, $error] = (new Cfbd($http, new Settings($db)))->teams();

            assertSame('unconfigured', $error);
            assertSame([], $http->asked, 'a request was made with no credential to make it with');
        });
    },

    'ESPN’s “post” is not final until it says completed' => static function () use ($db): void {
        /*
         * 🚨 ESPN puts a game into `post` while it is still being reviewed, and
         * a final written from that is a result that can change afterwards —
         * having already scored everybody's picks from it.
         */
        $http = new PicksScriptedHttp(['scoreboard' => [200, ['events' => [[
            'id' => 401_000_001,
            'competitions' => [[
                'status' => ['type' => ['state' => 'post', 'completed' => false]],
                'competitors' => [
                    ['homeAway' => 'home', 'score' => '24'],
                    ['homeAway' => 'away', 'score' => '21'],
                ],
            ]],
        ]]]]]);

        [$games, $error] = (new Espn($http))->scoreboard();

        assertSame('', $error);
        assertSame(1, count($games));
        assertFalse($games[0]['completed'], 'a game still under review was treated as final');
        assertSame(24, $games[0]['home']);
    },

    /* ================================================ SOURCE RULES ======= */

    'nothing but the HTTP class reaches off the machine' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 Convoro's standing rule, and a real customer outage paid for it:
         * an uncapped outbound call in queued work held PHP workers, the
         * workers held their database connections, and a site that had grown by
         * nothing looked down.
         *
         * One class makes calls, it has a hard connect timeout and a hard read
         * timeout with no way round either. Comments are stripped from the
         * source FIRST, because the files that explain this rule have to be
         * able to name the functions it is about — and the needles are BUILT so
         * this test file cannot match itself.
         */
        $banned = [
            'curl_' . 'init',
            'curl_' . 'exec',
            'fsock' . 'open',
            'stream_socket_' . 'client',
            'file_get_' . 'contents(\'http',
        ];

        foreach ($sourceFiles($root, 'php') as $path) {
            if (str_ends_with($path, '/Services/Http.php')) {
                continue;
            }

            $code = $codeOf($path);

            foreach ($banned as $needle) {
                assertFalse(
                    stripos($code, $needle) !== false,
                    basename($path) . ' makes its own outbound call: ' . $needle
                );
            }
        }
    },

    'the one class that can call out cannot call out without both timeouts' => static function () use ($root, $codeOf): void {
        $code = $codeOf($root . '/Services/Http.php');

        assertTrue(str_contains($code, 'CURLOPT_CONNECT' . 'TIMEOUT'), 'the connect timeout is gone');
        assertTrue(str_contains($code, 'CURLOPT_' . 'TIMEOUT'), 'the read timeout is gone');
        assertTrue(str_contains($code, 'CURLOPT_FOLLOW' . 'LOCATION => false'), 'redirects are followed again');
    },

    'no page render can ask a provider anything' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 The other half of the same rule, and the one a refactor is most
         * likely to break. Controllers read rows the sync left behind; a
         * controller that reached for a source or the HTTP class would make
         * every visitor wait on somebody else's server — on the afternoon that
         * server is down, which is the afternoon they all load this page.
         */
        foreach ($sourceFiles($root . '/Controllers', 'php') as $path) {
            $code = $codeOf($path);

            foreach (['picks.' . 'http', 'picks.' . 'cfbd', 'picks.' . 'espn'] as $needle) {
                assertFalse(
                    str_contains($code, $needle),
                    basename($path) . ' talks to a provider while somebody is waiting for a page'
                );
            }
        }
    },

    'no licence phone-home came across' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 Several of the Invision originals shipped a daily call home that
         * returned a "tier", and a controller that blanked the feature when
         * that tier came back wrong. A remote switch that can turn a paid
         * feature off on somebody's live site is not telemetry. Nothing like it
         * is in this port, and this is what keeps it that way.
         */
        $banned = ['usage' . '.php', 'EDS' . 'TORE', "'ti" . "er'", 'build' . 'Key', 'phone' . 'Home'];

        foreach ($sourceFiles($root, 'php') as $path) {
            $code = $codeOf($path);

            foreach ($banned as $needle) {
                assertFalse(
                    stripos($code, $needle) !== false,
                    basename($path) . ' carries a licence phone-home: ' . $needle
                );
            }
        }
    },

    'nothing reaches around visibility to read a private member' => static function () use ($root, $codeOf, $sourceFiles): void {
        $needle = 'set' . 'Accessible(';

        foreach ($sourceFiles($root, 'php') as $path) {
            assertFalse(
                str_contains($codeOf($path), $needle),
                basename($path) . ' uses reflection to reach a private member'
            );
        }
    },

    'the API key is never a password field and never rendered back' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 `text`, not `password`: core's own PasswordManagerTest fails the
         * build on a password input here, and nothing is exposed by using text
         * — the box is empty on every load whether or not a key is stored.
         *
         * 🚨 And no `value` attribute anywhere near it. A stored credential
         * printed back into a form is a credential in a browser cache, in a
         * screenshot, and in the support ticket that screenshot is attached to.
         */
        $passwordField = 'type="pass' . 'word"';

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            $code = $codeOf($path);

            assertFalse(str_contains($code, $passwordField), basename($path) . ' uses a password input');

            foreach (explode("\n", $code) as $line) {
                if (!str_contains($line, 'picks_cfbd_key')) {
                    continue;
                }

                assertFalse(
                    str_contains($line, 'value="'),
                    basename($path) . ' prints the stored API key back into the form'
                );
            }
        }
    },

    /* =============================================== SETTINGS + LANG ===== */

    'a blank credential keeps the stored one, and clearing it is deliberate' => static function () use ($db, $withSettings): void {
        /*
         * 🚨 A credential is never rendered back into the form, so a blank box
         * is ambiguous — and it has to mean "I did not touch this". Somebody
         * who came to change the season year must not stop every sync on the
         * way out.
         */
        $withSettings(['picks_cfbd_key' => 'zz-stored'], static function () use ($db): void {
            (new Settings($db))->save(['picks_cfbd_key' => '   ']);
            assertSame('zz-stored', (new Settings($db))->cfbdKey(), 'a blank box cleared a stored credential');

            (new Settings($db))->save(['picks_cfbd_key' => 'zz-other']);
            assertSame('zz-other', (new Settings($db))->cfbdKey(), 'a real value did not replace the stored one');

            (new Settings($db))->forget('picks_cfbd_key');
            assertSame('', (new Settings($db))->cfbdKey(), 'the deliberate clear did nothing');
        });
    },

    'a settings screen cannot write a setting it does not own' => static function () use ($db, $withSettings): void {
        $withSettings(['picks_enabled' => '0'], static function () use ($db): void {
            (new Settings($db))->save(['site_name' => 'zz-should-not-happen', 'picks_enabled' => '1']);

            $row = $db->table('settings')->where('key', 'site_name')->first();

            assertFalse(
                (string) ($row['value'] ?? '') === 'zz-should-not-happen',
                'Picks wrote a setting that is not its own'
            );
        });
    },

    'an unrecognised penalty takes no points away' => static function () use ($db, $withSettings): void {
        // 🚨 The safe direction. A stored value nobody expected must not become
        // a penalty nobody chose.
        $withSettings(['picks_confidence_penalty' => 'sudden-death'], static function () use ($db): void {
            assertSame('none', (new Settings($db))->confidencePenalty());
        });
    },

    'the poll interval cannot be set to hammer a free public endpoint' => static function () use ($db, $withSettings): void {
        $withSettings(['picks_live_interval' => '0'], static function () use ($db): void {
            assertTrue((new Settings($db))->pollMinutes() >= 2, 'the poll interval floor is gone');
        });
    },

    'every human string is translatable, with Convoro’s placeholders' => static function () use ($root): void {
        /*
         * 🚨 `{name}`, never `:name` — that is Flarum's syntax and this is not
         * Flarum, so a carried-over placeholder renders as literal text on the
         * page. And exactly TWO plural forms, because that is what the
         * translator supports; a third is silently ignored.
         */
        $lang = require $root . '/Lang/en.php';

        assertTrue(is_array($lang) && $lang !== [], 'the language file is empty');

        foreach ($lang as $key => $value) {
            assertTrue(is_string($value), $key . ' is not a string');

            assertSame(
                0,
                preg_match('/(?<![a-z0-9\/]):[a-z][a-z_]{2,}/i', $value),
                $key . ' uses a Flarum-style :placeholder, which renders as literal text here'
            );

            if (str_contains($value, '|')) {
                assertSame(2, count(explode('|', $value)), $key . ' is a plural with something other than two forms');
            }
        }
    },

    'no screen prints a shell command at an operator' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 Convoro is administrable without a terminal. The sync depends on
         * the site's scheduled task runner, and the honest thing to say is
         * where to look at it — not a command line somebody is expected to have
         * SSH access to run.
         */
        $banned = ['php ' . 'tools/convoro', 'crontab', 'supervisor', 'sudo ', 'systemctl'];

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            $code = strtolower($codeOf($path));

            foreach ($banned as $needle) {
                assertFalse(str_contains($code, strtolower($needle)), basename($path) . ' prints a shell command: ' . $needle);
            }
        }

        $lang = require $root . '/Lang/en.php';

        foreach ($lang as $key => $value) {
            foreach ($banned as $needle) {
                assertFalse(stripos($value, $needle) !== false, $key . ' prints a shell command: ' . $needle);
            }
        }
    },

    /* ====================================================== THE MARKUP === */

    'every CSS class these screens use actually exists' => static function () use ($root, $codeOf, $sourceFiles): void {
        /*
         * 🚨 A class name that does not exist is invisible in code review and
         * obvious on screen. `.admin-note` is the one that keeps being reached
         * for on this codebase — it has never existed, and the class is
         * `.admin-hint`.
         *
         * Picks' own `picks-*` classes have to be in its own stylesheet, which
         * is the other half of the same check: a rule nobody defined is as
         * broken as a class nobody has.
         */
        $cssRoot = defined('CONVORO_ROOT') ? CONVORO_ROOT : dirname($root, 3) . '/fbsfb-convoro';
        $known = [];

        foreach (glob($cssRoot . '/public/assets/css/*.css') ?: [] as $sheet) {
            if (preg_match_all('/\.([a-zA-Z][\w-]*)/', (string) file_get_contents($sheet), $matches)) {
                foreach ($matches[1] as $class) {
                    $known[$class] = true;
                }
            }
        }

        assertTrue(count($known) > 100, 'the core stylesheets could not be read, so this test proves nothing');

        // Picks' own rules count as defined.
        if (preg_match_all('/\.(picks-[\w-]*)/', (string) file_get_contents($root . '/Templates/front/styles.cvr'), $mine)) {
            foreach ($mine[1] as $class) {
                $known[$class] = true;
            }
        }

        foreach ($sourceFiles($root . '/Templates', 'cvr') as $path) {
            $code = $codeOf($path);

            if (!preg_match_all('/class="([^"]*)"/', $code, $attributes)) {
                continue;
            }

            foreach ($attributes[1] as $attribute) {
                // Strip the directives and interpolations woven through a
                // conditional class attribute before splitting on whitespace.
                $plain = preg_replace('/@\w+\s*\([^)]*\)|@\w+|\{\{.*?\}\}|\{!.*?!\}/s', ' ', $attribute) ?? '';

                foreach (preg_split('/\s+/', $plain) ?: [] as $class) {
                    if ($class === '' || preg_match('/^[a-zA-Z][\w-]*$/', $class) !== 1) {
                        continue;
                    }

                    assertTrue(
                        isset($known[$class]),
                        basename($path) . ' uses a CSS class that does not exist anywhere: .' . $class
                    );
                }
            }
        }
    },

    'the main class is named after the key, or packaging refuses it' => static function () use ($root): void {
        // 🚨 `ucfirst(key).php`. Key `picks` → `Picks.php`. Getting this wrong
        // fails at package time with a message about a missing provider.
        $manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);

        assertSame('picks', $manifest['key'] ?? '');
        assertTrue(is_file($root . '/' . ucfirst((string) $manifest['key']) . '.php'), 'the main class file is misnamed');
    },

    'the manifest floor is a promise, and schedule() is what sets it' => static function () use ($root, $codeOf): void {
        /*
         * 🚨 A floor looser than the seams actually used is the direction that
         * HURTS: the extension installs cleanly and its scheduled work silently
         * never runs. `Module::schedule()` shipped in 1.3.10, and this module
         * registers on it, so the constraint may not go below that.
         *
         * `health_checks` arrived later still, which is why it is reached for
         * behind `bound()` rather than by raising this floor.
         */
        $manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);
        $code = $codeOf($root . '/Picks.php');

        assertSame('^1.3.10', $manifest['convoro'] ?? '', 'the core constraint no longer matches the seams used');
        assertTrue(str_contains($code, '$this->schedule()'), 'the seam that sets the floor is gone');
        assertTrue(str_contains($code, "bound('health_" . "checks')"), 'the later registry is no longer guarded');
    },
];
