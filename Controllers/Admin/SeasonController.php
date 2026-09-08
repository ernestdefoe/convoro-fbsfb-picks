<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Picks\Services\Leagues\Leagues;

/**
 * Seasons and weeks, and the switch that decides whether a round is running.
 *
 * 🚨 Opening a week is the single most consequential button in this extension
 * and it is deliberately manual. A week nobody opened takes no picks, and a
 * pick'em that opened its own first round the moment it was installed would
 * start taking picks nobody knew were being taken.
 *
 * 🚨 Closing a week does NOT reveal anybody's picks and does not hide any. That
 * is decided per game by its own cutoff — see Games — so an operator cannot
 * accidentally publish a round early by tidying up.
 */
final class SeasonController extends Controller
{
    private const NOTICE = 'picks_admin_notice';
    private const PROBLEM = 'picks_admin_problem';

    public function index(Request $request): Response
    {
        $seasons = $this->app->make('picks.seasons');
        $leagues = new Leagues();
        $games = $this->app->make('picks.games');
        $store = $this->app->make('picks.store');

        $rows = [];

        foreach ($seasons->all() as $season) {
            $weeks = [];

            foreach ($seasons->weeks((int) $season['id']) as $week) {
                $counts = $games->statusCounts((int) $week['id']);

                $weeks[] = $week + [
                    'games' => array_sum($counts),
                    'finished' => $counts['finished'] ?? 0,
                    'players' => $store->playersInWeek((int) $week['id']),
                    'picks' => $store->countInWeek((int) $week['id']),
                ];
            }

            $league = $leagues->get($season['league'] ?? null);

            $rows[] = [
                'id' => (int) $season['id'],
                'name' => (string) $season['name'],
                'year' => (int) $season['year'],
                'league' => $league->name,
                'weeks' => $weeks,
            ];
        }

        return $this->render('picks::admin/seasons', [
            'user' => $this->user($request),
            'tab' => 'seasons',
            'seasons' => $rows,
            'leagues' => $leagues->choices(),
            'thisYear' => (int) date('Y'),
            'autoUnlock' => $this->app->make('picks.settings')->autoUnlock(),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
        ]);
    }

    /**
     * Start following a league.
     *
     * 🚨 Creating the SEASON is the whole action — the fixtures arrive on the
     * next scheduled sync rather than in this request. Fetching a full season
     * inline is what killed the college sync's first version: an admin request
     * with a seventeen-week schedule fetch in it was simply killed by the
     * server, and left half a season behind with no way to tell.
     */
    public function follow(Request $request): Response
    {
        $leagues = new Leagues();
        $league = (string) $request->post('league');
        $year = (int) $request->post('year');

        if (!$leagues->has($league)) {
            return $this->fail($request, __('picks.season_league_unknown'));
        }

        /*
         * 🚨 Bounded rather than trusted. A typo of 202 or 20266 creates a
         * season nothing will ever sync into, and the only symptom is an empty
         * card somebody has to work out how to delete.
         */
        if ($year < 2000 || $year > (int) date('Y') + 2) {
            return $this->fail($request, __('picks.season_year_unlikely'));
        }

        $this->app->make('picks.seasons')->seasonForYear($year, $league);

        return $this->ok($request, __('picks.season_following', [
            'league' => $leagues->get($league)->name,
            'year' => (string) $year,
        ]));
    }

    public function open(Request $request): Response
    {
        return $this->setOpen($request, true);
    }

    public function close(Request $request): Response
    {
        return $this->setOpen($request, false);
    }

    public function rename(Request $request): Response
    {
        $week = $this->app->make('picks.seasons')->week((int) $request->routeParam('id'));

        if ($week === null) {
            return $this->fail($request, __('picks.no_such_week'));
        }

        $this->app->make('picks.seasons')->rename((int) $week['id'], (string) $request->post('name'));

        return $this->ok($request, __('picks.week_renamed'));
    }

    private function setOpen(Request $request, bool $open): Response
    {
        $seasons = $this->app->make('picks.seasons');

        // 🚨 Loaded again rather than trusted from the form. That a route
        // parameter matched means it was the right shape, not that it names a
        // week that exists.
        $week = $seasons->week((int) $request->routeParam('id'));

        if ($week === null) {
            return $this->fail($request, __('picks.no_such_week'));
        }

        $seasons->setOpen((int) $week['id'], $open);

        return $this->ok($request, $open
            ? __('picks.week_opened', ['name' => (string) $week['name']])
            : __('picks.week_closed', ['name' => (string) $week['name']]));
    }

    private function ok(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect('/admin/picks/seasons');
    }

    private function fail(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect('/admin/picks/seasons');
    }
}
