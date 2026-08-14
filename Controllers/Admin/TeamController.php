<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Controllers\Admin;

use Convoro\Engine\Http\Controller;
use Convoro\Engine\Http\Request;
use Convoro\Engine\Http\Response;

/**
 * The teams, their crests, and the forum each of them has on this site.
 *
 * 🚨 A logo is an ADDRESS, not a file. The Flarum original downloaded 139
 * crests, converted each to WebP through an image library and wrote them into
 * the site's asset disk — 139 outbound calls, a dependency, and a directory of
 * files that outlived uninstalling the extension. The provider's CDN address is
 * stored instead.
 *
 * 🚨 Typing an address marks the team custom, and a custom logo is never
 * overwritten by a sync. That flag is the only thing that makes a hand-picked
 * crest survive the night, and without it an operator would redo the same work
 * every morning.
 *
 * 🚨 Linking teams to forums READS `forums` and writes only `picks_teams`. A
 * restricted forum is skipped entirely rather than linked — a link on a public
 * game page to a forum somebody may not see tells them it exists and what it is
 * called, which is the whole point of restricting it.
 */
final class TeamController extends Controller
{
    private const NOTICE = 'picks_admin_notice';
    private const PROBLEM = 'picks_admin_problem';

    public function index(Request $request): Response
    {
        $teams = $this->app->make('picks.teams');
        $conference = trim((string) $request->query('conference', ''));
        $search = trim((string) $request->query('q', ''));

        $matching = $search === '' ? null : array_flip($teams->idsMatching($search));
        $rows = [];

        foreach ($teams->all() as $team) {
            if ($conference !== '' && (string) $team['conference'] !== $conference) {
                continue;
            }

            if ($matching !== null && !isset($matching[(int) $team['id']])) {
                continue;
            }

            $rows[] = $team;
        }

        return $this->render('picks::admin/teams', [
            'user' => $this->user($request),
            'tab' => 'teams',
            'teams' => $rows,
            'conferences' => $teams->conferences(),
            'conference' => $conference,
            'search' => $search,
            'total' => $teams->count(),
            'withoutLogo' => $teams->withoutLogo(),
            'hasForums' => $this->app->make('modules')->has('forum'),
            'notice' => $this->session($request)->getFlash(self::NOTICE),
            'problem' => $this->session($request)->getFlash(self::PROBLEM),
        ]);
    }

    public function logo(Request $request): Response
    {
        $teams = $this->app->make('picks.teams');
        $team = $teams->byId((int) $request->routeParam('id'));

        if ($team === null) {
            return $this->fail($request, __('picks.no_such_team'));
        }

        $light = trim((string) $request->post('logo_path'));
        $dark = trim((string) $request->post('logo_dark_path'));

        /*
         * 🚨 Only http(s) addresses, and only absolute ones. Anything else goes
         * straight into a `src` attribute on a public page, where a `javascript:`
         * or a `data:` is script somebody else wrote running on this site.
         */
        foreach ([$light, $dark] as $address) {
            if ($address !== '' && preg_match('#^https?://#i', $address) !== 1) {
                return $this->fail($request, __('picks.logo_must_be_a_url'));
            }
        }

        $teams->setLogo((int) $team['id'], $light, $dark);

        return $this->ok($request, $light === '' && $dark === ''
            ? __('picks.logo_reset', ['name' => (string) $team['name']])
            : __('picks.logo_saved', ['name' => (string) $team['name']]));
    }

    public function linkForums(Request $request): Response
    {
        $result = $this->app->make('picks.teams')->linkForums();

        return $this->ok($request, __('picks.forums_linked', [
            'linked' => $result['linked'],
            'unmatched' => $result['unmatched'],
        ]));
    }

    private function ok(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::NOTICE, $message);

        return $this->redirect('/admin/picks/teams');
    }

    private function fail(Request $request, string $message): Response
    {
        $this->session($request)->flash(self::PROBLEM, $message);

        return $this->redirect('/admin/picks/teams');
    }
}
