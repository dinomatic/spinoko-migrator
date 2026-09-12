<?php

if (! defined('ABSPATH')) {
    exit;
}

use Spinoko\Core\CasinoCpt;

/**
 * `wp spinoko-migrator cleanup-legacy` — step 3, run once
 * `wp spinoko-migrator import` has already run for real and you've
 * confirmed the migrated casinos/slots look right.
 *
 * Two separate kinds of v2 leftover, found two different ways:
 *
 *   - Casino: `import`'s suffixLegacyCasinoSlugs() renames (never
 *     deletes) every leftover v2-era 'casino' post so the migrated v3
 *     casino can reuse its original slug/title — see that class's
 *     docblock. A 'casino' post only counts as a leftover here if it
 *     matches *both* signals import used to identify it in the first
 *     place: no v3 'casino_name' postmeta, and a slug already ending in
 *     '-v2' (i.e. it really did go through suffixLegacyCasinoSlugs() —
 *     never touches a post just because it happens to lack that meta
 *     key for some other reason).
 *   - Game: v2's 'game' CPT has no v3 equivalent at all (v3's slot data
 *     lives in its own 'slot' CPT, under new post IDs) — v3 doesn't
 *     register a 'game' post type, doesn't read its postmeta, and
 *     `import` never needed to rename it out of anyone's way (no slug
 *     collision like casinos have). So unlike casinos, no signature
 *     check is needed: every 'game' post on a v3-active site is by
 *     definition a v2 leftover.
 *
 * Kept as its own command rather than an `import` flag so a bad import
 * can still be diagnosed against the untouched leftovers before
 * anything is removed.
 */
class Spinoko_Migrator_Cleanup_Command
{
    /**
     * Removes v2-era leftovers: 'casino' posts `import` renamed out of
     * the way (post_name suffixed '-v2', no v3 postmeta), and every
     * 'game' post (v2's CPT, no v3 equivalent).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List what would be removed without removing anything.
     *
     * [--force]
     * : Permanently delete instead of moving to trash.
     *
     * ## EXAMPLES
     *
     *     wp spinoko-migrator cleanup-legacy --dry-run
     *     wp spinoko-migrator cleanup-legacy
     *     wp spinoko-migrator cleanup-legacy --force
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        if (! class_exists(CasinoCpt::class)) {
            WP_CLI::error('This must be run while the Spinoko v3 theme is active — Spinoko\Core\CasinoCpt not found.');
        }

        $dry_run = isset($assoc_args['dry-run']);
        $force = isset($assoc_args['force']);

        $casinos = $this->findLegacyCasinos();
        $games = $this->findLegacyGames();

        if ($casinos === [] && $games === []) {
            WP_CLI::success('Nothing to clean up — no leftover v2-era casino or game posts found.');

            return;
        }

        $prefix = $dry_run ? '[DRY RUN] ' : '';
        $verb = $force ? 'permanently deleted' : 'trashed';

        if ($casinos !== []) {
            WP_CLI::log("Casinos ({$verb}):");
            $this->removeAll($casinos, $dry_run, $force, $prefix, $verb);
        }

        if ($games !== []) {
            WP_CLI::log('');
            WP_CLI::log("Games ({$verb}):");
            $this->removeAll($games, $dry_run, $force, $prefix, $verb);
        }

        WP_CLI::log('');
        WP_CLI::success(sprintf(
            '%s%d casino(s) and %d game(s) %s.',
            $prefix,
            count($casinos),
            count($games),
            $dry_run ? 'would be '.$verb : $verb
        ));
    }

    /**
     * @return array<int,WP_Post>
     */
    private function findLegacyCasinos(): array
    {
        $ids = get_posts([
            'post_type' => CasinoCpt::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        $targets = [];
        foreach ($ids as $id) {
            $has_v3_meta = (string) get_post_meta($id, 'casino_name', true) !== '';
            $post = get_post($id);

            if ($has_v3_meta || ! $post || ! str_ends_with($post->post_name, '-v2')) {
                continue;
            }

            $targets[] = $post;
        }

        return $targets;
    }

    /**
     * @return array<int,WP_Post>
     */
    private function findLegacyGames(): array
    {
        $ids = get_posts([
            'post_type' => 'game',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        return array_values(array_filter(array_map('get_post', $ids)));
    }

    /**
     * @param array<int,WP_Post> $posts
     */
    private function removeAll(array $posts, bool $dry_run, bool $force, string $prefix, string $verb): void
    {
        foreach ($posts as $post) {
            WP_CLI::log(sprintf('  %s%s: %s (%s)', $prefix, $verb, $post->post_title, $post->post_name));

            if ($dry_run) {
                continue;
            }

            if ($force) {
                wp_delete_post($post->ID, true);
            } else {
                wp_trash_post($post->ID);
            }
        }
    }
}
