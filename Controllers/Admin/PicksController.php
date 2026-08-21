<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;
use Convoro\Extensions\Picks\Picks;
use Convoro\Extensions\Picks\Services\Sync;

/**
 * Running the pick'em: the rules of the game, the credential, and what the last
 * sync found.
 *
 * 🚨 No outbound call is made by rendering this page, and "Sync now" queues the
 * same job the schedule runs rather than doing it here. A screen that talks to
 * a data provider is a screen that hangs on the day that provider is down,
 * which is the day an administrator opens it to find out why.
 *
 * 🚨 The API key is never rendered back. The box is empty on every load whether
 * or not one is stored, blank means keep, and clearing it is a separate button.
 *
 * 🚨 Nothing on this screen prints a shell command. Convoro is administrable
 * without a terminal: the sync depends on the site's scheduled task runner, and
 * the honest thing to say is where to look at it — Dashboard → System health.
 */
final class PicksController extends Controller
{
    private const NOTICE = 'picks_admin_notice';
    private const PROBLEM = 'picks_admin_problem';

    public function index(Request $request): Response
    {
        $settings = $this->app->make('picks.settings');
        $teams = $this->app->make('picks.teams');

        return $this->render('picks::admin/index', [
            'user' => $this->user($request),
            'tab' => 'settings',
            'settings' => $settings,
            'conferences' => $teams->conferences(),
            'teamCount' => $teams->count(),
            'fixturesAt' => Picks::ago($settings->fixturesAt()),
            'fixturesOkAt' => Picks::ago($settings->fixturesOkAt()),
            'scoresAt' => Picks::ago($settings->scoresAt()),
            'scoresOkAt' => Picks::ago($settings->scoresOkAt()),
            'callBudget' => Sync::CALL_BUDGET,
            'staleMinutes' => intdiv(\Convoro\Extensions\Picks\Services\Settings::STALE_AFTER, 60),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $settings = $this->app->make('picks.settings');
        $offsetBefore = $settings->lockOffsetMinutes();
        $offsetAfter = max(0, (int) $request->post('picks_lock_offset'));

        $settings->save([
            // 🚨 A checkbox that is off sends nothing, so absent must mean off
            // and every one of these is written explicitly.
            'picks_enabled' => $request->post('picks_enabled') ? '1' : '0',
            'picks_sync_regular' => $request->post('picks_sync_regular') ? '1' : '0',
            'picks_sync_postseason' => $request->post('picks_sync_postseason') ? '1' : '0',
            'picks_confidence_mode' => $request->post('picks_confidence_mode') ? '1' : '0',
            'picks_auto_unlock' => $request->post('picks_auto_unlock') ? '1' : '0',
            'picks_live_scores' => $request->post('picks_live_scores') ? '1' : '0',

            // 🚨 Blank means keep. Enforced in Settings::save() as well, so
            // this line is the convenience and that one is the rule.
            'picks_cfbd_key' => (string) $request->post('picks_cfbd_key'),

            'picks_season_year' => (string) max(0, (int) $request->post('picks_season_year')),
            'picks_conference' => (string) $request->post('picks_conference'),
            'picks_lock_offset' => (string) $offsetAfter,
            'picks_confidence_penalty' => (string) $request->post('picks_confidence_penalty'),
            'picks_live_interval' => (string) max(0, (int) $request->post('picks_live_interval')),

            // 🚨 Zero is meaningful and is not the same as blank: it means the
            // plan has no monthly limit worth policing, so Picks stops
            // policing one. Anything else is a ceiling it keeps itself under.
            'picks_monthly_cap' => (string) max(0, (int) $request->post('picks_monthly_cap')),
        ]);

        /*
         * 🚨 A changed lock offset is applied to the fixtures already on the
         * board, not only to ones synced afterwards. Otherwise the new rule
         * would take effect some time next week, silently, and the week
         * currently being played would keep locking on the old one.
         */
        if ($offsetAfter !== $offsetBefore) {
            $moved = $this->app->make('picks.games')->reapplyOffset($offsetAfter);

            // 🚨 __n, not __. The string has two forms and __ would print the
            // pipe between them straight onto the screen.
            return $this->ok($request, __n('picks.saved_offset', $moved));
        }

        return $this->ok($request, __('picks.saved'));
    }

    /** Clearing the credential, which is the deliberate act a blank box is not. */
    public function forgetKey(Request $request): Response
    {
        $this->app->make('picks.settings')->forget('picks_cfbd_key');

        return $this->ok($request, __('picks.key_cleared'));
    }

    /**
     * Runs the fixture sync out of turn.
     *
     * 🚨 Queued, not run here. The sync talks to a provider that may not
     * answer; doing that inside this request would make the admin panel hang
     * for exactly as long as the provider is broken. The queue is drained by
     * the same timer that runs the schedule, so the answer arrives within about
     * a minute — and if it does not, that is itself what this button was
     * pressed to find out.
     */
    public function syncNow(Request $request): Response
    {
        /*
         * 🚨 The cursor is reset so a manual sync starts from the teams and the
         * calendar rather than from wherever the hourly run had got to.
         * Somebody pressing this has usually just changed the season year or
         * the key, and continuing mid-season would fetch six weeks of the wrong
         * year before anything they changed took effect.
         */
        $this->app->make('picks.settings')->recordFixtures('idle', '', 0);

        try {
            $this->app->make('queue')->push(Picks::FIXTURES);
        } catch (\Throwable $e) {
            return $this->fail($request, __('picks.sync_failed', ['reason' => $e->getMessage()]));
        }

        return $this->ok($request, __('picks.sync_queued'));
    }

    /**
     * Recomputes every total from the picks.
     *
     * 🚨 The button an operator needs after changing confidence mode or the
     * penalty, and the reason it exists: those settings change what a stored
     * pick is WORTH, and every `total_points` on the site was computed under
     * the old rule. Without this the leaderboard would keep the old numbers
     * until each member happened to be re-scored by a game finishing.
     *
     * 🚨 Queued in batches, not done here. This is every player on the site
     * times three scopes.
     */
    public function recalculate(Request $request): Response
    {
        /*
         * 🚨 A cursor, not a list of members. The job walks everybody who has
         * ever picked, a hundred at a time, and every scope each of them
         * already has a row in — so the payload is four small numbers however
         * many members there are.
         */
        try {
            $this->app->make('queue')->push(Picks::RESCORE, ['all' => true, 'after' => 0]);
        } catch (\Throwable $e) {
            return $this->fail($request, __('picks.sync_failed', ['reason' => $e->getMessage()]));
        }

        return $this->ok($request, __('picks.recalculate_queued'));
    }

    private function ok(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect('/admin/picks');
    }

    private function fail(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect('/admin/picks');
    }
}
