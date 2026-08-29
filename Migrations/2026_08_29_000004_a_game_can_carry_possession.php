<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Who has the ball.
 *
 * 🚨 Stored as `home` or `away`, never as a provider's team id. ESPN answers
 * with its own identifier for a team, and nothing in this database is keyed by
 * one — resolving it at the point of reading, against the two competitors
 * already in hand, means every row here says something this system can still
 * understand if the provider ever changes.
 *
 * 🚨 It shares `clock_at` for freshness rather than carrying its own. Possession
 * moves faster than anything else on a scoreboard — a few plays, sometimes one —
 * so it is stale in exactly the same breath the clock is, and two timestamps
 * that must agree are two timestamps that eventually will not.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('picks_events');

        foreach ([
            'possession' => "ALTER TABLE `{$table}` ADD COLUMN `possession` VARCHAR(4) NOT NULL DEFAULT ''",
            'down_distance' => "ALTER TABLE `{$table}` ADD COLUMN `down_distance` VARCHAR(32) NOT NULL DEFAULT ''",
            'red_zone' => "ALTER TABLE `{$table}` ADD COLUMN `red_zone` TINYINT NOT NULL DEFAULT 0",
        ] as $column => $sql) {
            if ($this->hasColumn($table, $column)) {
                continue;
            }

            $this->db->query($sql);
        }
    }

    public function down(): void
    {
        $table = $this->db->prefixed('picks_events');

        foreach (['possession', 'down_distance', 'red_zone'] as $column) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }

            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
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
};
