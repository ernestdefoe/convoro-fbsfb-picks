<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;
use Convoro\Engine\Database\Schema\Blueprint;

/**
 * Teams, seasons, weeks, games, picks and scores.
 *
 * Six tables, carried over from the Flarum extension this is a port of. Four
 * decisions in here differ from that original and each one is load-bearing.
 *
 * 🚨 `cutoff_at` is an INTEGER of epoch seconds, not a DATETIME.
 *
 * The cutoff is the whole integrity of this game: after it passes, a pick may
 * not be made, changed or withdrawn, and everybody's picks become visible. The
 * original stored a MySQL DATETIME carrying no timezone and compared it to
 * PHP's timezone-aware `now`. That comparison is correct exactly when the two
 * agree about what hour it is, which is a property of the server's php.ini and
 * the database's session variables rather than of this code. Epoch seconds have
 * no zone to disagree about. `match_at` follows for the same reason; the week
 * date range stays a DATE because it is only ever printed.
 *
 * 🚨 `picks_user_scores.season_id` and `week_id` are 0 for "no scope", never
 * NULL, and that is what makes the unique index real.
 *
 * A score row exists at three scopes — one week, one season, and all time — and
 * the all-time row is identified by having neither. With NULLs, MySQL's unique
 * index does not constrain it at all, because NULL is not equal to NULL: two
 * concurrent scoring passes for the same member both find no row and both
 * INSERT, and the duplicate is permanent and silently doubles a leaderboard
 * total. The original worked around that with an application-level cache lock
 * standing in for a constraint. Zero is a value, so the index does the work
 * instead, and there is nothing left to serialise.
 *
 * 🚨 `confirmed_at` on a game is when a score was last CONFIRMED by a source,
 * and it is a different fact from `updated_at`. A game left mid-play by an ESPN
 * outage keeps its last known score for ever; pairing that score with the age
 * of its confirmation is what lets the front end say "not known" instead of
 * showing a stale 14–10 as if it were the present one.
 *
 * 🚨 `picks_teams.forum_id` links a team to its forum on this site, and is 0
 * when there is no match. Nothing requires it and nothing writes to `forums`:
 * it is resolved by name during the team sync and used to put a link on a game.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->schema->create('picks_teams', function (Blueprint $bp): void {
            $bp->id();
            $bp->string('name', 190);
            $bp->string('slug', 190);
            $bp->string('abbreviation', 16)->default('');
            $bp->string('conference', 100)->default('');

            /*
             * NULL here genuinely means "we do not have one", and a unique
             * index tolerates any number of NULLs while still refusing a second
             * team claiming the same provider id. This is the one place in the
             * schema where nullable is the right answer.
             */
            $bp->int('cfbd_id', true)->nullable()->default(null);
            $bp->int('espn_id', true)->default(0);

            $bp->string('logo_path', 255)->default('');
            $bp->string('logo_dark_path', 255)->default('');

            // Set by hand in the admin screen. The sync never overwrites a
            // logo an operator chose.
            $bp->bool('logo_custom')->default(false);

            // The team's forum on this site, or 0. Read-only towards `forums`.
            $bp->bigInt('forum_id', true)->default(0);

            $bp->timestamps();

            $bp->unique('slug', 'picks_teams_slug');
            $bp->unique('cfbd_id', 'picks_teams_cfbd');
            $bp->index('conference');
        });

        $this->schema->create('picks_seasons', function (Blueprint $bp): void {
            $bp->id();
            $bp->string('name', 100);
            $bp->string('slug', 100);
            $bp->smallInt('year', true);
            $bp->date('start_date')->nullable()->default(null);
            $bp->date('end_date')->nullable()->default(null);
            $bp->timestamps();

            $bp->unique('slug', 'picks_seasons_slug');
            $bp->unique('year', 'picks_seasons_year');
        });

        $this->schema->create('picks_weeks', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('season_id', true);
            $bp->string('name', 100);
            $bp->smallInt('week_number', true)->default(0);

            // `regular` or `postseason`. A string rather than an enum so a
            // third kind of week is code and not a schema change.
            $bp->string('season_type', 20)->default('regular');

            $bp->date('start_date')->nullable()->default(null);
            $bp->date('end_date')->nullable()->default(null);

            /*
             * 🚨 A week nobody opened takes no picks, however far away its
             * games are. This is the operator's switch and it is checked on
             * every write alongside the per-game cutoff — the two answer
             * different questions and a game needs both.
             */
            $bp->bool('is_open')->default(false);

            $bp->timestamps();

            $bp->unique(['season_id', 'season_type', 'week_number'], 'picks_weeks_scope');
            $bp->foreign('season_id', 'id', $this->db->prefixed('picks_seasons'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('picks_events', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('week_id', true)->default(0);
            $bp->bigInt('home_team_id', true);
            $bp->bigInt('away_team_id', true);

            // The provider's game id. Unique so a sync that runs twice — which
            // it will — updates one row instead of inserting a second.
            $bp->int('cfbd_id', true)->nullable()->default(null);

            $bp->bool('neutral_site')->default(false);

            // 🚨 Epoch seconds. See the note at the top of this file.
            $bp->int('match_at', true)->default(0);
            $bp->int('cutoff_at', true)->default(0);

            // `scheduled`, `closed`, `in_progress` or `finished`.
            $bp->string('status', 20)->default('scheduled');

            /*
             * NULL is "no score yet", which is not 0–0. A game that has kicked
             * off and is genuinely goalless has to be tellable apart from one
             * nobody has reported on.
             */
            $bp->smallInt('home_score', true)->nullable()->default(null);
            $bp->smallInt('away_score', true)->nullable()->default(null);

            // `home`, `away`, or '' while undecided.
            $bp->string('result', 10)->default('');

            // 🚨 When a source last confirmed this score, not when the row was
            // last touched. The front end pairs every live score with it.
            $bp->int('confirmed_at', true)->default(0);

            $bp->timestamps();

            $bp->unique('cfbd_id', 'picks_events_cfbd');
            $bp->index('week_id');
            $bp->index('match_at');
            $bp->index('status');

            $bp->foreign('home_team_id', 'id', $this->db->prefixed('picks_teams'), 'RESTRICT', 'CASCADE');
            $bp->foreign('away_team_id', 'id', $this->db->prefixed('picks_teams'), 'RESTRICT', 'CASCADE');
        });

        $this->schema->create('picks_picks', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('user_id', true);
            $bp->bigInt('event_id', true);

            // `home` or `away`. Validated on the way in; never rendered raw.
            $bp->string('selected_outcome', 10);

            /*
             * 🚨 Three states, and NULL is the one that matters. A pick is
             * right, wrong, or NOT YET SCORED — and the aggregate counts only
             * the rows that have been decided, so a member's accuracy is not
             * dragged down by games that have not been played.
             */
            $bp->bool('is_correct')->nullable()->default(null);

            // NULL when confidence mode is off, or when the member did not set
            // one. Scoring reads it as COALESCE(confidence, 1).
            $bp->smallInt('confidence', true)->nullable()->default(null);

            $bp->timestamps();

            // 🚨 One pick per member per game, enforced by the database. The
            // controller upserts; this is what makes a double submit harmless.
            $bp->unique(['user_id', 'event_id'], 'picks_picks_one_each');
            $bp->index('event_id');

            $bp->foreign('user_id', 'id', $this->db->prefixed('users'), 'CASCADE', 'CASCADE');
            $bp->foreign('event_id', 'id', $this->db->prefixed('picks_events'), 'CASCADE', 'CASCADE');
        });

        $this->schema->create('picks_user_scores', function (Blueprint $bp): void {
            $bp->id();
            $bp->bigInt('user_id', true);

            // 🚨 0 means "not scoped to one". Never NULL — see the top of this
            // file for why the unique index depends on that.
            $bp->bigInt('season_id', true)->default(0);
            $bp->bigInt('week_id', true)->default(0);

            $bp->int('total_points', true)->default(0);
            $bp->int('total_picks', true)->default(0);
            $bp->int('correct_picks', true)->default(0);
            $bp->decimal('accuracy', 5, 2)->default(0);

            // 1-based. 0 means no rank has been recorded in this scope yet,
            // which is why the leaderboard shows no arrow rather than a jump
            // from nowhere to first.
            $bp->int('previous_rank', true)->default(0);
            $bp->int('current_rank', true)->default(0);

            $bp->timestamps();

            $bp->unique(['user_id', 'season_id', 'week_id'], 'picks_scores_scope');
            $bp->index(['season_id', 'week_id', 'total_points'], 'picks_scores_board');

            $bp->foreign('user_id', 'id', $this->db->prefixed('users'), 'CASCADE', 'CASCADE');
        });
    }

    public function down(): void
    {
        // Children first: the foreign keys above refuse it in any other order.
        $this->schema->drop('picks_user_scores');
        $this->schema->drop('picks_picks');
        $this->schema->drop('picks_events');
        $this->schema->drop('picks_weeks');
        $this->schema->drop('picks_seasons');
        $this->schema->drop('picks_teams');
    }
};
