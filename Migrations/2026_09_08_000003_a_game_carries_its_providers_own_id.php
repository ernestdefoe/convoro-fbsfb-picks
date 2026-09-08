<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * A provider's own identifier for a game.
 *
 * 🚨 `cfbd_id` cannot carry an ESPN id, and the reason is not the type. It
 * would fit — and CollegeFootballData in fact reuses ESPN's ids for college
 * football, which is exactly why the live scoreboard can match games at all.
 * What it cannot carry is the AMBIGUITY: once two providers write to one
 * column, the value means "the CFBD game" on one row and "the ESPN event" on
 * another, and nothing in the schema says which. The first collision between a
 * real CFBD id and a real ESPN id from another sport would overwrite a game in
 * silence.
 *
 * So the provider's id gets its own column, as a string, because not every
 * provider's id is a number. `cfbd_id` stays exactly as it is: every existing
 * row keeps working and nothing has to be backfilled before the next sync.
 *
 * 🚨 Indexed but NOT unique. Two leagues can share an id space — ESPN numbers
 * its events globally, but a provider added later need not — and a unique index
 * would reject the second league's game rather than the duplicate it was meant
 * to catch.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('picks_events');

        if (!$this->hasColumn($table, 'external_id')) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD COLUMN `external_id` VARCHAR(40) NOT NULL DEFAULT ''"
            );
        }

        if (!$this->hasIndex($table, 'picks_events_external')) {
            $this->db->query(
                "ALTER TABLE `{$table}` ADD INDEX `picks_events_external` (`external_id`)"
            );
        }
    }

    public function down(): void
    {
        $table = $this->db->prefixed('picks_events');

        if ($this->hasIndex($table, 'picks_events_external')) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `picks_events_external`");
        }

        if ($this->hasColumn($table, 'external_id')) {
            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `external_id`");
        }
    }

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
