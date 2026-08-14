<?php

declare(strict_types=1);

use Convoro\Engine\Http\Middleware\Authenticate;
use Convoro\Extensions\Picks\Controllers\Front\BoardController;
use Convoro\Extensions\Picks\Controllers\Front\PickController;

/** @var \Convoro\Engine\Routing\Router $router */

/*
 * No middleware on the reads, and the reason is the same one Quests and OnAir
 * give: whether Picks answers at all is a setting and whether a signed-out
 * visitor may look is a permission, so the controller decides rather than the
 * router. An extension that is installed and switched off should 404 like a
 * page that does not exist, not 403 like one somebody is not allowed to see.
 *
 * 🚨 The literal paths come first. `/picks/{week}` would match the word
 * "leaderboard" as happily as it matches 7, and the symptom would be "no such
 * week" on the site's own navigation.
 */
$router->get('/picks', [BoardController::class, 'index'], 'picks.board');
$router->get('/picks/leaderboard', [BoardController::class, 'leaderboard'], 'picks.leaderboard');
$router->get('/picks/me', [BoardController::class, 'me'], 'picks.me');
$router->get('/picks/week/{id}', [BoardController::class, 'week'], 'picks.week');

/*
 * The writes.
 *
 * 🚨 Signed-in only at the router, permission-checked in the controller, and
 * then checked AGAIN against the game's own cutoff before a single row moves.
 * Three gates rather than one because they answer three different questions,
 * and the third is the only one that is about time.
 */
$router->group()
    ->middleware(Authenticate::class)
    ->group(function ($router) {
        $router->post('/picks/game/{id}', [PickController::class, 'submit'], 'picks.submit');
        $router->post('/picks/game/{id}/withdraw', [PickController::class, 'withdraw'], 'picks.withdraw');
    });
