<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Front;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Picks\Services\Picks as Store;

/**
 * Making a pick, and taking one back.
 *
 * 🚨 Everything the browser sent is treated as a claim, not a fact. The game is
 * loaded again from the database here rather than trusted from the form, the
 * viewer is asked again rather than remembered, and the cutoff is checked in
 * `Picks::submit()` against that freshly-read row. There is no path through
 * this file where a hidden field decides whether a pick is allowed.
 *
 * 🚨 No outbound call. A member pressing a button waits for one small write and
 * nothing else.
 *
 * 🚨 The pick is not scored here and no total is recomputed here. Scoring
 * happens when a game finishes, in the queue — a member changing their mind on
 * a Thursday must not pay for two hundred people's aggregates.
 */
final class PickController extends Controller
{
    private const NOTICE = 'picks_notice';
    private const PROBLEM = 'picks_problem';

    public function submit(Request $request): Response
    {
        $settings = $this->app->make('picks.settings');

        if (!$settings->enabled()) {
            return Response::notFound(__('picks.not_found'));
        }

        $viewer = $this->user($request);

        // 🚨 Asked again even though the route requires a session. A route's
        // middleware says who reached this method; the permission says who may
        // play, and an operator can take that away from a group at any time.
        if ($viewer === null || !$this->app->make('gate')->can($viewer, 'picks.play')) {
            return Response::forbidden(__('picks.may_not_play'));
        }

        $games = $this->app->make('picks.games');
        $game = $games->byId((int) $request->routeParam('id'));

        if ($game === null) {
            return $this->fail($request, __('picks.error_no_such_game'), 0);
        }

        $confidence = $request->post('confidence');

        $result = $this->app->make('picks.store')->submit(
            (int) $viewer['id'],
            $game,
            (string) $request->post('outcome'),

            // '' from an untouched select is "no rating", not zero.
            $confidence === null || trim((string) $confidence) === '' ? null : (int) $confidence,
            $settings->confidenceMode(),
        );

        return $result === Store::OK
            ? $this->ok($request, __('picks.pick_saved'), (int) $game['week_id'])
            : $this->fail($request, __('picks.error_' . $result), (int) $game['week_id']);
    }

    public function withdraw(Request $request): Response
    {
        if (!$this->app->make('picks.settings')->enabled()) {
            return Response::notFound(__('picks.not_found'));
        }

        $viewer = $this->user($request);

        if ($viewer === null || !$this->app->make('gate')->can($viewer, 'picks.play')) {
            return Response::forbidden(__('picks.may_not_play'));
        }

        $game = $this->app->make('picks.games')->byId((int) $request->routeParam('id'));

        if ($game === null) {
            return $this->fail($request, __('picks.error_no_such_game'), 0);
        }

        $result = $this->app->make('picks.store')->withdraw((int) $viewer['id'], $game);

        return $result === Store::OK
            ? $this->ok($request, __('picks.pick_withdrawn'), (int) $game['week_id'])
            : $this->fail($request, __('picks.error_' . $result), (int) $game['week_id']);
    }

    private function ok(Request $request, string $message, int $weekId): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect($this->boardPath($weekId));
    }

    private function fail(Request $request, string $message, int $weekId): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect($this->boardPath($weekId));
    }

    /**
     * Back to the week the game was in.
     *
     * 🚨 Built from the game's own week rather than from a `return` parameter
     * on the request. A redirect target taken from user input is an open
     * redirect, and this one would be reachable by anybody signed in.
     */
    private function boardPath(int $weekId): string
    {
        return $weekId > 0 ? '/picks/week/' . $weekId : '/picks';
    }
}
