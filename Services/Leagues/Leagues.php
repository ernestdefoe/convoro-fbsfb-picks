<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services\Leagues;

/**
 * The competitions this install knows about.
 *
 * 🚨 A registry, so an extension can add one without editing a file it does not
 * own — the same argument every other registry here makes. Adding a league that
 * ESPN already covers is one line, because ESPN answers every one of these in
 * the same shape.
 *
 * 🚨 An unknown key falls back to college football rather than throwing. A
 * season row naming a league that has since been removed is somebody's install,
 * not a programming error, and a sync that skips it beats a scheduled job that
 * dies with every other league's fixtures still unsynced.
 */
final class Leagues
{
    public const DEFAULT = 'cfb';

    /*
     * 🚨 The deciding figures, per vocabulary rather than per league. College
     * football and the NFL are read the same way; so are the NBA and college
     * basketball. Repeating the map per league is how two leagues of the same
     * sport quietly end up naming different players.
     *
     * Every label is ESPN's own, read off a live response.
     */
    public const GRIDIRON = [
        'passing' => 'YDS',
        'rushing' => 'YDS',
        'receiving' => 'YDS',
        'defensive' => 'TOT',
    ];

    /** Basketball answers ONE unnamed group, so there is one category. */
    public const HARDWOOD = ['general' => 'PTS'];

    public const DIAMOND = [
        'batting' => 'RBI',
        'pitching' => 'K',
    ];

    /*
     * 🚨 `skaters` is deliberately absent. ESPN answers it alongside
     * `forwards` and `defenses` with a full set of column labels and NO
     * athletes in it — a heading over nothing.
     */
    public const ICE = [
        'forwards' => 'G',
        'defenses' => 'G',
        'goalies' => 'SV',
    ];

    /** @var array<string, League> */
    private array $leagues = [];

    public function __construct()
    {
        /*
         * 🚨 College football is the one league NOT served by ESPN here.
         * CollegeFootballData is richer for it — real week definitions, a
         * conference filter, and team box scores for games ESPN summarises
         * thinly — and it is what every existing install is already syncing.
         */
        $this->register(new League('cfb', 'College football', 'cfbd', 'football/college-football', 'gridiron', true, self::GRIDIRON));

        $this->register(new League('nfl', 'NFL', 'espn', 'football/nfl', 'gridiron', true, self::GRIDIRON));
        $this->register(new League('nba', 'NBA', 'espn', 'basketball/nba', 'hardwood', false, self::HARDWOOD));
        $this->register(new League('cbb', 'College basketball', 'espn', 'basketball/mens-college-basketball', 'hardwood', false, self::HARDWOOD));
        $this->register(new League('mlb', 'MLB', 'espn', 'baseball/mlb', 'diamond', false, self::DIAMOND));
        $this->register(new League('nhl', 'NHL', 'espn', 'hockey/nhl', 'ice', false, self::ICE));

        /*
         * 🚨 Football gets NO leader categories, and that is the honest answer
         * rather than an unfinished one. ESPN's match summary carries team
         * statistics and nothing else — naming a scorer means reading the goal
         * events, a different feed and a different piece of work. Declaring a
         * category that is always empty would put a heading over nothing under
         * every match.
         */
        $this->register(new League('mls', 'MLS', 'espn', 'soccer/usa.1', 'soccer', false, []));
        $this->register(new League('epl', 'Premier League', 'espn', 'soccer/eng.1', 'soccer', false, []));
        $this->register(new League('ucl', 'Champions League', 'espn', 'soccer/uefa.champions', 'soccer', false, []));
    }

    public function register(League $league): void
    {
        $this->leagues[$league->key] = $league;
    }

    public function get(?string $key): League
    {
        return $this->leagues[(string) $key] ?? $this->leagues[self::DEFAULT];
    }

    public function has(?string $key): bool
    {
        return isset($this->leagues[(string) $key]);
    }

    /** @return array<string, League> */
    public function all(): array
    {
        return $this->leagues;
    }

    /** @return array<string, string> key => name, for a dropdown */
    public function choices(): array
    {
        $out = [];

        foreach ($this->leagues as $key => $league) {
            $out[$key] = $league->name;
        }

        return $out;
    }
}
