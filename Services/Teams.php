<?php

declare(strict_types=1);

namespace Convoro\Extensions\Picks\Services;

use Convoro\Engine\Database\Connection;

/**
 * The teams, and the forum each of them already has on this site.
 *
 * 🚨 Every team is loaded once per request and kept in memory. There are 139 of
 * them and a week of games references most; the alternative is two joins onto
 * the same table per query, which this database layer cannot express — a join
 * target is backtick-wrapped, so `picks_teams AS home_team` becomes a table
 * name with a space in it. One small query and a lookup is both faster and
 * expressible.
 *
 * 🚨 Nothing here ever writes to `forums`. The link is resolved by matching a
 * team's name against forum titles and stored on the TEAM's row, so a
 * pick'em can put a link on a game without owning anything in somebody else's
 * table. A team with no forum is normal and every screen copes.
 */
final class Teams
{
    /**
     * Forum titles that are a team under another name.
     *
     * 🚨 Four of these are misspellings on the live site — Deleware, Lousiana,
     * Perdue, Pittsburg — and they are mapped rather than corrected. Renaming
     * somebody's forums to make a link appear is a decision for them, not a
     * side effect of installing a pick'em.
     *
     * 🚨 The same table exists in core, privately, inside
     * `Convoro\Modules\System\Services\Flarum\TeamLogos`. It is copied rather
     * than shared because that class is a one-shot importer wired to two
     * database connections and its map is a private constant. Two copies of a
     * list is a real cost and it is named here so the next person knows there
     * is a second one to change.
     */
    private const FORUM_ALIASES = [
        'Appalachian State' => 'App State',
        'Deleware' => 'Delaware',
        'Hawaii' => "Hawai'i",
        'Lousiana' => 'Louisiana',
        'Mizzou' => 'Missouri',
        'MTSU' => 'Middle Tennessee',
        'Perdue' => 'Purdue',
        'Pittsburg' => 'Pittsburgh',
        'Sam Houston State' => 'Sam Houston',
        'UMass' => 'Massachusetts',
    ];

    /** @var array<int, array<string, mixed>>|null id => team */
    private ?array $byId = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every team, keyed by id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function map(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $this->byId = [];

        foreach ($this->rows() as $row) {
            $this->byId[(int) $row['id']] = $this->shape($row);
        }

        return $this->byId;
    }

    /**
     * Every team row, with its forum's slug where there is one.
     *
     * 🚨 The slug is JOINED rather than stored on the team, so renaming a forum
     * cannot leave a link pointing at an address that no longer resolves. The
     * id is what is stored, because that is the thing that does not change.
     *
     * 🚨 One join and no alias. Falls back to the plain table when `forums` is
     * not there at all — a site with no forum module is a normal site, not a
     * broken one, and every screen already copes with an unlinked team.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        $teams = $this->db->prefixed('picks_teams');
        $forums = $this->db->prefixed('forums');

        try {
            return $this->db->table('picks_teams')
                ->select($teams . '.*', $forums . '.slug AS forum_slug')
                ->leftJoin($forums, $forums . '.id', '=', $teams . '.forum_id')
                ->orderBy($teams . '.name')
                ->get();
        } catch (\Throwable) {
            return $this->db->table('picks_teams')->orderBy('name')->get();
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->map());
    }

    /** @return array<string, mixed>|null */
    public function byId(int $id): ?array
    {
        return $this->map()[$id] ?? null;
    }

    /**
     * Teams whose name contains this text, as ids.
     *
     * Searched in memory rather than with a LIKE, because the whole list is
     * already here and 139 strings is not a query.
     *
     * @return list<int>
     */
    public function idsMatching(string $needle): array
    {
        $needle = trim(mb_strtolower($needle));

        if ($needle === '') {
            return [];
        }

        $out = [];

        foreach ($this->map() as $id => $team) {
            if (str_contains(mb_strtolower((string) $team['name']), $needle)
                || str_contains(mb_strtolower((string) $team['abbreviation']), $needle)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** @return list<string> every conference that has a team, sorted */
    public function conferences(): array
    {
        $out = [];

        foreach ($this->map() as $team) {
            $conference = trim((string) $team['conference']);

            if ($conference !== '' && !in_array($conference, $out, true)) {
                $out[] = $conference;
            }
        }

        sort($out);

        return $out;
    }

    public function count(): int
    {
        return count($this->map());
    }

    /** How many teams have no logo, for the admin screen. */
    public function withoutLogo(): int
    {
        $missing = 0;

        foreach ($this->map() as $team) {
            if ((string) $team['logo_path'] === '') {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * Creates or updates one team from a provider.
     *
     * 🚨 A logo an operator marked custom is never overwritten. That flag is
     * the only way to keep a hand-picked crest through a sync, and a sync that
     * ignored it would undo the same piece of work every night.
     *
     * @param array<string, mixed> $values
     * @return int the team's id, or 0 when there was not enough to store
     */
    public function upsert(array $values): int
    {
        $name = trim((string) ($values['name'] ?? ''));
        $cfbdId = (int) ($values['cfbd_id'] ?? 0);

        if ($name === '' || $cfbdId < 1) {
            return 0;
        }

        $slug = $this->slug($name);

        $existing = $this->db->table('picks_teams')->where('cfbd_id', $cfbdId)->first()
            ?? $this->db->table('picks_teams')->where('slug', $slug)->first();

        $changed = [
            'name' => mb_substr($name, 0, 190),
            'abbreviation' => mb_substr(trim((string) ($values['abbreviation'] ?? '')), 0, 16),
            'conference' => mb_substr(trim((string) ($values['conference'] ?? '')), 0, 100),
            'cfbd_id' => $cfbdId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $espnId = (int) ($values['espn_id'] ?? 0);

        if ($espnId > 0) {
            $changed['espn_id'] = $espnId;
        }

        if ($existing === null) {
            $this->byId = null;

            return (int) $this->db->table('picks_teams')->insertGetId($changed + [
                'slug' => $slug,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if ((int) $existing['logo_custom'] !== 1 && $espnId > 0) {
            $changed['logo_path'] = self::logoUrl($espnId, false);
            $changed['logo_dark_path'] = self::logoUrl($espnId, true);
        }

        $this->db->table('picks_teams')->where('id', $existing['id'])->updateAll($changed);
        $this->byId = null;

        return (int) $existing['id'];
    }

    /**
     * A logo an operator typed in by hand.
     *
     * 🚨 Setting either address marks the team custom, which is what makes the
     * choice survive the next sync. Clearing both hands the team back to the
     * provider.
     */
    public function setLogo(int $id, string $light, string $dark): void
    {
        $light = trim($light);
        $dark = trim($dark);

        $this->db->table('picks_teams')->where('id', $id)->updateAll([
            'logo_path' => mb_substr($light, 0, 255),
            'logo_dark_path' => mb_substr($dark, 0, 255),
            'logo_custom' => ($light === '' && $dark === '') ? 0 : 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $this->byId = null;
    }

    /**
     * Points every team at its forum, where this site has one.
     *
     * 🚨 Reads `forums` and writes only `picks_teams`. A pick'em that started
     * editing forum rows to make its own links work would be an extension
     * nobody could uninstall cleanly.
     *
     * @return array{linked: int, unmatched: int}
     */
    public function linkForums(): array
    {
        $forums = [];

        try {
            /*
             * 🚨 A forum with any row in `forum_groups` is restricted to those
             * groups, and is skipped. A link is a small thing, but a link on a
             * public game page to a forum somebody is not allowed to see tells
             * them it exists and what it is called — which is the entire point
             * of restricting it. No rows means everybody, which is the normal
             * case for the 139 team forums this was built for.
             */
            $restricted = [];

            foreach ($this->db->table('forum_groups')->select('forum_id')->get() as $row) {
                $restricted[(int) $row['forum_id']] = true;
            }

            foreach ($this->db->table('forums')->where('type', 'forum')->get() as $forum) {
                if (isset($restricted[(int) $forum['id']])) {
                    continue;
                }

                $title = (string) $forum['title'];
                $name = self::FORUM_ALIASES[$title] ?? $title;
                $forums[self::key($name)] = (int) $forum['id'];
            }
        } catch (\Throwable) {
            // No forums table, or no forum module. Not a fault: the link is a
            // convenience and every screen already copes without it.
            return ['linked' => 0, 'unmatched' => $this->count()];
        }

        $linked = 0;
        $unmatched = 0;

        foreach ($this->map() as $id => $team) {
            $forumId = $forums[self::key((string) $team['name'])] ?? 0;

            if ($forumId === 0) {
                $unmatched++;
            } else {
                $linked++;
            }

            if ($forumId !== (int) $team['forum_id']) {
                $this->db->table('picks_teams')->where('id', $id)->updateAll(['forum_id' => $forumId]);
            }
        }

        $this->byId = null;

        return ['linked' => $linked, 'unmatched' => $unmatched];
    }

    /** ESPN publishes a light-ground and a dark-ground mark for every team. */
    public static function logoUrl(int $espnId, bool $dark): string
    {
        return 'https://a.espncdn.com/i/teamlogos/ncaa/' . ($dark ? '500-dark/' : '500/') . $espnId . '.png';
    }

    /**
     * A name reduced to what is worth comparing.
     *
     * 🚨 Accents are FOLDED, not stripped. Removing them turns "San José State"
     * into "sanjosstate", which matches nothing — the letter has to become `e`,
     * not vanish. This cost one team on the live site before it was noticed,
     * and the symptom was a single missing logo among 138.
     */
    public static function key(string $name): string
    {
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);

        if ($folded === false) {
            $folded = $name;
        }

        // TRANSLIT can emit `'e` or `"o` for a folded letter depending on the
        // platform's iconv; dropping everything but letters and digits handles
        // both without caring which one this machine does.
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($folded)) ?? '';
    }

    private function slug(string $name): string
    {
        $slug = trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(
            (string) (@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name)
        )) ?? '') ?? '', '-');

        return $slug === '' ? 'team' : mb_substr($slug, 0, 190);
    }

    /** @param array<string, mixed> $row */
    private function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'abbreviation' => (string) $row['abbreviation'],
            'conference' => (string) $row['conference'],
            'cfbd_id' => $row['cfbd_id'] === null ? 0 : (int) $row['cfbd_id'],
            'espn_id' => (int) $row['espn_id'],
            'logo_path' => (string) $row['logo_path'],
            'logo_dark_path' => (string) $row['logo_dark_path'],
            'logo_custom' => (int) $row['logo_custom'] === 1,
            'forum_id' => (int) $row['forum_id'],
            'forum_slug' => (string) ($row['forum_slug'] ?? ''),
        ];
    }
}
