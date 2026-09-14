<?php

if (! defined('ABSPATH')) {
    exit;
}

use Spinoko\Admin\CountryMeta;
use Spinoko\Core\CasinoCpt;
use Spinoko\Core\CasinoImporter;
use Spinoko\Core\CasinoMeta;
use Spinoko\Core\SlotImporter;
use Spinoko\Settings\SiteImporter;

/**
 * `wp spinoko-migrator import <file>` — step 2, run once the Spinoko v3
 * theme is active (composer install already done). Reads the JSON step
 * 1 produced and applies it via v3's own CasinoImporter/SlotImporter/
 * SiteImporter — this class only does the v2->v3 renaming/reshaping
 * those don't already know how to do; every actual write (post
 * creation, taxonomy term resolution, media sideloading, dedup-by-name)
 * is delegated to them. See spinoko-migrator.php's docblock for why
 * this is a separate step/command from export rather than one combined
 * tool.
 *
 * Deliberate, documented judgment calls made here (also printed in the
 * final report):
 *   - v2's single flat bonus (bonus_main/short/amount/percentage/terms/
 *     terms_url) becomes one 'welcome'-typed entry in v3's bonuses[]
 *     repeater — v2 never distinguished bonus types, so 'welcome' is a
 *     guess, not a read.
 *   - v2 carries both an allow-list ("Supported Countries") and a
 *     block-list ("Restricted Countries") field on the same casino, and
 *     an editor may fill in either one. v3 only has restricted_countries
 *     (block-list), so: if v2's restricted_countries is non-empty, it's
 *     used as-is; else if v2's countries (supported) is non-empty, its
 *     complement against the full country list is used instead; else
 *     restricted_countries is left empty. This mirrors v2's own
 *     spinoko_get_casino_countries() precedence (inc/functions/casino/
 *     countries.php) — see restrictedCountriesFor().
 *   - Posts/pages/menus are never read from the export JSON at all —
 *     export doesn't even capture them (see its docblock: only
 *     casino/game ACF data actually requires v2's runtime to read
 *     correctly). This is a same-install, theme-switch-in-place
 *     migration, not a cross-site import, so importPosts()/importMenus()
 *     query the *live* site directly instead:
 *       - Posts/pages are core WordPress content, untouched by a theme
 *         switch — every post/page already sitting on this install is
 *         classified as 'flagged' (its content contains an acf/* block,
 *         no automatic v3 equivalent, needs manual rebuilding in the v3
 *         editor — listed in the report with an edit link back to the
 *         original) or 'ok' (nothing to do). Nothing is ever created.
 *         An earlier version of this routed posts through the export
 *         JSON and matched by title on creation, via WordPress's own
 *         SiteImporter::importPost() — which sanitizes/strips-tags the
 *         title before its own dedup check runs, silently failing to
 *         match (and then creating a mangled-title duplicate of) any v2
 *         title containing literal HTML tags or a backslash. Reading
 *         live and never creating sidesteps that bug entirely rather
 *         than working around it inside SiteImporter itself.
 *       - Menus: `wp_get_nav_menu_items()`/nav_menu terms are also plain
 *         core data, but *which* menu is assigned to *which* location
 *         (header/footer/additional) lives in a theme-scoped option row
 *         (`theme_mods_{stylesheet}` — one per theme). Since v3 is
 *         active now, `get_theme_mod()` would read v3's own empty row;
 *         importMenus() instead reads v2's row directly by its known
 *         option key (`--v2-theme`, defaulting to 'spinoko') — an
 *         ordinary wp_options row, readable regardless of which theme
 *         happens to be active.
 *   - v3 registers the same 'casino' CPT slug as v2. On an in-place
 *     migration (same WP install, just switching the active theme) v2's
 *     original casino posts are still sitting in the same wp_posts table
 *     when this runs, occupying the URLs *and titles* we want the new v3
 *     casinos to reuse. Before creating anything, suffixLegacyCasinoSlugs()
 *     renames both the slug (+'-v2') and the title (+' (v2)') of every
 *     'casino' post with no v3 postmeta (i.e. every leftover v2-era
 *     post, identified by a missing 'casino_name' meta value) — freeing
 *     the original slug/title pair and tagging the leftover for an easy
 *     later cleanup query (`post_type=casino AND post_name LIKE
 *     '%-v2'`), which `wp spinoko-migrator cleanup-legacy` also
 *     uses. Renaming the slug alone was tried first and wasn't enough:
 *     CasinoImporter::importOne()'s own dedup is a *title* match, so a
 *     legacy post with its slug changed but title untouched still
 *     silently "skips" the new casino, mistaking the untouched legacy
 *     post for an already-migrated one. Each new v3 casino then gets its
 *     post_title and post_name force-set back to v2's exact original
 *     title/slug after creation (CasinoImporter's own 'name' input
 *     becomes the post_title otherwise, and doesn't accept a slug at
 *     all) — so the migrated casino's URL and page title stay exactly
 *     what they were pre-migration.
 *   - Games -> Slots get the same post_title/post_name restore after
 *     creation, for the same reason (SlotImporter's own 'name' input
 *     becomes the post_title, no slug concept). No legacy-slug/title
 *     suffixing step is needed there, unlike casinos — v2's 'game' CPT
 *     and v3's 'slot' CPT don't share a slug, so there's nothing to
 *     collide with.
 *   - v2's per-casino rating breakdown (ratings[], free-text-named,
 *     unlimited rows) maps onto v3's rating_breakdown (a *fixed* set of
 *     numbered slots, rating_1..CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS,
 *     each carrying just a score — the display name per slot is a
 *     sitewide Labels setting, not stored per casino at all)
 *     *positionally*: v2's Nth rating row becomes v3's rating_N, capped
 *     at RATING_BREAKDOWN_MAX_ITEMS, with no attempt to match by name.
 *     Matching by name was considered and rejected — it would mean
 *     either hardcoding this one site's specific category wording
 *     (doesn't generalize to a different v2 site's own naming) or
 *     silently guessing that two differently-worded names are the same
 *     concept (e.g. "Trust" vs "Trustworthiness"), neither of which
 *     this tool does anywhere else. Sitewide Labels are left completely
 *     untouched — renaming the slots to fit your data is a one-time
 *     site decision on the Labels screen, not something a migration
 *     script should make on your behalf. Each casino's original v2
 *     rating names, in the order they were mapped, are printed in the
 *     report so it's easy to see whether the slot order actually lines
 *     up consistently across casinos or needs reordering by hand — see
 *     buildRatingBreakdown().
 *
 * Fields genuinely dropped — v2 has real data here with no v3 field to
 * receive it, re-enter by hand if still needed: casino website /
 * deposit max; slot wilds / free spins / mobile-playable / reel rows;
 * classic widgets; Customizer colors, fonts and layout settings
 * (theme.json/global-styles is a different system entirely).
 *
 * v3-only fields v2 never collected — nothing to migrate, these are
 * just new fields available to fill in by hand on the migrated site,
 * *not* "no v3 equivalent": casino tagline / payout speed / customer
 * support / KYC speed / min withdrawal / blacklist status / geo-specific
 * affiliate links / currencies; most of the slot detail fields (release
 * date, languages, land-based, markets, cluster/scatter pays, bet/win
 * limits, bonus buy, autoplay, quickspin, tumbling reels, increasing
 * multipliers, orientation, restrictions, themes, types) — v3's slot
 * model is simply far more detailed than v2's game CPT ever was.
 */
class Spinoko_Migrator_Import_Command
{
    /** @var array<int,array{id:int,title:string,type:string,blocks:array<int,string>}> */
    private array $flaggedPosts = [];

    /** @var array<int,string> */
    private array $noCountryDataCasinos = [];

    /** @var array<int,array{id:int,old_title:string,new_title:string,old_slug:string,new_slug:string}> */
    private array $legacyCasinosRenamed = [];

    /** @var array<int,array{title:string,names:array<int,string>}> */
    private array $ratingBreakdownsMapped = [];

    /** @var array<int,array{title:string,dropped:array<int,string>}> */
    private array $ratingBreakdownsTruncated = [];

    /**
     * Imports a v2 export JSON file (see `wp spinoko-migrator export`)
     * into the active v3 theme's data model.
     *
     * ## OPTIONS
     *
     * <input>
     * : Path to the JSON file produced by `wp spinoko-migrator export`.
     *
     * [--dry-run]
     * : Report counts (created/skipped, by exact-name dedup) without writing anything.
     *
     * [--v2-theme=<slug>]
     * : v2 theme's stylesheet (folder) slug — used to read its nav_menu_locations from
     * `theme_mods_<slug>` directly, since v3 (not v2) is the active theme by the time
     * this runs. Only matters if v2 wasn't installed under the conventional folder name.
     * ---
     * default: spinoko
     * ---
     *
     * [--report=<file>]
     * : Path to also write the report to (Markdown), in addition to the terminal output.
     * ---
     * default: spinoko-migration-report.md
     * ---
     *
     * ## EXAMPLES
     *
     *     wp spinoko-migrator import v2-export.json
     *     wp spinoko-migrator import v2-export.json --dry-run
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        if (! class_exists(CasinoImporter::class)) {
            WP_CLI::error('This must be run while the Spinoko v3 theme is active — Spinoko\Core\CasinoImporter not found (check `composer install` ran in the v3 theme).');
        }

        $input = (string) ($args[0] ?? '');
        if ($input === '' || ! file_exists($input)) {
            WP_CLI::error('Pass the path to the JSON file produced by `wp spinoko-migrator export`.');
        }

        $dry_run = isset($assoc_args['dry-run']);
        $v2_theme = (string) ($assoc_args['v2-theme'] ?? 'spinoko');
        $report_path = (string) ($assoc_args['report'] ?? 'spinoko-migration-report.md');

        $data = json_decode((string) file_get_contents($input), true);
        if (! is_array($data)) {
            WP_CLI::error("Could not parse {$input} as JSON.");
        }

        $this->suffixLegacyCasinoSlugs($dry_run);

        $casino_tally = $this->importCasinos((array) ($data['casinos'] ?? []), $dry_run);
        $slot_tally = $this->importGames((array) ($data['games'] ?? []), $dry_run);
        $post_tally = $this->importPosts();
        $menu_tally = $this->importMenus($v2_theme, $dry_run);

        $this->printReport($casino_tally, $slot_tally, $post_tally, $menu_tally, $dry_run);

        if ($report_path !== '') {
            $this->writeReportFile($report_path, $casino_tally, $slot_tally, $post_tally, $menu_tally, $dry_run);
            WP_CLI::log("Report written to {$report_path}");
        }
    }

    // ---------------------------------------------------------------
    // Casinos
    // ---------------------------------------------------------------

    /**
     * Every 'casino' post with no v3 postmeta is a leftover from v2 —
     * see this class's docblock for why that's possible on an in-place
     * migration and why both its slug and its title need renaming (a
     * slug-only rename leaves CasinoImporter::importOne()'s own title
     * match still colliding with it). Renames only post_name/post_title
     * — never content, meta, or anything else — so the leftover post
     * stays fully intact for later cleanup, just out of the way.
     *
     * Slug and title are fixed independently and idempotently: each is
     * renamed only if it doesn't already carry its suffix, so re-running
     * this (repeated --dry-run, a real run after a prior partial or full
     * real run) never double-suffixes, and correctly finishes the job on
     * a post that was only half-fixed by an older version of this method
     * (slug suffixed, title not — exactly the state a prior run of the
     * slug-only version of this fix left behind).
     */
    private function suffixLegacyCasinoSlugs(bool $dry_run): void
    {
        $ids = get_posts([
            'post_type' => CasinoCpt::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($ids as $id) {
            if ($this->isV3Casino($id)) {
                continue;
            }

            $post = get_post($id);
            if (! $post) {
                continue;
            }

            $needs_slug_fix = $post->post_name !== '' && ! str_ends_with($post->post_name, '-v2');
            $needs_title_fix = $post->post_title !== '' && ! str_ends_with($post->post_title, ' (v2)');

            if (! $needs_slug_fix && ! $needs_title_fix) {
                continue;
            }

            $new_slug = $needs_slug_fix ? $post->post_name.'-v2' : $post->post_name;
            $new_title = $needs_title_fix ? $post->post_title.' (v2)' : $post->post_title;

            $this->legacyCasinosRenamed[] = [
                'id' => $id,
                'old_title' => $post->post_title,
                'new_title' => $new_title,
                'old_slug' => $post->post_name,
                'new_slug' => $new_slug,
            ];

            if (! $dry_run) {
                wp_update_post(['ID' => $id, 'post_title' => $new_title, 'post_name' => $new_slug]);
            }
        }
    }

    private function isV3Casino(int $post_id): bool
    {
        return (string) get_post_meta($post_id, 'casino_name', true) !== '';
    }

    private function importCasinos(array $casinos, bool $dry_run): array
    {
        $tally = ['created' => 0, 'skipped' => 0, 'error' => 0];

        foreach ($casinos as $casino) {
            if (! is_array($casino)) {
                continue;
            }

            $name = (string) ($casino['name'] ?: ($casino['title'] ?? ''));
            if ($name === '') {
                $tally['error']++;

                continue;
            }

            if ($dry_run) {
                // A blind CasinoImporter::findExistingId($name) title
                // match would also match a legacy v2 post that hasn't
                // been suffixed yet (dry-run mode never actually
                // suffixes) — filter those out so the preview reflects
                // what a real run would do (suffix first, then create),
                // not the current not-yet-suffixed state.
                $existing_id = CasinoImporter::findExistingId($name);
                $already_migrated = $existing_id && $this->isV3Casino($existing_id);
                $tally[$already_migrated ? 'skipped' : 'created']++;

                continue;
            }

            $result = CasinoImporter::importOne($this->transformCasino($casino));
            $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;

            // CasinoImporter::importOne() sets post_title from its
            // 'name' input (the ACF display name, e.g. "Slots Magic")
            // and has no concept of a slug/author/date/thumbnail at all
            // — restore all of v2's actual original values on a
            // freshly-created post so the migrated casino is unchanged
            // from v2 in every way that isn't its actual review content.
            if ($result['status'] === 'created' && $result['post_id']) {
                $this->restoreOriginalPostFields($result['post_id'], $casino, 'casino');
            }
        }

        return $tally;
    }

    private function transformCasino(array $c): array
    {
        $name = (string) ($c['name'] ?: ($c['title'] ?? ''));
        $license = trim((string) ($c['license'] ?? ''));
        $bonus = $this->buildBonus($c);

        return [
            'name' => $name,
            'status' => (string) ($c['status'] ?? 'draft'),
            'rating' => $c['rating_overall'] ?? 0,
            'established_year' => (int) preg_replace('/\D/', '', (string) ($c['established'] ?? '')),
            'min_deposit' => (string) ($c['deposit_min'] ?? ''),
            'affiliate_url' => (string) ($c['affiliate_link'] ?? ''),
            'licenses' => $license !== '' ? [$license] : [],
            'pros' => (array) ($c['pros'] ?? []),
            'cons' => (array) ($c['cons'] ?? []),
            'bonuses' => $bonus ? [$bonus] : [],
            'rating_breakdown' => $this->buildRatingBreakdown($c),
            'logo_url' => (string) ($c['logo_url'] ?: ($c['logo_square_url'] ?? '')),
            'restricted_countries' => $this->restrictedCountriesFor($c),
            'payment_methods' => array_values(array_unique(array_merge(
                (array) ($c['payment_methods'] ?? []),
                (array) ($c['payment_methods_additional'] ?? [])
            ))),
            'game_providers' => array_values(array_unique(array_merge(
                (array) ($c['game_providers'] ?? []),
                (array) ($c['game_providers_additional'] ?? [])
            ))),
            'games' => (array) ($c['games'] ?? []),
            'categories' => (array) ($c['categories'] ?? []),
            'tags' => (array) ($c['tags'] ?? []),
        ];
    }

    /**
     * Wraps v2's one flat bonus into a single v3 bonuses[] entry — see
     * this class's docblock for the 'welcome' type guess and the
     * bonus_short-over-bonus_main text choice. Input keys here are
     * CasinoImporter::importOne()'s actual contract (`text`/`code`/
     * `terms_short`/`terms_full`/`terms_link`), NOT the `bonus_*`-
     * prefixed post-meta field names those get written to internally.
     */
    private function buildBonus(array $c): ?array
    {
        $text = trim((string) ($c['bonus_short'] ?: ($c['bonus_main'] ?? '')));

        if ($text === '') {
            $amount = $c['bonus_amount'] ?? null;
            $percentage = $c['bonus_percentage'] ?? null;

            if ($percentage) {
                $text = $amount ? "{$percentage}% up to {$amount}" : "{$percentage}%";
            } elseif ($amount) {
                $text = (string) $amount;
            }
        }

        $terms_full = trim((string) ($c['terms'] ?? ''));
        $terms_link = trim((string) ($c['terms_url'] ?? ''));

        if ($text === '' && $terms_full === '' && $terms_link === '') {
            return null;
        }

        return [
            'type' => 'welcome',
            'text' => $text,
            'code' => '',
            'terms_short' => '',
            'terms_full' => $terms_full,
            'terms_link' => $terms_link,
            'affiliate_url' => '',
        ];
    }

    /**
     * v2 has both an allow-list (countries / "Supported Countries") and
     * a block-list (restricted_countries / "Restricted Countries") on
     * the same casino — an editor fills in whichever one made sense for
     * that casino, never necessarily both. v3 only has one field
     * (restricted_countries, a block-list), so the precedence mirrors
     * v2's own spinoko_get_casino_countries() (inc/functions/casino/
     * countries.php):
     *   1. v2's restricted_countries, if non-empty, is used directly.
     *   2. else v2's countries (supported), if non-empty, is complemented
     *      against the full country list.
     *   3. else restricted_countries is left empty and the casino is
     *      listed in the final report so its availability can be
     *      reviewed by hand — neither field had any data to go on.
     */
    private function restrictedCountriesFor(array $c): array
    {
        $all = CountryMeta::defaults(); // code => name, same list v2's own inc/_lists.php ships

        $restricted_codes = array_values(array_filter((array) ($c['restricted_countries'] ?? [])));
        if ($restricted_codes !== []) {
            return array_values(array_map(
                static fn (string $code): string => $all[$code] ?? $code,
                $restricted_codes
            ));
        }

        $supported_codes = array_values(array_filter((array) ($c['countries'] ?? [])));
        if ($supported_codes === []) {
            $this->noCountryDataCasinos[] = (string) ($c['title'] ?: ($c['name'] ?? '(untitled)'));

            return [];
        }

        $complement_codes = array_diff(array_keys($all), $supported_codes);

        return array_values(array_map(
            static fn (string $code): string => $all[$code] ?? $code,
            $complement_codes
        ));
    }

    /**
     * Positional, name-independent mapping onto v3's fixed rating_N
     * slots — see this class's docblock for why matching by name was
     * rejected. v2's Nth ratings[] row (in whatever order that casino
     * has them) becomes v3's rating_N; rows without a usable value are
     * skipped without consuming a slot number (so a casino with a blank
     * 3rd row still gets its 4th row into rating_3, not rating_4 with a
     * gap at rating_3); anything beyond CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS
     * is dropped and reported. CasinoMeta::sanitizeRatingBreakdown()
     * (called inside CasinoImporter::updateMeta()) does the actual
     * value clamping/validation on the way in — this only shapes the
     * {key, value} pairs, same "let v3's own class be the real gate"
     * pattern as everywhere else in this file.
     */
    private function buildRatingBreakdown(array $c): array
    {
        $rows = array_values(array_filter((array) ($c['ratings'] ?? []), 'is_array'));
        $max = CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS;
        $title = (string) ($c['title'] ?: ($c['name'] ?? '(untitled)'));

        $breakdown = [];
        $mapped_names = [];
        $dropped_names = [];

        foreach ($rows as $row) {
            $value = $row['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (count($breakdown) >= $max) {
                $dropped_names[] = (string) ($row['name'] ?? '');

                continue;
            }

            $slot = count($breakdown) + 1;
            $breakdown[] = ['key' => 'rating_'.$slot, 'value' => (float) $value];
            $mapped_names[] = (string) ($row['name'] ?? '(unnamed)');
        }

        if ($mapped_names !== []) {
            $this->ratingBreakdownsMapped[] = ['title' => $title, 'names' => $mapped_names];
        }

        if ($dropped_names !== []) {
            $this->ratingBreakdownsTruncated[] = ['title' => $title, 'dropped' => $dropped_names];
        }

        return $breakdown;
    }

    // ---------------------------------------------------------------
    // Games -> Slots
    // ---------------------------------------------------------------

    private function importGames(array $games, bool $dry_run): array
    {
        $tally = ['created' => 0, 'skipped' => 0, 'error' => 0];

        foreach ($games as $game) {
            if (! is_array($game)) {
                continue;
            }

            $name = (string) ($game['name'] ?: ($game['title'] ?? ''));
            if ($name === '') {
                $tally['error']++;

                continue;
            }

            if ($dry_run) {
                $tally[SlotImporter::findExistingId($name) ? 'skipped' : 'created']++;

                continue;
            }

            $result = SlotImporter::importOne($this->transformGame($game));
            $tally[$result['status']] = ($tally[$result['status']] ?? 0) + 1;

            // Same reasoning as importCasinos(): SlotImporter sets
            // post_title from its 'name' input and has no slug/author/
            // date/thumbnail concept at all, so restore v2's exact
            // original values on a freshly-created post — no CPT
            // collision here (v2's 'game' vs v3's 'slot'), so unlike
            // casinos this needs no legacy-slug suffixing first, just
            // the restore itself.
            if ($result['status'] === 'created' && $result['post_id']) {
                $this->restoreOriginalPostFields($result['post_id'], $game, 'slot');
            }
        }

        return $tally;
    }

    private function transformGame(array $g): array
    {
        return [
            'name' => (string) ($g['name'] ?: ($g['title'] ?? '')),
            'status' => (string) ($g['status'] ?? 'draft'),
            'rating' => $g['rating'] ?? 0,
            'linked_casino_name' => (string) ($g['linked_casino_title'] ?? ''),
            'screenshot_url' => (string) ($g['image_url'] ?? ''),
            'pros' => (array) ($g['pros'] ?? []),
            'cons' => (array) ($g['cons'] ?? []),
            'top_casinos' => (array) ($g['top_casino_titles'] ?? []),
            'game_providers' => (array) ($g['game_providers'] ?? []),
            'reels' => $g['reels'] ?? 0,
            'rtp' => $g['rtp'] ?? 0,
            'volatility' => (string) ($g['volatility'] ?? ''),
            'payline' => ($g['paylines'] ?? '') !== '' ? (string) $g['paylines'] : '',
            'progressive' => (bool) ($g['progressive'] ?? false),
        ];
    }

    /**
     * CasinoImporter/SlotImporter know nothing about slug, author, date,
     * post_content or featured image — restores all five from v2's
     * original post onto a freshly-created v3 post. Author/featured
     * image are resolved by ID straight off the export JSON (safe: same
     * users table/media library, same-install migration — see this
     * class's docblock); post_content, deliberately, is NOT read from
     * the export JSON at all — it's plain post_content, unaffected by
     * which theme is active, so it's read live off v2's original post
     * (by the 'id' the export JSON carries) same as importPosts()/
     * importMenus() already do for other plain-WordPress data, rather
     * than bloating the JSON with a duplicate copy that could go stale
     * between the export and import steps. That original post is still
     * sitting untouched under that same ID right up until
     * `cleanup-legacy` runs — suffixLegacyCasinoSlugs() only ever
     * changes its slug/title, never its ID.
     *
     * Each piece is independently guarded (missing/deleted user,
     * attachment, or original post just skips that one field) so a
     * stale export doesn't fail the whole restore.
     *
     * The copied content is scanned for acf/* blocks the same way
     * importPosts() already scans posts/pages — v2's casino/game
     * post_content can carry the exact same unconvertible blocks, so a
     * hit here is added to the same $flaggedPosts list/report section
     * rather than silently landing on the new casino/slot unnoticed.
     *
     * @param 'casino'|'slot' $type only used for the flagged-posts report entry
     */
    private function restoreOriginalPostFields(int $post_id, array $original, string $type): void
    {
        $update = ['ID' => $post_id];

        $title = trim((string) ($original['title'] ?? ''));
        if ($title !== '') {
            $update['post_title'] = $title;
        }

        $slug = trim((string) ($original['slug'] ?? ''));
        if ($slug !== '') {
            $update['post_name'] = $slug;
        }

        $author_id = (int) ($original['author_id'] ?? 0);
        if ($author_id && get_userdata($author_id)) {
            $update['post_author'] = $author_id;
        }

        $date = trim((string) ($original['date'] ?? ''));
        if ($date !== '') {
            $update['post_date'] = $date;
            $date_gmt = trim((string) ($original['date_gmt'] ?? ''));
            $update['post_date_gmt'] = $date_gmt !== '' ? $date_gmt : get_gmt_from_date($date);
        }

        $original_id = (int) ($original['id'] ?? 0);
        $original_post = $original_id ? get_post($original_id) : null;
        if ($original_post && $original_post->post_content !== '') {
            $update['post_content'] = $original_post->post_content;

            $acf_blocks = $this->detectAcfBlocks($original_post->post_content);
            if ($acf_blocks !== []) {
                $this->flaggedPosts[] = ['id' => $post_id, 'title' => $title, 'type' => $type, 'blocks' => $acf_blocks];
            }
        }

        if (count($update) > 1) {
            wp_update_post($update);
        }

        $thumbnail_id = (int) ($original['featured_image_id'] ?? 0);
        if ($thumbnail_id && get_post($thumbnail_id)) {
            set_post_thumbnail($post_id, $thumbnail_id);
        }
    }

    // ---------------------------------------------------------------
    // Posts / pages
    // ---------------------------------------------------------------

    /**
     * Posts/pages are core WordPress content — switching the active
     * theme never touches them, so on this same install every one of
     * them is already sitting exactly where it needs to be. Queried live
     * (never read from the export JSON — see this class's docblock),
     * and never created or modified; each is only classified as
     * 'flagged' (its content contains an acf/* block, no automatic v3
     * equivalent, needs manual rebuilding) or 'ok' (nothing to do).
     */
    private function importPosts(): array
    {
        $tally = ['ok' => 0, 'flagged' => 0];

        $ids = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        foreach ($ids as $id) {
            $post = get_post($id);
            if (! $post) {
                continue;
            }

            $acf_blocks = $this->detectAcfBlocks((string) $post->post_content);
            if ($acf_blocks !== []) {
                $this->flaggedPosts[] = ['id' => $id, 'title' => $post->post_title, 'type' => $post->post_type, 'blocks' => $acf_blocks];
                $tally['flagged']++;

                continue;
            }

            $tally['ok']++;
        }

        return $tally;
    }

    /**
     * Every distinct acf/* block name found in the post's serialized
     * content. These have no automatic v3 block equivalent (see this
     * class's docblock), so the post is skipped entirely rather than
     * created with missing/broken blocks — listed in the final report
     * for manual rebuilding in the v3 editor instead.
     */
    private function detectAcfBlocks(string $content): array
    {
        preg_match_all('#<!--\s*wp:(acf/[a-z0-9\-]+)#i', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    // ---------------------------------------------------------------
    // Classic nav menus -> wp_navigation block posts
    // ---------------------------------------------------------------

    /**
     * Reads v2's nav_menu_locations directly off its theme_mods option
     * row ($v2_theme_slug — see this class's docblock for why v3 being
     * the active theme means get_theme_mod() can't be used here), then
     * walks each assigned menu's live items — never read from the export
     * JSON, same reasoning as importPosts().
     */
    private function importMenus(string $v2_theme_slug, bool $dry_run): array
    {
        $mods = get_option("theme_mods_{$v2_theme_slug}");
        $locations = is_array($mods) ? (array) ($mods['nav_menu_locations'] ?? []) : [];

        $menus = [];
        foreach (['menu-1' => 'Header', 'menu-2' => 'Footer', 'menu-additional' => 'Additional'] as $location => $label) {
            $menu_id = (int) ($locations[$location] ?? 0);
            if (! $menu_id) {
                continue;
            }

            $menu = wp_get_nav_menu_object($menu_id);
            if (! $menu) {
                continue;
            }

            $items = wp_get_nav_menu_items($menu_id);
            if (! $items) {
                continue;
            }

            $menus[] = [
                'name' => $menu->name ?: $label,
                'items' => $this->buildMenuTree($items, 0),
            ];
        }

        if ($dry_run) {
            return ['created' => count($menus), 'skipped' => 0];
        }

        $payload = array_map(
            fn (array $menu): array => [
                'name' => $menu['name'],
                'items' => $this->convertMenuItems($menu['items']),
            ],
            $menus
        );

        return SiteImporter::importMenus($payload);
    }

    /**
     * wp_get_nav_menu_items() returns a flat list with menu_item_parent
     * references — rebuilt here into a nested {type, object, object_id,
     * object_title, label, url, children} tree, one level of recursion
     * per submenu depth. Was export's buildMenuTree() before menus moved
     * to being read live — unchanged otherwise.
     */
    private function buildMenuTree(array $items, int $parent_id): array
    {
        $children = array_values(array_filter(
            $items,
            static fn (WP_Post $item): bool => (int) $item->menu_item_parent === $parent_id
        ));

        return array_map(
            fn (WP_Post $item): array => [
                'type' => $item->type, // 'custom' | 'post_type' | 'taxonomy'
                'object' => $item->object, // e.g. 'page', 'post', 'category'
                'object_id' => (int) $item->object_id,
                'object_title' => $item->object_id ? get_the_title((int) $item->object_id) : '',
                'label' => $item->title,
                'url' => $item->url,
                'children' => $this->buildMenuTree($items, (int) $item->ID),
            ],
            $children
        );
    }

    /**
     * Converts the {type, object, object_id, object_title, label, url,
     * children} tree above into SiteImporter::importMenus()'s {block,
     * label, target_title/type, url, children} shape, reusing its own
     * navigation-block-building and post-title resolution rather than
     * duplicating it. A v2 taxonomy-type menu item (a category/tag
     * archive link) has no post-type target to resolve, so it falls
     * through to a plain 'custom' link carrying its old URL — a
     * best-effort fallback, not a verified-working link if the permalink
     * structure changed.
     */
    private function convertMenuItems(array $items): array
    {
        return array_map(function (array $item): array {
            $children = $this->convertMenuItems((array) ($item['children'] ?? []));
            $is_submenu = $children !== [];

            $entry = [
                'block' => $is_submenu ? 'submenu' : 'link',
                'label' => (string) ($item['label'] ?? ''),
            ];

            if (($item['type'] ?? '') === 'post_type' && (string) ($item['object_title'] ?? '') !== '') {
                $entry['target_type'] = (string) ($item['object'] ?? 'page');
                $entry['target_title'] = (string) $item['object_title'];
            } else {
                $entry['url'] = (string) ($item['url'] ?? '');
            }

            if ($is_submenu) {
                $entry['children'] = $children;
            }

            return $entry;
        }, array_filter($items, 'is_array'));
    }

    // ---------------------------------------------------------------
    // Report
    // ---------------------------------------------------------------

    private function printReport(array $casinos, array $slots, array $posts, array $menus, bool $dry_run): void
    {
        $prefix = $dry_run ? '[DRY RUN] ' : '';

        WP_CLI::log('');
        WP_CLI::log($prefix.'Casinos: '.$this->formatTally($casinos));
        WP_CLI::log($prefix.'Slots:   '.$this->formatTally($slots));
        WP_CLI::log($prefix.'Posts:   '.$this->formatTally($posts));
        WP_CLI::log($prefix.'Menus:   '.$this->formatTally($menus));

        if ($dry_run) {
            WP_CLI::log('');
            WP_CLI::warning('--dry-run does not preview ACF-block content flags for casinos/slots (only for posts/pages, above) — that check runs against post_content copied during actual post creation, which --dry-run skips. Run for real to see the full flagged list.');
        }

        if ($this->flaggedPosts !== []) {
            WP_CLI::log('');
            WP_CLI::warning(count($this->flaggedPosts).' post(s)/page(s)/casino(s)/slot(s) contain ACF blocks with no v3 equivalent — rebuild manually in the v3 editor, using the v2 content at the edit link below as reference:');
            foreach ($this->flaggedPosts as $flagged) {
                WP_CLI::log(sprintf('  - [%s] %s  (%s)  %s', $flagged['type'], $flagged['title'], implode(', ', $flagged['blocks']), $this->editLink($flagged['id'])));
            }
        }

        if ($this->noCountryDataCasinos !== []) {
            WP_CLI::log('');
            WP_CLI::warning(count($this->noCountryDataCasinos).' casino(s) had neither "Supported Countries" nor "Restricted Countries" set in v2 — restricted_countries left empty, review availability manually:');
            foreach ($this->noCountryDataCasinos as $title) {
                WP_CLI::log("  - {$title}");
            }
        }

        if ($this->legacyCasinosRenamed !== []) {
            WP_CLI::log('');
            WP_CLI::log($prefix.count($this->legacyCasinosRenamed)." leftover v2-era casino post(s) had their slug/title suffixed with -v2 / (v2) to free the URL and title for the migrated version. Run `wp spinoko-migrator cleanup-legacy` once you've confirmed the import looks right:");
            foreach ($this->legacyCasinosRenamed as $renamed) {
                WP_CLI::log(sprintf('  - %s -> %s  (%s -> %s)', $renamed['old_title'], $renamed['new_title'], $renamed['old_slug'], $renamed['new_slug']));
            }
        }

        if ($this->ratingBreakdownsMapped !== []) {
            WP_CLI::log('');
            WP_CLI::log('Rating breakdown mapped positionally (v2\'s Nth rating -> v3\'s rating_N slot, names not used for matching — see this class\'s docblock). Check the order lines up the way you want, then name the '.CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS.' slots on the Labels screen:');
            foreach ($this->ratingBreakdownsMapped as $mapped) {
                $slots = [];
                foreach ($mapped['names'] as $i => $rating_name) {
                    $slots[] = 'rating_'.($i + 1).'='.$rating_name;
                }
                WP_CLI::log(sprintf('  - %s: %s', $mapped['title'], implode(', ', $slots)));
            }
        }

        if ($this->ratingBreakdownsTruncated !== []) {
            WP_CLI::log('');
            WP_CLI::warning('Casino(s) had more ratings than v3\'s '.CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS.' slots — extra ones were dropped:');
            foreach ($this->ratingBreakdownsTruncated as $truncated) {
                WP_CLI::log(sprintf('  - %s: dropped %s', $truncated['title'], implode(', ', $truncated['dropped'])));
            }
        }

        WP_CLI::log('');
        WP_CLI::log('No v2->v3 equivalent (data exists in v2, nothing to receive it in v3 — re-enter by hand if still needed): casino website / deposit max; slot wilds / free spins / mobile-playable / reel rows; classic widgets; Customizer colors, fonts and layout settings.');
        WP_CLI::log('v3-only fields v2 never collected (nothing to migrate, just new fields you can fill in): casino tagline / payout speed / customer support / KYC speed / min withdrawal / blacklist status / geo affiliate links / currencies; most slot detail fields (release date, languages, land-based, markets, cluster/scatter pays, bet/win limits, bonus buy, autoplay, quickspin, tumbling reels, increasing multipliers, orientation, restrictions, themes, types).');

        WP_CLI::log('');
        WP_CLI::success($dry_run ? 'Dry run complete — nothing was written.' : 'Import complete.');
    }

    /**
     * Built directly via admin_url() rather than get_edit_post_link(),
     * which gates on current_user_can() — WP-CLI has no logged-in user
     * by default, so that would silently return an empty string here.
     */
    private function editLink(int $post_id): string
    {
        return $post_id ? admin_url("post.php?post={$post_id}&action=edit") : '';
    }

    private function writeReportFile(string $path, array $casinos, array $slots, array $posts, array $menus, bool $dry_run): void
    {
        $lines = [];
        $lines[] = '# Spinoko v2 → v3 Migration Report';
        $lines[] = '';
        $lines[] = ($dry_run ? '**DRY RUN** — nothing was written. ' : '**Real run.** ').'Generated '.current_time('mysql').'.';
        $lines[] = '';

        if ($dry_run) {
            $lines[] = '**Note:** this dry run does not preview ACF-block content flags for casinos/slots (only for posts/pages, below) — that check runs against post_content copied during actual post creation, which a dry run skips. Run for real to see the full flagged list.';
            $lines[] = '';
        }

        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '- Casinos: '.$this->formatTally($casinos);
        $lines[] = '- Slots: '.$this->formatTally($slots);
        $lines[] = '- Posts/pages: '.$this->formatTally($posts);
        $lines[] = '- Menus: '.$this->formatTally($menus);
        $lines[] = '';

        if ($this->flaggedPosts !== []) {
            $lines[] = '## Posts/pages/casinos/slots needing manual review (ACF blocks, no v3 equivalent)';
            $lines[] = '';
            $lines[] = '| Type | Title | Blocks | Edit (v2 content) |';
            $lines[] = '|---|---|---|---|';
            foreach ($this->flaggedPosts as $flagged) {
                $lines[] = sprintf(
                    '| %s | %s | `%s` | [Edit](%s) |',
                    $flagged['type'],
                    str_replace('|', '\\|', $flagged['title']),
                    implode('`, `', $flagged['blocks']),
                    $this->editLink($flagged['id'])
                );
            }
            $lines[] = '';
        }

        if ($this->noCountryDataCasinos !== []) {
            $lines[] = '## Casinos with no country data (restricted_countries left empty)';
            $lines[] = '';
            foreach ($this->noCountryDataCasinos as $title) {
                $lines[] = "- {$title}";
            }
            $lines[] = '';
        }

        if ($this->legacyCasinosRenamed !== []) {
            $lines[] = '## Legacy v2 casino posts renamed, pending cleanup';
            $lines[] = '';
            $lines[] = 'Run `wp spinoko-migrator cleanup-legacy` once the import above looks right.';
            $lines[] = '';
            $lines[] = '| Old title -> new title | Old slug -> new slug |';
            $lines[] = '|---|---|';
            foreach ($this->legacyCasinosRenamed as $renamed) {
                $lines[] = sprintf('| %s -> %s | %s -> %s |', $renamed['old_title'], $renamed['new_title'], $renamed['old_slug'], $renamed['new_slug']);
            }
            $lines[] = '';
        }

        if ($this->ratingBreakdownsMapped !== []) {
            $lines[] = '## Rating breakdown — mapped positionally';
            $lines[] = '';
            $lines[] = "v2's Nth rating became v3's rating_N slot; names below are v2's original wording, shown only so you can check the order lines up consistently across casinos, then name the ".CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS.' slots on the Labels screen (site-wide, not per casino).';
            $lines[] = '';
            $lines[] = '| Casino | Slot order (rating_1, rating_2, ...) |';
            $lines[] = '|---|---|';
            foreach ($this->ratingBreakdownsMapped as $mapped) {
                $lines[] = sprintf('| %s | %s |', $mapped['title'], implode(', ', $mapped['names']));
            }
            $lines[] = '';
        }

        if ($this->ratingBreakdownsTruncated !== []) {
            $lines[] = '## Rating breakdown — extra ratings dropped';
            $lines[] = '';
            $lines[] = 'These casinos had more ratings than v3\'s '.CasinoMeta::RATING_BREAKDOWN_MAX_ITEMS.' slots.';
            $lines[] = '';
            foreach ($this->ratingBreakdownsTruncated as $truncated) {
                $lines[] = sprintf('- %s: dropped %s', $truncated['title'], implode(', ', $truncated['dropped']));
            }
            $lines[] = '';
        }

        $lines[] = '## Fields not carried over';
        $lines[] = '';
        $lines[] = '**No v2→v3 equivalent** (data exists in v2, nothing to receive it in v3 — re-enter by hand if still needed): casino website, deposit max; slot wilds, free spins, mobile-playable, reel rows; classic widgets; Customizer colors, fonts and layout settings.';
        $lines[] = '';
        $lines[] = '**v3-only fields v2 never collected** (nothing to migrate — new fields available to fill in): casino tagline, payout speed, customer support, KYC speed, min withdrawal, blacklist status, geo affiliate links, currencies; most slot detail fields (release date, languages, land-based, markets, cluster/scatter pays, bet/win limits, bonus buy, autoplay, quickspin, tumbling reels, increasing multipliers, orientation, restrictions, themes, types).';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
    }

    private function formatTally(array $tally): string
    {
        $parts = [];
        foreach ($tally as $key => $count) {
            $parts[] = "{$count} {$key}";
        }

        return implode(', ', $parts);
    }
}
