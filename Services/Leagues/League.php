<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services\Leagues;

/**
 * One competition this extension can follow.
 *
 * 🚨 A value object and a registry rather than a database table. A league is
 * not something an administrator invents — it is something a provider either
 * covers or does not, and a row somebody typed for a competition ESPN has never
 * heard of is a season that syncs nothing and explains nothing about why.
 *
 * 🚨 `sport` is the RECAP's vocabulary, not the league. College football and
 * the NFL are both `gridiron` because a recap of either says the same words
 * about yards and turnovers; the Premier League and MLS are both `soccer`. The
 * distinction matters because there are far more leagues than there are ways to
 * describe a game, and pretending otherwise means writing the same sentences
 * again for every new competition.
 */
final class League
{
    public function __construct(
        /** The stored key. Goes in `picks_seasons.league` and never changes. */
        public readonly string $key,
        /** What an administrator sees in the dropdown. */
        public readonly string $name,
        /** Which provider answers for it: `cfbd` or `espn`. */
        public readonly string $provider,
        /**
         * ESPN's own path for it — `football/nfl`, `soccer/eng.1`. Empty for a
         * league no ESPN endpoint covers.
         */
        public readonly string $espnPath = '',
        /** The recap vocabulary: `gridiron`, `soccer`, `hardwood`, `diamond`. */
        public readonly string $sport = 'gridiron',
        /**
         * 🚨 Whether the season is divided into numbered weeks.
         *
         * Gridiron is; basketball, baseball and league football are played to a
         * date instead. Everything downstream — the pick deadline, the
         * standings, "this week's games" — was written assuming a week exists,
         * and a league that has none needs a week per calendar span rather than
         * a week per round. False here is what tells the sync to build them.
         */
        public readonly bool $hasWeeks = true,
        /**
         * Which player groups are worth keeping one name from, and which figure
         * decides who led each.
         *
         * 🚨 This lives here because the CHOICE is made when a box score is
         * stored — only the leader is kept, not every athlete — and Picks is
         * what stores it. Game Day's `Sport` declares the same categories again
         * for a different job: deciding what to CALL them in prose. The two
         * must agree, and Game Day's test suite proves it against fixtures this
         * code produced rather than by trusting that somebody edited both.
         *
         * 🚨 The figure names are ESPN's own column labels, read off live
         * responses. `YDS` and `TD` are what the NFL answers; `RBI` and `K` are
         * baseball's; `PTS` is basketball's single group. A label invented here
         * simply never matches and the category silently disappears.
         *
         * @var array<string, string> group => the deciding figure
         */
        public readonly array $leaders = [
            'passing' => 'YDS',
            'rushing' => 'YDS',
            'receiving' => 'YDS',
            'defensive' => 'TOT',
        ],
    ) {
    }
}
