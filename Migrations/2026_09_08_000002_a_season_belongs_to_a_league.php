<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Which competition a season is.
 *
 * 🚨 The league belongs to the SEASON, not to a setting. A sport as one global
 * value is right for a board that follows one league and wrong the moment it
 * follows two — and wrong in the worst way, because adding the second silently
 * changes what the first one is. On the season it costs nothing: a board with
 * one league has one value in one column.
 *
 * 🚨 It defaults to college football on purpose. Every row that exists when this
 * runs was synced from CollegeFootballData, so the default is not a guess — it
 * is the only thing those rows can be.
 *
 * 🚨 And `unique(year)` HAS to go. It said a community has one season per year,
 * which was true when there was one sport; the NFL and the Premier League both
 * running in 2026 is two seasons in one year, and the old index would refuse
 * the second one with a duplicate-key error that says nothing about leagues.
 * The pair is what is unique now.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('picks_seasons');

        if (!$this->hasColumn($table, 'league')) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD COLUMN `league` VARCHAR(20) NOT NULL DEFAULT 'cfb'"
            );
        }

        if ($this->hasIndex($table, 'picks_seasons_year')) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `picks_seasons_year`");
        }

        if (!$this->hasIndex($table, 'picks_seasons_year_league')) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD UNIQUE `picks_seasons_year_league` (`year`, `league`)"
            );
        }
    }

    public function down(): void
    {
        $table = $this->db->prefixed('picks_seasons');

        if ($this->hasIndex($table, 'picks_seasons_year_league')) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `picks_seasons_year_league`");
        }

        /*
         * 🚨 The old single-column unique index is NOT put back. Going down
         * after two leagues have run in one year would fail on rows that are
         * perfectly valid — and a rollback that cannot complete is worse than
         * one that leaves an index off.
         */
        if ($this->hasColumn($table, 'league')) {
            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `league`");
        }
    }

    /** Checked rather than assumed, so an upgrade can run this twice. */
    private function hasColumn(string $table, string $column): bool
    {
        foreach ($this->db->select("SHOW COLUMNS FROM `{$table}`") as $row) {
            if (($row['Field'] ?? '') === $column) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach ($this->db->select("SHOW INDEX FROM `{$table}`") as $row) {
            if (($row['Key_name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
