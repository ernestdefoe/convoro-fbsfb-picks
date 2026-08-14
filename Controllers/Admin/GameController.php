<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Engine\Support\Paginator;
use Convoro\Extensions\Picks\Picks;

/**
 * The fixture list, and entering a result by hand.
 *
 * 🚨 Entering a result scores every pick on that game and re-ranks, and both of
 * those happen HERE rather than in the queue — deliberately, and it is the one
 * place this extension does work inside a request. An operator who has just
 * typed a final score is entitled to see the leaderboard change; queueing it
 * would mean pressing a button and watching nothing happen for a minute, which
 * is how people press it four more times.
 *
 * 🚨 It is bounded, which is what makes that safe: one game, the members who
 * picked it, three scopes each. It makes no outbound call. The unbounded case —
 * a whole slate finishing at once — is the scheduled path, and that one queues.
 *
 * 🚨 Clearing a result puts every pick on the game back to unscored. Marking
 * them all wrong instead would be the mistake this button exists to undo.
 */
final class GameController extends Controller
{
    private const PER_PAGE = 40;

    private const NOTICE = 'picks_admin_notice';
    private const PROBLEM = 'picks_admin_problem';

    public function index(Request $request): Response
    {
        $games = $this->app->make('picks.games');
        $seasons = $this->app->make('picks.seasons');

        $weekId = max(0, (int) $request->query('week', '0'));
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', '1'));

        $found = $games->browse($weekId, $status, $search, $page, self::PER_PAGE);
        $now = time();

        $rows = [];

        foreach ($found['rows'] as $game) {
            $rows[] = [
                'id' => (int) $game['id'],
                'home' => $game['home'],
                'away' => $game['away'],
                'week_name' => (string) $game['week_name'],
                'kickoff' => (int) $game['match_at'],
                'cutoff' => $games->cutoff($game),
                'locked' => $games->locked($game, $now),
                'state' => $games->state($game, $now),
                'status' => (string) $game['status'],
                'home_score' => $game['home_score'],
                'away_score' => $game['away_score'],
                'result' => (string) $game['result'],
                'confirmed' => Picks::ago((int) $game['confirmed_at'], $now),
                'neutral' => (bool) $game['neutral_site'],
            ];
        }

        $weeks = [];

        foreach ($seasons->all() as $season) {
            foreach ($seasons->weeks((int) $season['id']) as $week) {
                $weeks[] = [
                    'id' => (int) $week['id'],
                    'label' => $season['year'] . ' — ' . $week['name'],
                ];
            }
        }

        $query = array_filter([
            'week' => $weekId > 0 ? (string) $weekId : '',
            'status' => $status,
            'q' => $search,
        ], static fn (string $v): bool => $v !== '');

        return $this->render('picks::admin/games', [
            'user' => $this->user($request),
            'tab' => 'games',
            'games' => $rows,
            'weeks' => $weeks,
            'weekId' => $weekId,
            'status' => $status,
            'search' => $search,
            'total' => $found['total'],
            'pagination' => new Paginator(
                $rows,
                $found['total'],
                self::PER_PAGE,
                $page,
                '/admin/picks/games' . ($query === [] ? '' : '?' . http_build_query($query)),
            ),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
        ]);
    }

    public function result(Request $request): Response
    {
        $games = $this->app->make('picks.games');
        $game = $games->byId((int) $request->routeParam('id'));

        if ($game === null) {
            return $this->fail($request, __('picks.no_such_game'), 0);
        }

        $weekId = (int) $game['week_id'];

        $home = $request->post('home_score');
        $away = $request->post('away_score');

        if (trim((string) $home) === '' || trim((string) $away) === '') {
            return $this->fail($request, __('picks.result_needs_both'), $weekId);
        }

        $games->recordResult((int) $game['id'], (int) $home, (int) $away);

        $fresh = $games->byId((int) $game['id']);
        $result = $fresh === null ? '' : (string) $fresh['result'];

        /*
         * 🚨 Equal scores store no result, and every pick stays unscored. It is
         * not a draw — college football does not have them — it is a typo, and
         * the honest response to a typo is to say so rather than to score two
         * hundred picks against a number nobody meant.
         */
        if ($result === '') {
            $this->app->make('picks.store')->unscore((int) $game['id']);

            return $this->fail($request, __('picks.result_tied'), $weekId);
        }

        $settled = $this->app->make('picks.sync')->settleFinished([(int) $game['id']]);

        // 🚨 __n, not __. The string has two forms and __ would print the pipe
        // between them straight onto the screen.
        return $this->ok($request, __n('picks.result_saved', $settled), $weekId);
    }

    public function clear(Request $request): Response
    {
        $games = $this->app->make('picks.games');
        $game = $games->byId((int) $request->routeParam('id'));

        if ($game === null) {
            return $this->fail($request, __('picks.no_such_game'), 0);
        }

        $games->recordResult((int) $game['id'], null, null);
        $this->app->make('picks.store')->unscore((int) $game['id']);

        // 🚨 Totals are recomputed straight away. Leaving them would keep
        // points on the board that came from a result that no longer exists.
        $this->app->make('picks.sync')->rescore([
            'games' => [(int) $game['id']],
            'scopes' => [['s' => (int) $game['season_id'], 'w' => (int) $game['week_id']]],
            'after' => 0,
        ]);

        return $this->ok($request, __('picks.result_cleared'), (int) $game['week_id']);
    }

    private function ok(Request $request, string $message, int $weekId): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect($this->listPath($weekId));
    }

    private function fail(Request $request, string $message, int $weekId): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect($this->listPath($weekId));
    }

    /**
     * Back to the fixture list, filtered to the week the game was in.
     *
     * 🚨 Built here rather than with `Controller::back()`. That helper
     * redirects to the raw `Referer` header, which is whatever the browser
     * chose to send — an open redirect, and not one this extension needs to
     * inherit. The week comes from the row.
     */
    private function listPath(int $weekId): string
    {
        return '/admin/picks/games' . ($weekId > 0 ? '?week=' . $weekId : '');
    }
}
