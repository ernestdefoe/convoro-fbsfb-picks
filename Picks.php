<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks;

use Convoro\Engine\Module\Module;
use Convoro\Extensions\Picks\Services\Games;
use Convoro\Extensions\Picks\Services\Http;
use Convoro\Extensions\Picks\Services\Picks as Store;
use Convoro\Extensions\Picks\Services\Scores;
use Convoro\Extensions\Picks\Services\Seasons;
use Convoro\Extensions\Picks\Services\Settings;
use Convoro\Extensions\Picks\Services\Sources\Cfbd;
use Convoro\Extensions\Picks\Services\Sources\Espn;
use Convoro\Extensions\Picks\Services\Sync;
use Convoro\Extensions\Picks\Services\Teams;

/**
 * Picks — a college football pick'em.
 *
 * A port of the `resofire/picks` Flarum extension, which is the pick'em fbsfb
 * has been running. The game is unchanged: members pick a winner in every game
 * of the open week, a game locks at its own cutoff, and points accumulate over
 * a week, a season and all time.
 *
 * 🚨 **The cutoff is the whole integrity of the game**, and it is enforced in
 * one method — `Games::open()` — against a row read from the database in the
 * same request. Nothing the browser sends about whether a game is still open is
 * consulted, because the browser is the thing being defended against.
 *
 * 🚨 **Before a game's cutoff a member sees their own pick and nobody else's.**
 * After it, everybody's are on show. Decided per GAME rather than per week,
 * because a Tuesday night fixture locks four days before a Saturday one in the
 * same round, and a per-week rule would either publish the Tuesday game early
 * or keep it hidden after it had been played.
 *
 * 🚨 **Nothing on a page makes an outbound call.** The Flarum original ran its
 * whole seventeen-week schedule fetch inside an admin request and was killed by
 * `max_execution_time` halfway through. Here a capped, resumable job on the
 * schedule writes rows and every screen reads them, saying how old they are.
 *
 * Four things did not come across from the original.
 *
 * **The Testing tab is gone** — `TestDataSeeder`, `seed2026`, `seedFake2025`,
 * `cleanFake`, `wipeAll`. Four hundred lines that manufactured members, picks
 * and results on a live database, reachable from a button in the admin panel.
 * A "wipe all" next to a "seed fake data" on somebody's production forum is an
 * accident waiting for a mis-click, and the thing it was for — exercising the
 * scoring rules — is what the test suite is for.
 *
 * **Logos are linked rather than downloaded.** The original fetched every
 * crest, converted it to WebP and wrote it into the site's asset disk, which is
 * 139 outbound calls, an image library dependency, and a directory of files
 * that survive uninstalling. ESPN's CDN address is stored instead; an operator
 * who wants a different image types one in and that choice is marked custom so
 * no sync overwrites it.
 *
 * **The scoring lock is gone, and so is the race it was covering.** Score rows
 * are scoped with 0 rather than NULL, so the unique index actually constrains
 * the all-time row and two concurrent passes cannot both insert it. A
 * constraint the database enforces beats a lock the application remembers to
 * take.
 *
 * **The confidence value is no longer stored while confidence mode is off.** It
 * was accepted and ignored, which means switching the mode on would have
 * started counting ratings people entered when nobody told them ratings
 * counted.
 */
final class Picks extends Module
{
    /** Queue handlers. The schedule pushes the first two; the third re-queues. */
    public const FIXTURES = 'picks.fixtures';
    public const SCORES = 'picks.scores';
    public const RESCORE = 'picks.rescore';

    public function register(): void
    {
        /*
         * Filed under `community`, with Quests: this is something members do
         * with each other, not a change to how an existing part of the product
         * behaves.
         *
         * 🚨 The pip is a settings read and nothing else — this closure runs on
         * every admin page. What it counts is "switched on and unable to
         * answer", which is otherwise invisible: a pick'em with a rejected API
         * key looks exactly like one in the off season.
         */
        $this->adminNav()->area('community')
            ->item('picks', '/admin/picks', 'picks.nav')
            ->badge(fn (): int => $this->app->make('picks.settings')->problems());

        $db = $this->app->make('db');

        $this->app->singleton('picks.settings', fn (): Settings => new Settings($db));
        $this->app->singleton('picks.teams', fn (): Teams => new Teams($db));
        $this->app->singleton('picks.seasons', fn (): Seasons => new Seasons($db));

        $this->app->singleton('picks.games', fn (): Games => new Games(
            $db,
            $this->app->make('picks.teams'),
        ));

        $this->app->singleton('picks.store', fn (): Store => new Store(
            $db,
            $this->app->make('picks.games'),
        ));

        $this->app->singleton('picks.scores', fn (): Scores => new Scores(
            $db,
            $this->app->make('picks.settings'),
        ));

        // 🚨 One HTTP class, with hard connect and read timeouts baked in and
        // no way to make a call without them. See Services/Http.php.
        $this->app->singleton('picks.http', fn (): Http => new Http());

        $this->app->singleton('picks.cfbd', fn (): Cfbd => new Cfbd(
            $this->app->make('picks.http'),
            $this->app->make('picks.settings'),
        ));

        $this->app->singleton('picks.espn', fn (): Espn => new Espn($this->app->make('picks.http')));

        $this->app->singleton('picks.sync', fn (): Sync => new Sync(
            $this->app,
            $this->app->make('picks.settings'),
            $this->app->make('picks.teams'),
            $this->app->make('picks.seasons'),
            $this->app->make('picks.games'),
            $this->app->make('picks.store'),
            $this->app->make('picks.scores'),
            $this->app->make('picks.cfbd'),
            $this->app->make('picks.espn'),
        ));

        /*
         * 🚨 Registered in register(), not boot() — the cron boots everything
         * and then reads the schedule, so a module registering its schedule
         * during boot relies on an order it does not control and fails
         * silently. This is the seam that sets the manifest floor.
         *
         * Hourly for the fixtures, which change on the timescale of a press
         * release. Minutely for the scores, because a live score an hour late
         * is worthless — and affordable because almost every tick returns
         * immediately: nothing happens unless live scores are switched on, the
         * operator's own interval has elapsed, and a game is actually being
         * played. See Sync.
         */
        $this->schedule()->hourly(self::FIXTURES);
        $this->schedule()->minutely(self::SCORES);
    }

    public function boot(): void
    {
        $queue = $this->app->make('queue');

        $queue->handle(self::FIXTURES, fn (): array => $this->app->make('picks.sync')->fixtures());
        $queue->handle(self::SCORES, fn (): array => $this->app->make('picks.sync')->scores());

        /*
         * The tail of a large re-scoring. A slate of thirty bowls finishing at
         * once affects every member who played; the first hundred are done in
         * the run that noticed, and the rest arrive here.
         */
        $queue->handle(
            self::RESCORE,
            fn (array $payload): array => ['rescored' => $this->app->make('picks.sync')->rescore($payload)],
        );

        $this->reportHealth();
    }

    /**
     * Say how we are on the screen an operator opens when something is wrong.
     *
     * 🚨 Reads only what the sync left behind. A health check that reaches off
     * the machine hangs on the day the thing it reaches is down — which is the
     * day somebody opens it. That is core's own rule for this registry.
     *
     * 🚨 Guarded with `bound()`. The registry arrived after the version this
     * extension's manifest requires, so on an older core Picks simply does not
     * appear on the health screen rather than fatalling on a missing binding.
     */
    private function reportHealth(): void
    {
        if (!$this->app->bound('health_checks')) {
            return;
        }

        $this->app->make('health_checks')->register('picks', 'running', function (): array {
            $settings = $this->app->make('picks.settings');
            $label = __('picks.health_label');

            if (!$settings->enabled()) {
                return [
                    'label' => $label,
                    'value' => __('picks.health_off'),
                    'tone' => 'ok',
                    'note' => __('picks.health_off_note'),
                ];
            }

            if (!$settings->hasCfbdKey()) {
                return [
                    'label' => $label,
                    'value' => __('picks.health_unconfigured'),
                    'tone' => 'bad',
                    'note' => __('picks.health_unconfigured_note'),
                ];
            }

            if ($settings->fixturesStatus() === 'unreachable' || $settings->scoresStatus() === 'unreachable') {
                return [
                    'label' => $label,
                    'value' => __('picks.health_unreachable'),
                    'tone' => 'bad',

                    /*
                     * 🚨 The reason, and when anything last answered. A status
                     * with no reason attached is a mystery an operator cannot
                     * act on, and the point at which they stop looking.
                     */
                    'note' => __('picks.health_unreachable_note', [
                        'reason' => $settings->fixturesError() ?: $settings->scoresError(),
                        'when' => self::ago($settings->fixturesOkAt()),
                    ]),
                ];
            }

            if ($settings->fixturesOkAt() < 1) {
                return [
                    'label' => $label,
                    'value' => __('picks.health_never'),
                    'tone' => 'warn',
                    'note' => __('picks.health_never_note'),
                ];
            }

            return [
                'label' => $label,
                'value' => __('picks.health_ok'),
                'tone' => 'ok',
                'note' => __('picks.health_ok_note', ['when' => self::ago($settings->fixturesOkAt())]),
            ];
        });
    }

    /**
     * "4 minutes ago", or the words for never.
     *
     * 🚨 Static and shared, because every screen in this extension pairs a
     * figure with one of these and they all have to agree. A page that says
     * "as of a moment ago" beside a page that says "as of 12:04" is two pages
     * arguing about the same fact.
     */
    public static function ago(int $timestamp, ?int $now = null): string
    {
        if ($timestamp <= 0) {
            return __('picks.never');
        }

        $seconds = max(0, ($now ?? time()) - $timestamp);

        return match (true) {
            $seconds < 60 => __('picks.ago_moments'),
            $seconds < 3600 => __n('picks.ago_minutes', intdiv($seconds, 60)),
            $seconds < 86400 => __n('picks.ago_hours', intdiv($seconds, 3600)),
            default => __n('picks.ago_days', intdiv($seconds, 86400)),
        };
    }

    /**
     * "in 4 minutes", or the words for a deadline that has gone.
     *
     * 🚨 The counterpart of `ago()`, and it exists so a cut-off is stated in
     * the same units the member is thinking in. "Picks close at 19:30" needs a
     * timezone the page does not know; "picks close in 40 minutes" does not.
     */
    public static function until(int $timestamp, ?int $now = null): string
    {
        $seconds = $timestamp - ($now ?? time());

        if ($seconds <= 0) {
            return __('picks.locked');
        }

        return match (true) {
            $seconds < 60 => __('picks.in_moments'),
            $seconds < 3600 => __n('picks.in_minutes', intdiv($seconds, 60)),
            $seconds < 86400 => __n('picks.in_hours', intdiv($seconds, 3600)),
            default => __n('picks.in_days', intdiv($seconds, 86400)),
        };
    }
}
