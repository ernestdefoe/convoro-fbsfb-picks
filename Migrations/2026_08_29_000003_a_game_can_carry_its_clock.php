<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Where a game has got to, not just what the score is.
 *
 * A scoreboard that says "10 - 10" and nothing else is a result, and a result
 * is the wrong shape for a game still being played: the whole question anybody
 * has when they look at one is "when in the game is this?".
 *
 * 🚨 Three columns, and the third is the one that matters. `period` and `clock`
 * are worth nothing without knowing WHEN they were true — a quarter lasts
 * fifteen minutes and a game clock moves every second, so a clock read a minute
 * ago is already wrong. `clock_at` is what lets the front end show a clock
 * while it is fresh and fall back to the period once it is not, instead of
 * printing a number that quietly stops being true.
 *
 * `confirmed_at` cannot do that job: it says when the SCORE was last agreed,
 * which is a different question and moves on a different rhythm.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('picks_events');

        foreach ([
            'period' => "ALTER TABLE `{$table}` ADD COLUMN `period` TINYINT NOT NULL DEFAULT 0",
            'clock' => "ALTER TABLE `{$table}` ADD COLUMN `clock` VARCHAR(16) NOT NULL DEFAULT ''",
            'clock_detail' => "ALTER TABLE `{$table}` ADD COLUMN `clock_detail` VARCHAR(64) NOT NULL DEFAULT ''",
            'clock_at' => "ALTER TABLE `{$table}` ADD COLUMN `clock_at` INT UNSIGNED NOT NULL DEFAULT 0",
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

        foreach (['period', 'clock', 'clock_detail', 'clock_at'] as $column) {
            if (!$this->hasColumn($table, $column)) {
                continue;
            }

            $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    /**
     * 🚨 Checked rather than assumed, so this can be run twice.
     *
     * An extension upgrade re-runs migrations it has already applied on more
     * installs than anybody expects, and `ADD COLUMN` on a column that is there
     * is an error that stops the whole upgrade halfway.
     */
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
