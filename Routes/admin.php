<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\RequireAdmin;
use Convoro\Extensions\Picks\Controllers\Admin\GameController;
use Convoro\Extensions\Picks\Controllers\Admin\PicksController;
use Convoro\Extensions\Picks\Controllers\Admin\SeasonController;
use Convoro\Extensions\Picks\Controllers\Admin\TeamController;

/** @var \Convoro\Engine\Routing\Router $router */

$router->group()
    ->prefix('/admin/picks')
    ->middleware(RequireAdmin::class)
    ->group(function ($router) {
        /*
         * 🚨 Never an action called `settings` — the base Controller has a
         * protected `settings(): array` and the clash is a fatal at class load.
         * The path may say settings; the method may not.
         *
         * 🚨 Every literal path is registered before anything carrying `{id}`,
         * because `{id}` matches the word "sync" as happily as it matches 7.
         */
        $router->get('/', [PicksController::class, 'index'], 'admin.picks');
        $router->post('/settings', [PicksController::class, 'saveSettings'], 'admin.picks.settings');
        $router->post('/forget', [PicksController::class, 'forgetKey'], 'admin.picks.forget');
        $router->post('/sync', [PicksController::class, 'syncNow'], 'admin.picks.sync');
        $router->post('/recalculate', [PicksController::class, 'recalculate'], 'admin.picks.recalculate');

        $router->get('/seasons', [SeasonController::class, 'index'], 'admin.picks.seasons');
        $router->post('/weeks/{id}/open', [SeasonController::class, 'open'], 'admin.picks.week.open');
        $router->post('/weeks/{id}/close', [SeasonController::class, 'close'], 'admin.picks.week.close');
        $router->post('/weeks/{id}/rename', [SeasonController::class, 'rename'], 'admin.picks.week.rename');

        $router->get('/games', [GameController::class, 'index'], 'admin.picks.games');
        $router->post('/games/{id}/result', [GameController::class, 'result'], 'admin.picks.game.result');
        $router->post('/games/{id}/clear', [GameController::class, 'clear'], 'admin.picks.game.clear');

        $router->get('/teams', [TeamController::class, 'index'], 'admin.picks.teams');
        $router->post('/teams/link-forums', [TeamController::class, 'linkForums'], 'admin.picks.teams.link');
        $router->post('/teams/{id}/logo', [TeamController::class, 'logo'], 'admin.picks.team.logo');
    });
