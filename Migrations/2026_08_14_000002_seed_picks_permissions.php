<?php

declare(strict_types=1);

use Convoro\Engine\Database\Migration\Migration;

/**
 * Who may look at the board, and who may play.
 *
 * 🚨 Two permissions rather than one, and the split is the same one Chat makes:
 * reading without playing is what makes a pick'em safe to leave open on a site
 * with unverified members. Merging them would mean the only way to stop
 * somebody entering picks is to stop them seeing the leaderboard.
 *
 * Guests may look and may not play, because a leaderboard nobody can see until
 * they sign up is a leaderboard nobody signs up for, and a pick is attached to
 * a member.
 *
 * Managing Picks is not a permission here. Every administrative screen sits
 * behind `RequireAdmin`, which is how every other extension in this tree gates
 * its admin panel; a third permission would be a second answer to a question
 * core already answers.
 */
return new class extends Migration {
    /** Group ids seeded by core: 1 administrators, 2 moderators, 3 members, 4 guests. */
    private const GRANTS = [
        ['picks.view', 3],
        ['picks.view', 4],
        ['picks.play', 3],
    ];

    public function up(): void
    {
        $table = $this->db->prefixed('permissions');

        foreach (self::GRANTS as [$permission, $group]) {
            $exists = $this->db->table('permissions')
                ->where('group_id', $group)
                ->where('permission', $permission)
                ->where('resource', '')
                ->exists();

            if ($exists) {
                continue;
            }

            $this->db->query(
                "INSERT INTO `{$table}` (`group_id`, `permission`, `resource`, `granted`) VALUES (?, ?, '', 1)",
                [$group, $permission]
            );
        }
    }

    public function down(): void
    {
        $this->db->table('permissions')
            ->whereIn('permission', ['picks.view', 'picks.play'])
            ->deleteAll();
    }
};
