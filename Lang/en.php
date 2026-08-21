<?php

declare(strict_types=1);

/*
 * Every human string Picks puts on a screen.
 *
 * 🚨 `{name}` for a placeholder, and exactly TWO forms for a plural. Nothing
 * here is assembled out of fragments in a template: a sentence a translator
 * cannot see whole is a sentence they cannot translate, and word order is not a
 * constant across languages.
 */

return [
    'name' => 'Picks',
    'nav' => 'Picks',
    'admin_title' => 'Picks',
    'title' => 'Picks',
    'intro' => 'Pick a winner in every game. One point a game, or as many as you dare.',
    'not_found' => 'There is nothing here.',
    'save' => 'Save',
    'saved' => 'Saved.',

    /* ---------------------------------------------------------- the clock */

    'never' => 'never',
    'ago_moments' => 'a moment ago',
    'ago_minutes' => '{count} minute ago|{count} minutes ago',
    'ago_hours' => '{count} hour ago|{count} hours ago',
    'ago_days' => '{count} day ago|{count} days ago',
    'in_moments' => 'in a moment',
    'in_minutes' => 'in {count} minute|in {count} minutes',
    'in_hours' => 'in {count} hour|in {count} hours',
    'in_days' => 'in {count} day|in {count} days',

    /* ----------------------------------------------------------- the board */

    'board' => 'This week',
    'leaderboard' => 'Leaderboard',
    'my_record' => 'My record',
    'at' => 'at',
    'neutral_site' => 'neutral ground',
    'home' => 'Home',
    'away' => 'Away',

    'no_week' => 'No round is open',
    'no_week_note' => 'Picks open when an administrator opens a round. There is nothing to pick yet.',
    'no_games' => 'No games in this round',
    'no_games_note' => 'Fixtures appear here once they have been fetched.',
    'week_hidden' => 'This round has not been opened.',
    'week_hidden_admin' => 'You can see it because you are an administrator. Open it under Admin → Picks → Seasons when you are ready for people to pick.',

    /*
     * 🚨 The lines that keep the pages honest. Everything on them is what a
     * scheduled sync last managed to find out, and saying when that was — in
     * plain words, before anything claims to be a score — is the difference
     * between a page that is a few minutes behind and a page that lies.
     */
    'fixtures_as_of' => 'Fixtures as of {when}.',
    'scores_as_of' => 'Scores as of {when}.',
    'never_synced' => 'Nothing has been fetched yet, so there are no fixtures to show.',
    'never_synced_admin' => 'Picks fetches fixtures on a schedule. Check that the site’s scheduled task runner is set up — Dashboard → System health says whether it has been running.',

    /* ------------------------------------------------------- game statuses */

    'state_scheduled' => 'Not started',
    'state_live' => 'Playing now',
    'state_final' => 'Final',

    // 🚨 A real answer, and a different one from "nothing has started". It
    // means this site has lost touch with the scoreboard.
    'state_unknown' => 'Score not known',
    'state_unknown_note' => 'The scoreboard has not answered recently, so the score below may have moved on.',

    'kickoff' => 'Kick-off {when}',
    'locks' => 'Picks close {when}',
    'locked' => 'Picks closed',

    /* ----------------------------------------------------------- your pick */

    'your_pick' => 'Your pick',
    'no_pick' => 'You have not picked this game',
    'pick_home' => 'Pick the home team',
    'pick_away' => 'Pick the away team',

    /*
     * 🚨 The tile itself is the button now, so this is what says what pressing
     * it does — a picture of a helmet with no label is not a control anybody
     * should have to guess at.
     */
    'pick_this' => 'Pick {team}',
    'withdraw' => 'Take it back',
    'withdraw_confirm' => 'Take back your pick on this game?',
    'pick_saved' => 'Pick saved.',
    'pick_withdrawn' => 'Pick taken back.',
    'picked_count' => 'You have picked {picked} of {total}.',
    'confidence' => 'Confidence',
    'confidence_none' => 'No rating',
    'confidence_note' => 'A correct pick scores its rating. An unrated pick scores one.',

    'sign_in_to_play' => 'Sign in to pick.',
    'may_not_view' => 'You are not allowed to see this.',
    'may_not_play' => 'You are not allowed to enter picks.',

    /*
     * 🚨 The refusals, and they are worded so nobody has to guess. "Closed" is
     * the one people will see most, and it has to say what closed and when so
     * it does not read as a bug.
     */
    'error_no_such_game' => 'There is no such game.',
    'error_closed' => 'That game is closed. Picks shut at the cut-off and cannot be changed afterwards.',
    'error_bad_outcome' => 'Pick the home team or the away team.',
    'error_bad_confidence' => 'A confidence rating is a whole number from 1 to 10.',
    'error_not_picked' => 'You had not picked that game.',

    /* --------------------------------------------------- everybody’s picks */

    /*
     * 🚨 Shown only once a game’s cut-off has passed. Before that a member sees
     * their own pick and nobody else’s, which is the whole competitive point.
     */
    'everyone' => 'Everybody’s picks',
    'hidden_until_cutoff' => 'Everybody’s picks appear here when this game closes.',
    'split' => '{home} for the home team, {away} for the away team',
    'nobody_picked' => 'Nobody picked this game.',

    /* ----------------------------------------------------- the leaderboard */

    'scope_week' => 'This week',
    'scope_season' => 'This season',
    'scope_alltime' => 'All time',
    'col_rank' => '#',
    'col_player' => 'Player',
    'col_points' => 'Points',
    'col_correct' => 'Correct',
    'col_picks' => 'Picks',
    'col_accuracy' => 'Accuracy',
    'col_move' => 'Move',
    'moved_up' => 'up {count}',
    'moved_down' => 'down {count}',
    'moved_none' => 'no change',
    'new_entry' => 'new',
    'empty_board' => 'Nobody has scored yet',
    'empty_board_note' => 'The leaderboard fills in once games start finishing.',
    'your_standing' => 'You are {rank} of {players}.',
    'no_standing' => 'You have no scored picks in this scope yet.',

    /* ------------------------------------------------------- your own page */

    'record_week' => 'This week',
    'record_season' => 'This season',
    'record_alltime' => 'All time',
    'record_points' => 'points',
    'record_of' => '{correct} of {total} correct',
    'record_rank' => '{rank} of {players}',
    'history' => 'Your recent picks',
    'no_history' => 'You have not picked anything yet',
    'no_history_note' => 'Open the board and pick a winner in this week’s games.',
    'outcome_correct' => 'Correct',
    'outcome_wrong' => 'Wrong',
    'outcome_pending' => 'Not played yet',

    /* -------------------------------------------------------------- health */

    'health_label' => 'Picks',
    'health_off' => 'Switched off',
    'health_off_note' => 'Picks is installed and not in use.',
    'health_unconfigured' => 'No API key',
    'health_unconfigured_note' => 'Picks needs a CollegeFootballData API key before it can fetch anything. Admin → Picks.',
    'health_ok' => 'Fetching',
    'health_ok_note' => 'Fixtures last fetched {when}.',
    'health_unreachable' => 'Cannot fetch',
    'health_unreachable_note' => 'The last attempt failed: {reason}. Anything was last fetched successfully {when}.',
    'health_never' => 'Nothing fetched yet',
    'health_never_note' => 'Picks fetches on a schedule. Check the scheduled task runner on this screen.',

    /*
     * The provider declining, which is not the provider breaking. Each of
     * these says what ran out, when it comes back, and what to change if
     * waiting is not good enough — because an operator reading this row is
     * deciding what to do, not admiring the diagnosis.
     */
    'setting_cap' => 'Monthly call budget',
    'setting_cap_note' => 'How many calls Picks may make to CollegeFootballData in a calendar month. It stops one short of your plan rather than being cut off mid-season, and spreads the rest across the weeks that can still change. Zero means your plan has no monthly limit and Picks should not enforce one. Used so far this month: {used}.',

    'health_quota' => 'Monthly quota used up',
    'health_quota_note' => 'CollegeFootballData has stopped answering until its allowance resets on {resumes}. The board keeps every fixture already fetched and scores still arrive from ESPN, but changed kick-off times will not appear until then. Raise the plan on your CollegeFootballData account, or lower the monthly call budget below so Picks spreads what you have across the month. Fixtures last fetched {when}.',
    'health_budget' => 'Call budget spent',
    'health_budget_note' => 'Picks has made {used} of the {cap} provider calls it is allowed this month and has stopped on purpose, so the allowance is not spent before the season needs it. Fetching resumes on {resumes}. Raise the monthly call budget if your plan allows more. Fixtures last fetched {when}.',
    'health_rate' => 'Asked too often',
    'health_rate_note' => 'The provider is refusing for the moment rather than for the month. Picks waits an hour and tries again by itself — nothing needs doing. Fixtures last fetched {when}.',
    'health_rejected' => 'API key rejected',
    'health_rejected_note' => 'CollegeFootballData will not accept the key on this site. Check it has not expired and paste it in again below. Fixtures last fetched {when}.',

    /* --------------------------------------------------------- admin: tabs */

    'tab_settings' => 'Settings',
    'tab_seasons' => 'Seasons',
    'tab_games' => 'Games',
    'tab_teams' => 'Teams',

    /* ----------------------------------------------------- admin: settings */

    'admin_intro' => 'A college football pick’em, its fixtures fetched on a schedule.',

    'how_it_works' => 'How this runs',
    'how_it_works_note' => 'Picks fetches nothing while somebody is waiting for a page. A scheduled job asks CollegeFootballData for the teams, the calendar and the fixtures, a few requests at a time, and writes them down; every screen reads what it wrote and says how old it is.',
    'how_it_works_schedule' => 'That job runs hourly and gets through {count} request each time, so a season fills in over the first few hours.|That job runs hourly and gets through {count} requests each time, so a season fills in over the first few hours.',
    'how_it_works_scores' => 'Live scores are a separate job, checked as often as you allow below, and only while a game is actually being played.',
    'how_it_works_cron' => 'Both depend on the site’s scheduled task runner. Dashboard → System health says whether it has been running.',

    'status_heading' => 'Last fetch',
    'status_ok' => 'Working',
    'status_ok_body' => 'The last fetch succeeded.',
    'status_unreachable' => 'Failed',
    'status_unreachable_body' => 'The last fetch did not get an answer. Nothing was changed.',
    'status_idle' => 'Nothing to do',
    'status_idle_body' => 'Picks is switched off, or there is nothing being played.',
    'status_never' => 'Never run',
    'status_never_body' => 'Nothing has been fetched yet.',
    'status_fixtures_tried' => 'Fixtures last tried {when}.',
    'status_fixtures_answered' => 'Fixtures last answered {when}.',
    'status_scores_tried' => 'Scores last tried {when}.',
    'status_scores_answered' => 'Scores last answered {when}.',
    'status_reason' => 'Reason: {reason}',
    'status_stale_note' => 'A score whose last confirmation is older than {minutes} minutes is shown as “not known” rather than as the present score.',
    'status_teams' => '{count} team stored.|{count} teams stored.',

    'sync_now' => 'Fetch now',
    'sync_now_note' => 'Queues the same job the schedule runs, starting again from the teams and the calendar. It does not fetch while you wait — the answer arrives within about a minute.',
    'sync_queued' => 'Queued. It runs within about a minute.',
    'sync_failed' => 'Could not queue that: {reason}',

    'recalculate' => 'Recompute every total',
    'recalculate_note' => 'Needed after changing confidence mode or the penalty: those settings change what a stored pick is worth, and every total on the site was worked out under the old rule.',
    'recalculate_queued' => 'Queued. Totals are recomputed a batch at a time.',

    'settings_heading' => 'Switch',
    'setting_enabled' => 'Turn Picks on',
    'setting_enabled_note' => 'While this is off the pick’em pages are not there at all, and nothing is fetched.',

    'api_heading' => 'CollegeFootballData',
    'api_note' => 'Fixtures, teams and the season calendar come from CollegeFootballData, which needs a free API key.',
    'setting_key' => 'API key',
    'setting_key_note' => 'Stored and never shown again. Leave it blank to keep the one already stored.',
    'unchanged' => 'Stored — leave blank to keep',
    'key_stored' => 'A key is stored.',
    'forget_key' => 'Forget the stored key',
    'key_cleared' => 'The stored key has been cleared.',
    'credentials_note' => 'A blank box above means “I did not touch this”. Clearing the key is this separate button.',

    'season_heading' => 'What to fetch',
    'setting_year' => 'Season',
    'setting_year_note' => 'The year the season starts in. A January bowl belongs to the previous year.',
    'setting_conference' => 'Conference',
    'setting_conference_note' => 'Leave blank for every FBS team. A conference abbreviation narrows every fetch to that conference.',
    'any_conference' => 'Every conference',
    'setting_regular' => 'Fetch the regular season',
    'setting_postseason' => 'Fetch the bowls',

    'rules_heading' => 'Rules of the game',
    'setting_offset' => 'Close picks before kick-off',
    'setting_offset_note' => 'In minutes. Zero closes a game exactly at kick-off. Changing this moves the cut-off on every game that has not started yet.',
    'setting_confidence' => 'Confidence ratings',
    'setting_confidence_note' => 'Members rate each pick 1–10. A correct pick scores its rating instead of one point.',
    'setting_penalty' => 'Penalty for a wrong high-confidence pick',
    'penalty_none' => 'None — a wrong pick scores nothing',
    'penalty_half' => 'Half — a wrong pick loses half its rating, rounded down',
    'penalty_full' => 'Full — a wrong pick loses its whole rating',
    'setting_penalty_note' => 'A total never goes below zero, whichever of these is chosen.',
    'setting_auto_unlock' => 'Open the next round automatically',
    'setting_auto_unlock_note' => 'Once every game in an open round has a result, the round after it opens by itself. The first round is always opened by hand.',

    'live_heading' => 'Live scores',
    'live_note' => 'Scores while games are being played come from ESPN’s public scoreboard, which needs no key. One request covers every game at once.',
    'setting_live' => 'Check for live scores',
    'setting_interval' => 'How often',
    'setting_interval_note' => 'In minutes, and never less than two. Nothing is checked at all unless a game is actually being played.',
    'saved_offset' => 'Saved. The cut-off moved on {count} game.|Saved. The cut-off moved on {count} games.',

    /* ------------------------------------------------------ admin: seasons */

    'seasons_heading' => 'Seasons and rounds',
    'seasons_note' => 'A round takes no picks until you open it. Opening one is the moment the pick’em starts for everybody.',
    'auto_unlock_on' => 'The next round opens automatically once the current one is finished. The first one is still yours to open.',
    'no_seasons' => 'No seasons yet',
    'no_seasons_note' => 'Seasons and rounds appear here once fixtures have been fetched.',
    'col_round' => 'Round',
    'col_dates' => 'Dates',
    'col_games' => 'Games',
    'col_players' => 'Players',
    'col_state' => 'State',
    'col_actions' => 'Actions',
    'players_picks' => '{players} players, {picks} picks',
    'round_postseason' => 'Bowls',
    'round_open' => 'Open',
    'round_closed' => 'Closed',
    'open_round' => 'Open this round',
    'close_round' => 'Close it',
    'close_confirm' => 'Close this round? Nobody will be able to enter or change a pick in it.',
    'week_opened' => '{name} is open for picks.',
    'week_closed' => '{name} is closed.',
    'week_renamed' => 'Renamed.',
    'no_such_week' => 'There is no such round.',
    'games_progress' => '{finished} of {total} finished',
    'rename' => 'Rename',
    'round_name' => 'Name',

    /* -------------------------------------------------------- admin: games */

    'games_heading' => 'Games',
    'games_note' => 'Results arrive by themselves while live scores are on. Typing one in here scores every pick on that game straight away.',
    'no_games_found' => 'No games match',
    'no_games_found_note' => 'Try a different round, or clear the search.',
    'filter_week' => 'Round',
    'filter_status' => 'State',
    'filter_search' => 'Team',
    'filter_apply' => 'Filter',
    'all_rounds' => 'Every round',
    'all_states' => 'Every state',
    'status_scheduled' => 'Not started',
    'status_closed' => 'Closed',
    'status_in_progress' => 'Playing',
    'status_finished' => 'Finished',
    'col_game' => 'Game',
    'col_kickoff' => 'Kick-off',
    'col_cutoff' => 'Picks close',
    'col_score' => 'Score',
    'enter_result' => 'Result',
    'clear_result' => 'Clear',
    'clear_confirm' => 'Clear this result? Every pick on the game goes back to unscored.',
    'result_saved' => 'Result saved. {count} player re-scored.|Result saved. {count} players re-scored.',
    'result_cleared' => 'Result cleared, and every pick on that game is unscored again.',
    'result_needs_both' => 'Both scores are needed.',
    'result_tied' => 'Those scores are level. College football has no draws, so nothing was scored — check the numbers.',
    'no_such_game' => 'There is no such game.',
    'games_total' => '{count} game|{count} games',

    /* -------------------------------------------------------- admin: teams */

    'teams_heading' => 'Teams',
    'teams_note' => 'Teams arrive with the fixtures. A logo is an address rather than a file, so nothing is downloaded and nothing is left behind when Picks is removed.',
    'no_teams' => 'No teams yet',
    'no_teams_note' => 'They appear here after the first fetch.',
    'col_team' => 'Team',
    'col_conference' => 'Conference',
    'col_logo' => 'Logo',
    'col_forum' => 'Forum',
    'teams_stored' => '{count} team|{count} teams',
    'teams_no_logo' => '{count} without a logo|{count} without a logo',
    'logo_light' => 'Logo, for a light background',
    'logo_dark' => 'Logo, for a dark background',
    'logo_note' => 'A full https:// address. Setting either one keeps it safe from the next fetch; clearing both hands the team back to the provider.',
    'logo_saved' => 'Logo saved for {name}, and protected from the next fetch.',
    'logo_reset' => '{name} is back to the provider’s logo.',
    'logo_must_be_a_url' => 'A logo has to be a full http:// or https:// address.',
    'logo_custom' => 'Yours',
    'logo_provider' => 'Provider',
    'logo_none' => 'None',
    'no_such_team' => 'There is no such team.',

    'link_forums' => 'Link teams to their forums',
    'link_forums_note' => 'Matches each team to a forum with the same name, so a game can link to it. Nothing in your forums is changed, and a forum restricted to some groups is left alone.',
    'link_forums_no_module' => 'This site has no forums, so there is nothing to link to.',
    /*
     * 🚨 Not a plural string, on purpose: it counts two different things and a
     * two-form plural can only agree with one of them. Written so both numbers
     * read correctly at any value.
     */
    'forums_linked' => 'Linked {linked} of them. {unmatched} have no forum on this site.',
    'forum_linked' => 'Linked',
    'forum_none' => '—',

    // Where do I get this? — see themes/default/templates/partials/setup-link.cvr
    'setup_hint' => 'Sign in and request a key; it arrives by email. The free tier is enough for a season of picks.',
];
