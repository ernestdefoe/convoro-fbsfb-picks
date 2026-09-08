<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * What happened in a game, beyond who won.
 *
 * 🚨 One row per game holding a NORMALISED document, not a table of columns per
 * statistic. Two reasons, and the second is the one that matters.
 *
 * A box score is not a fixed set of numbers. The provider answers with whatever
 * that game's feed carried — a game with no defensive stats simply has none,
 * and the categories differ between an FBS game and a lower-division one. A
 * column per statistic would be a migration every time a feed changed and a
 * table of nulls the rest of the time.
 *
 * And the shape stored here is OURS rather than the provider's. Normalising on
 * the way in means every reader — a recap post, a panel, a future leaderboard —
 * sees the same document however collegefootballdata.com decides to arrange its
 * JSON next season. The raw answer is not kept: it is large, it is theirs, and
 * anything worth having from it is in the document.
 *
 * 🚨 `fetched_at` is separate from `updated_at` because they answer different
 * questions. One says when this was true, which is what a screen showing it has
 * to be able to say out loud; the other says when the row was last written,
 * which changes when nothing about the game did.
 */
return new class () extends Migration {
    public function up(): void
    {
        $table = $this->db->prefixed('picks_box_scores');
        $events = $this->db->prefixed('picks_events');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `event_id` BIGINT UNSIGNED NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `fetched_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                /* One box score per game: a second fetch replaces the first. */
                UNIQUE KEY `picks_box_event` (`event_id`),
                CONSTRAINT `picks_box_event_fk` FOREIGN KEY (`event_id`)
                    REFERENCES `{$events}` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixed('picks_box_scores') . '`');
    }
};
