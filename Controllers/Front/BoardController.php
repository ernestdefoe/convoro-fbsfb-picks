<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Picks\Picks;
use Convoro\Extensions\Picks\Services\Games;

/**
 * The board, the leaderboard, and one member's own record.
 *
 * 🚨 Not one outbound call anywhere in this file. Every figure on these pages
 * is a row the scheduled sync wrote, and the page says how old that row is
 * rather than pretending it is the present. A page that asked a scoreboard for
 * a live score would hang on the afternoon that scoreboard was down — which is
 * the afternoon everybody loads this page.
 *
 * 🚨 The disclosure rule is applied by the SERVICE, not by the template. This
 * controller asks `Picks::revealed()` for other members' picks and gets back
 * only the games whose cutoff has passed; a template that decided what to print
 * would be one edit away from printing everything.
 */
final class BoardController extends Controller
{
    private const NOTICE = 'picks_notice';
    private const PROBLEM = 'picks_problem';

    public function index(Request $request): Response
    {
        $week = $this->app->make('picks.seasons')->currentWeek();

        return $this->board($request, $week === null ? 0 : (int) $week['id']);
    }

    public function week(Request $request): Response
    {
        return $this->board($request, (int) $request->routeParam('id'));
    }

    /* --------------------------------------------------------------- board */

    private function board(Request $request, int $weekId): Response
    {
        $settings = $this->app->make('picks.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('picks.not_found'));
        }

        $viewer = $this->user($request);

        if (!$this->app->make('gate')->can($viewer, 'picks.view')) {
            return Response::forbidden(__('picks.may_not_view'));
        }

        $seasons = $this->app->make('picks.seasons');
        $games = $this->app->make('picks.games');
        $store = $this->app->make('picks.store');

        $week = $seasons->week($weekId);
        $viewerId = $viewer === null ? 0 : (int) $viewer['id'];
        $mayPlay = $viewer !== null && $this->app->make('gate')->can($viewer, 'picks.play');
        $now = time();

        /*
         * 🚨 A week nobody has opened is shown to nobody but an administrator,
         * and it 404s rather than 403s. A 403 confirms the week exists and
         * which number it is, which for a round that has not been revealed is
         * exactly the thing being kept quiet.
         */
        $isAdmin = $this->viewerIsAdmin($request);

        if ($week === null || (!$week['is_open'] && !$isAdmin)) {
            return $this->render('picks::front/board', $this->shell($request, $viewer, $seasons) + [
                'week' => null,
                'games' => [],
                'notice' => $this->session($request)->getFlash(self::NOTICE),
                'problem' => $this->session($request)->getFlash(self::PROBLEM),
            ]);
        }

        $rows = $games->forWeek($weekId);
        $mine = $store->mine($viewerId, array_map(static fn (array $g): int => (int) $g['id'], $rows));
        $revealed = $store->revealed($rows, $now);
        $tallies = $store->tallies($rows, $now);

        $cards = [];
        $picked = 0;

        foreach ($rows as $game) {
            $pick = $mine[(int) $game['id']] ?? null;

            if ($pick !== null) {
                $picked++;
            }

            $locked = $games->locked($game, $now);
            $state = $games->state($game, $now);

            $cards[] = [
                'id' => (int) $game['id'],
                'home' => $game['home'],
                'away' => $game['away'],
                'neutral' => $game['neutral_site'],
                'kickoff' => $game['match_at'],
                'cutoff' => $games->cutoff($game),

                /*
                 * 🚨 A relative deadline, worked out here. "Picks close at
                 * 19:30" needs a timezone this page does not know the member
                 * is in; "picks close in 40 minutes" is right for everybody.
                 */
                'closes' => Picks::until($games->cutoff($game), $now),
                'starts' => Picks::until((int) $game['match_at'], $now),

                // 🚨 The one gate, asked once and handed to the template as a
                // boolean. The template never re-derives it from a clock.
                'open' => $games->open($game, $now),
                'locked' => $locked,
                'state' => $state,

                /*
                 * 🚨 A score is printed only while its confirmation is fresh.
                 * A game whose scoreboard stopped answering shows "not known"
                 * rather than a number that stopped being true an hour ago.
                 */
                'shows_score' => $games->scoreIsCurrent($game, $now),
                'home_score' => $game['home_score'],
                'away_score' => $game['away_score'],
                'result' => (string) $game['result'],
                'mine' => $pick,

                // Empty until the cutoff passes — the service decides, not this.
                'others' => $revealed[(int) $game['id']] ?? [],
                'tally' => $tallies[(int) $game['id']] ?? null,
            ];
        }

        return $this->render('picks::front/board', $this->shell($request, $viewer, $seasons) + [
            'seo' => $this->seo($request)->title($week['name']),
            'week' => $week,
            'games' => $cards,
            'total' => count($cards),
            'picked' => $picked,
            'mayPlay' => $mayPlay,
            'signedIn' => $viewer !== null,
            'hidden' => !$week['is_open'],
            'confidence' => $this->app->make('picks.settings')->confidenceMode(),
            'confidenceRange' => range(
                \Convoro\Extensions\Picks\Services\Picks::CONFIDENCE_MIN,
                \Convoro\Extensions\Picks\Services\Picks::CONFIDENCE_MAX
            ),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
        ]);
    }

    /* --------------------------------------------------------- leaderboard */

    public function leaderboard(Request $request): Response
    {
        $settings = $this->app->make('picks.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('picks.not_found'));
        }

        $viewer = $this->user($request);

        if (!$this->app->make('gate')->can($viewer, 'picks.view')) {
            return Response::forbidden(__('picks.may_not_view'));
        }

        $seasons = $this->app->make('picks.seasons');
        $scores = $this->app->make('picks.scores');
        $week = $seasons->currentWeek();

        $scope = (string) $request->query('scope', 'season');
        $scope = in_array($scope, ['week', 'season', 'alltime'], true) ? $scope : 'season';

        $weekId = $week === null ? 0 : (int) $week['id'];
        $seasonId = $week === null ? 0 : (int) $week['season_id'];

        [$boardSeason, $boardWeek] = match ($scope) {
            'week' => [$seasonId, $weekId],
            'season' => [$seasonId, 0],
            default => [0, 0],
        };

        $viewerId = $viewer === null ? 0 : (int) $viewer['id'];

        return $this->render('picks::front/leaderboard', $this->shell($request, $viewer, $seasons) + [
            'seo' => $this->seo($request)->title(__('picks.leaderboard')),
            'scope' => $scope,
            'week' => $week,
            'rows' => $scope === 'week' && $weekId < 1 ? [] : $scores->board($boardSeason, $boardWeek, 100),
            'mine' => $viewerId > 0 ? $scores->standing($viewerId, $boardSeason, $boardWeek) : null,
            'viewerId' => $viewerId,
        ]);
    }

    /* ----------------------------------------------------------- my record */

    public function me(Request $request): Response
    {
        $settings = $this->app->make('picks.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('picks.not_found'));
        }

        $viewer = $this->user($request);

        if ($viewer === null) {
            return $this->redirect('/login?next=' . rawurlencode('/picks/me'));
        }

        if (!$this->app->make('gate')->can($viewer, 'picks.view')) {
            return Response::forbidden(__('picks.may_not_view'));
        }

        $seasons = $this->app->make('picks.seasons');
        $scores = $this->app->make('picks.scores');
        $teams = $this->app->make('picks.teams')->map();
        $week = $seasons->currentWeek();

        $viewerId = (int) $viewer['id'];
        $weekId = $week === null ? 0 : (int) $week['id'];
        $seasonId = $week === null ? 0 : (int) $week['season_id'];

        $history = [];

        foreach ($this->app->make('picks.store')->history($viewerId, 60) as $pick) {
            $home = $teams[$pick['home_team_id']] ?? null;
            $away = $teams[$pick['away_team_id']] ?? null;
            $side = $pick['selected_outcome'];

            $history[] = $pick + [
                'home_name' => $home === null ? '' : (string) $home['name'],
                'away_name' => $away === null ? '' : (string) $away['name'],
                'picked_name' => (string) (($side === Games::HOME ? $home : $away)['name'] ?? ''),
                'final' => $pick['status'] === Games::FINISHED,
            ];
        }

        return $this->render('picks::front/me', $this->shell($request, $viewer, $seasons) + [
            'seo' => $this->seo($request)->title(__('picks.my_record'))->noindex(),
            'week' => $week,
            'weekStanding' => $weekId > 0 ? $scores->standing($viewerId, $seasonId, $weekId) : null,
            'seasonStanding' => $seasonId > 0 ? $scores->standing($viewerId, $seasonId, 0) : null,
            'allTimeStanding' => $scores->standing($viewerId, 0, 0),
            'history' => $history,
            'confidence' => $settings->confidenceMode(),
        ]);
    }

    /* -------------------------------------------------------------- pieces */

    /**
     * What every page in this extension carries.
     *
     * 🚨 The freshness line is here rather than on one screen, because every
     * one of these pages presents something the sync produced. `everSynced`
     * exists so a site nobody has configured says "nothing has been fetched
     * yet" instead of "as of never", which reads as a bug.
     *
     * @return array<string, mixed>
     */
    private function shell(Request $request, ?array $viewer, mixed $seasons): array
    {
        $settings = $this->app->make('picks.settings');

        return [
            'user' => $viewer,
            'weeks' => $seasons->openWeeks(),
            'asOf' => Picks::ago($settings->fixturesOkAt()),
            'scoresAsOf' => Picks::ago($settings->scoresOkAt()),
            /*
             * 🚨 "Has this site got fixtures" — not "has the sync ever run".
             *
             * These were the same question until an importer put a whole
             * season's schedule in without CollegeFootballData being involved
             * at all. The board then listed 655 games underneath a banner
             * saying nothing had been fetched yet, which is the site calling
             * itself broken while visibly working.
             *
             * A successful sync still counts, so a site with a key and an empty
             * off-season table is not told it has no fixtures.
             */
            'everSynced' => $settings->fixturesOkAt() > 0
                || $this->app->make('db')->table('picks_events')->exists(),
            'liveScores' => $settings->liveScores(),

            /*
             * 🚨 Configuration advice is for administrators only. A visitor
             * told to check the scheduled task runner learns nothing they can
             * act on and rather a lot about a site that is half set up.
             */
            'isAdmin' => $this->viewerIsAdmin($request),
        ];
    }

    private function viewerIsAdmin(Request $request): bool
    {
        $user = $this->user($request);

        if ($user === null) {
            return false;
        }

        $db = $this->app->make('db');

        try {
            return $db->table('group_members')
                ->join(
                    $db->prefixed('groups'),
                    $db->prefixed('group_members') . '.group_id',
                    '=',
                    $db->prefixed('groups') . '.id'
                )
                ->where($db->prefixed('groups') . '.is_admin', 1)
                ->where($db->prefixed('group_members') . '.user_id', (int) $user['id'])
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
