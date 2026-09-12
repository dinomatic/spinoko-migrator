<?php

if (! defined('ABSPATH')) {
    exit;
}

use Spinoko\Core\CasinoCpt;

/**
 * `wp spinoko-migrator convert-blocks` — a separate, optional step from
 * `import`/`cleanup-legacy` (never run automatically by either). Rewrites
 * existing posts/pages' post_content *in place*: same install, same post
 * IDs, nothing created — only v2's acf/* blocks inside get swapped for a
 * native v3 equivalent where one genuinely exists. A post with no acf/*
 * blocks at all is never touched. A block with no real v3 equivalent is
 * left exactly as it is (inert once v2's block registration is gone
 * under v3, but harmless — still listed in the report for manual
 * rebuilding).
 *
 * Runs entirely against the live site — no export JSON involved, same
 * reasoning as `import`'s importPosts()/importMenus(): block markup is
 * plain post_content, unaffected by which theme happens to be active.
 *
 * Every mapping below was decided explicitly (not guessed) against the
 * actual v2 field definitions and v3 block.json attribute schemas:
 *
 *   - Simple swap, v2's config dropped in favor of v3's defaults (v2's
 *     fields are either pure display-style variants v3's block doesn't
 *     expose, or pure microcopy v3 hardcodes instead):
 *     casino-countries -> countries, casino-payments -> payment-methods,
 *     casino-games -> games, casino-game-providers -> game-providers,
 *     casino-finder -> casino-finder, casino-dropdown -> casino-finder
 *     (a `<select>`-and-jump navigator becomes a live search widget —
 *     different interaction, same purpose, explicitly accepted),
 *     casino-cta -> casino-card, casino-info-pros-cons ->
 *     casino-quick-facts (note: quick-facts has no pros/cons of its own
 *     at all — license/established/payout speed/deposit/withdrawal/
 *     support/KYC + taxonomies + a CTA — so this swap changes *what's
 *     shown*, not just how; explicitly accepted anyway).
 *   - faqs -> faq: questions[]/answer[] repeater maps 1:1 to items[].
 *   - recent-posts -> recent-posts: num_posts/num_words/cat_id map to
 *     numberOfItems/excerptLength/categoryId (both use core's native
 *     'category' taxonomy, so term IDs carry over unchanged); v2's
 *     generic style (1/2/3) has no clean match against v3's richer
 *     enum, left at v3's own default.
 *   - single-bonus -> bonus-card: casino post_object -> casinoId (0/
 *     empty is valid in both — "use the current post's casino"),
 *     terms_type -> termsDisplay (terms_none->none, terms_link->link,
 *     terms_popover->popover, terms_text->full). v2's hand-typed
 *     bonus_text/heading/button_text overrides have no v3 attribute —
 *     the converted card shows the casino's real configured bonus
 *     instead of that custom copy.
 *   - single-casino ("Featured Casino") -> featured-casino: casino ->
 *     casinoId. terms_features -> termsDisplay (terms_popover->popover,
 *     terms_block->full, terms_features->popover [closest, includes a
 *     popover], features->none [closest to "no terms, just features"]).
 *     v2's style (1-4 visual variants) and heading/sub_heading have no
 *     v3 attribute — content survives, chosen layout/heading text
 *     doesn't (v3 always shows its own ribbonLabel default instead).
 *   - casinos-grid/casinos-table -> casino-listing: source=tax maps its
 *     query (category/tags/orderby/order/ppp) onto casino-listing's own
 *     categoryId/tagId/orderBy/order/numberOfItems (orderby: name->
 *     title, rating_overall->rating, date/modified->date, default/rand
 *     ->v3's own default). source=manual (every real instance in this
 *     site's content, confirmed against live data) has no equivalent —
 *     casino-listing has no manual per-casino picker at all — so it
 *     becomes an unfiltered casino-listing with numberOfItems set to
 *     the original manual list's count, decided explicitly rather than
 *     switching block type. viewMode/termsDisplay come from v2's style
 *     select (grid style 4 "Compact" / table styles 2 & 5 "Compact" ->
 *     viewMode compact; the "with Terms"/"as a Link" style variants ->
 *     termsDisplay full/link). display_games/display_countries/
 *     display_payment_methods badges have no casino-listing attribute
 *     at all — dropped.
 *   - casino-bonuses -> a core/group of bonus-card blocks, one per
 *     bonus the target casino *actually has* in v3's own bonuses[] meta
 *     (read live, not from the block) — not one per row in v2's
 *     freeform bonuses[] repeater on the block itself, which is
 *     hand-typed comparison-table content (label/text/value_1/value_2/
 *     terms/link) entirely independent of the casino's real bonus data
 *     and has no v3 attribute to receive it. Casino context resolution
 *     mirrors v2's own render.php exactly: the containing post itself
 *     if it's a casino post, else the block's own 'casino' field.
 *   - page-links -> a core/columns of, per page: a linked image (the
 *     page's real featured image, since neither v3's link-tile nor any
 *     other v3 block has an image attribute at all) plus a linked title
 *     — built as plain literal HTML inside core/column/core/columns
 *     wrappers rather than decomposed into separate core/image/
 *     core/paragraph child blocks, which is an equally valid, lower-risk
 *     way to embed content inside a wrapper block.
 *   - show-more -> a core/group (v2's text_visible, always shown) with a
 *     core/details inside it (summary = v2's text_button_more, body =
 *     v2's text_invisible) — v2's wysiwyg fields are run through
 *     wp_kses_post()+wpautop() first. v2's text_button_less has no
 *     native equivalent — core/details uses one static summary label
 *     regardless of open/closed state — dropped.
 *   - game-finder, casino-ratings: left exactly as-is, still flagged in
 *     the report. game-finder has no slot-search equivalent in v3 at
 *     all. casino-ratings reads v2's live *named* rating breakdown;
 *     v3's stat-breakdown block *looks* like a match but isn't wired to
 *     any casino's data at all (a static, manually-authored block) —
 *     and v3's new rating_breakdown postmeta (positional slots, added
 *     this session) has no block anywhere that displays it yet. Not a
 *     migration-tool gap so much as a "build this v3 block first" gap.
 *
 * Caveat worth knowing before trusting the output blindly: the group/
 * details/columns/column/image markup this class hand-builds for
 * page-links and show-more is valid, functional HTML matching WordPress
 * core's own attribute-source selectors (verified against this
 * install's wp-includes/blocks/*\/block.json), but it is not guaranteed
 * byte-identical to what the JS block editor's own save() would
 * generate — worth opening one converted page in the editor to confirm
 * it doesn't show a block-recovery notice before trusting the rest.
 */
class Spinoko_Migrator_Convert_Blocks_Command
{
    /** @var array<int,array{id:int,title:string,type:string,converted:array<int,string>,left:array<int,string>}> */
    private array $touchedPosts = [];

    /** @var array<int,array{id:int,title:string,type:string,left:array<int,string>}> */
    private array $untouchedPosts = [];

    /**
     * Converts v2 acf/* blocks in existing posts/pages to native v3
     * blocks, in place, where a real equivalent exists.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be converted without writing anything.
     *
     * [--report=<file>]
     * : Path to also write the report to (Markdown), in addition to the terminal output.
     * ---
     * default: spinoko-block-conversion-report.md
     * ---
     *
     * ## EXAMPLES
     *
     *     wp spinoko-migrator convert-blocks --dry-run
     *     wp spinoko-migrator convert-blocks
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        if (! class_exists(CasinoCpt::class)) {
            WP_CLI::error('This must be run while the Spinoko v3 theme is active — Spinoko\Core\CasinoCpt not found.');
        }

        $dry_run = isset($assoc_args['dry-run']);
        $report_path = (string) ($assoc_args['report'] ?? 'spinoko-block-conversion-report.md');

        $ids = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        $tally = ['converted' => 0, 'partial' => 0, 'left_untouched' => 0];

        foreach ($ids as $id) {
            $post = get_post($id);
            if (! $post) {
                continue;
            }

            $original_acf_blocks = $this->detectAcfBlocks((string) $post->post_content);
            if ($original_acf_blocks === []) {
                continue;
            }

            $converted_names = [];
            $new_blocks = $this->convertBlockList(parse_blocks($post->post_content), $id, $post->post_type, $converted_names);
            $new_content = serialize_blocks($new_blocks);
            $remaining_acf_blocks = $this->detectAcfBlocks($new_content);

            if ($converted_names === []) {
                $this->untouchedPosts[] = ['id' => $id, 'title' => $post->post_title, 'type' => $post->post_type, 'left' => $remaining_acf_blocks];
                $tally['left_untouched']++;

                continue;
            }

            if (! $dry_run) {
                wp_update_post(['ID' => $id, 'post_content' => $new_content]);
            }

            $this->touchedPosts[] = [
                'id' => $id, 'title' => $post->post_title, 'type' => $post->post_type,
                'converted' => $converted_names, 'left' => $remaining_acf_blocks,
            ];
            $tally[$remaining_acf_blocks === [] ? 'converted' : 'partial']++;
        }

        $this->printReport($tally, $dry_run);
        $this->writeReportFile($report_path, $tally, $dry_run);
        WP_CLI::log("Report written to {$report_path}");
    }

    /**
     * v2's acf/* blocks are NOT always top-level — confirmed against
     * real content on this site (e.g. a "Casino Dropdown" page has its
     * acf/casino-dropdown block nested inside a core/columns layout) —
     * so this recurses into every block's innerBlocks, not just the
     * post's own root list.
     *
     * Every convertOneBlock() result in this class is at most one
     * replacement block — casino-bonuses/page-links's multi-item
     * results are always pre-wrapped in a single core/group/core/columns
     * block, never returned as separate siblings — so a straight 1-for-1
     * swap here never changes a parent's child count, which means it
     * never needs to touch the parent's own innerContent alignment, even
     * when nested arbitrarily deep. The one genuinely 0-block result
     * (convertPageLinks() with no valid pages) is filled with an empty
     * null-name block instead of actually removed, for the same reason —
     * same "nothing to put here" fallback SiteImporter::
     * buildNavigationBlock() already uses for a dangling menu target.
     *
     * @param array<int,string> $converted appended with each converted block's original name, by reference
     */
    private function convertBlockList(array $blocks, int $post_id, string $post_type, array &$converted): array
    {
        return array_map(function (array $block) use ($post_id, $post_type, &$converted): array {
            $name = (string) ($block['blockName'] ?? '');

            if (str_starts_with($name, 'acf/')) {
                $replacement = $this->convertOneBlock($block, substr($name, 4), $post_id, $post_type);

                if ($replacement === null) {
                    return $block;
                }

                $converted[] = $name;

                return $replacement[0] ?? ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
            }

            if (! empty($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->convertBlockList($block['innerBlocks'], $post_id, $post_type, $converted);
            }

            return $block;
        }, $blocks);
    }

    /**
     * @return array<int,array>|null null = no v3 equivalent, leave as-is.
     */
    private function convertOneBlock(array $block, string $slug, int $post_id, string $post_type): ?array
    {
        return match ($slug) {
            'casino-countries' => [$this->buildBlock('spinoko/countries')],
            'casino-payments' => [$this->buildBlock('spinoko/payment-methods')],
            'casino-games' => [$this->buildBlock('spinoko/games')],
            'casino-game-providers' => [$this->buildBlock('spinoko/game-providers')],
            'casino-finder', 'casino-dropdown' => [$this->buildBlock('spinoko/casino-finder')],
            'casino-cta' => [$this->buildBlock('spinoko/casino-card')],
            'casino-info-pros-cons' => [$this->buildBlock('spinoko/casino-quick-facts')],
            'faqs' => [$this->convertFaqs($block)],
            'recent-posts' => [$this->convertRecentPosts($block)],
            'single-bonus' => [$this->convertSingleBonus($block)],
            'single-casino' => [$this->convertSingleCasino($block)],
            'casinos-grid' => [$this->convertCasinoListing($block, 'grid')],
            'casinos-table' => [$this->convertCasinoListing($block, 'table')],
            'casino-bonuses' => $this->convertCasinoBonuses($block, $post_id, $post_type),
            'page-links' => $this->convertPageLinks($block),
            'show-more' => [$this->convertShowMore($block)],
            default => null, // game-finder, casino-ratings, and anything unrecognized
        };
    }

    // ---------------------------------------------------------------
    // Block builders
    // ---------------------------------------------------------------

    /**
     * Standard shape for a dynamic (has a render.php) block instance —
     * attrs only, no innerHTML/innerContent needed since the render
     * callback rebuilds the markup from attrs on every request. Same
     * convention SiteImporter::buildNavigationBlock() already uses for
     * core/navigation-link.
     */
    private function buildBlock(string $name, array $attrs = []): array
    {
        return [
            'blockName' => $name,
            'attrs' => $attrs,
            'innerBlocks' => [],
            'innerHTML' => '',
            'innerContent' => [],
        ];
    }

    private function acfData(array $block, string $key, mixed $default = null): mixed
    {
        return $block['attrs']['data'][$key] ?? $default;
    }

    private function convertFaqs(array $block): array
    {
        $data = $block['attrs']['data'] ?? [];
        $count = (int) ($data['faqs'] ?? 0);

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $question = trim((string) ($data["faqs_{$i}_question"] ?? ''));
            $answer = trim((string) ($data["faqs_{$i}_answer"] ?? ''));

            if ($question === '' && $answer === '') {
                continue;
            }

            $items[] = ['question' => $question, 'answer' => $answer];
        }

        return $this->buildBlock('spinoko/faq', $items !== [] ? ['items' => $items] : []);
    }

    private function convertRecentPosts(array $block): array
    {
        $attrs = [];

        $num_posts = (int) $this->acfData($block, 'num_posts', 0);
        if ($num_posts > 0) {
            $attrs['numberOfItems'] = $num_posts;
        }

        $num_words = (int) $this->acfData($block, 'num_words', 0);
        if ($num_words > 0) {
            $attrs['excerptLength'] = $num_words;
        }

        // core's own native 'category' taxonomy — same taxonomy, same
        // term IDs, on both v2 and v3, no remapping needed.
        $cat_id = (int) $this->acfData($block, 'cat_id', 0);
        if ($cat_id) {
            $attrs['categoryId'] = $cat_id;
        }

        return $this->buildBlock('spinoko/recent-posts', $attrs);
    }

    private function convertSingleBonus(array $block): array
    {
        $attrs = [];

        $casino_id = (int) $this->acfData($block, 'casino', 0);
        if ($casino_id) {
            $attrs['casinoId'] = $casino_id;
        }

        $terms_map = ['terms_none' => 'none', 'terms_link' => 'link', 'terms_popover' => 'popover', 'terms_text' => 'full'];
        $terms_type = (string) $this->acfData($block, 'terms_type', '');
        if (isset($terms_map[$terms_type])) {
            $attrs['termsDisplay'] = $terms_map[$terms_type];
        }

        return $this->buildBlock('spinoko/bonus-card', $attrs);
    }

    private function convertSingleCasino(array $block): array
    {
        $attrs = [];

        $casino_id = (int) $this->acfData($block, 'casino', 0);
        if ($casino_id) {
            $attrs['casinoId'] = $casino_id;
        }

        $terms_map = ['terms_popover' => 'popover', 'terms_block' => 'full', 'terms_features' => 'popover', 'features' => 'none'];
        $terms_features = (string) $this->acfData($block, 'terms_features', '');
        if (isset($terms_map[$terms_features])) {
            $attrs['termsDisplay'] = $terms_map[$terms_features];
        }

        return $this->buildBlock('spinoko/featured-casino', $attrs);
    }

    private function convertCasinoListing(array $block, string $kind): array
    {
        $data = $block['attrs']['data'] ?? [];
        $source = (string) ($data['source'] ?? 'manual');
        $style = (string) ($data['style'] ?? '1');

        $attrs = [];

        if ($source === 'tax') {
            $category = $data['category'] ?? 0;
            $category_id = is_array($category) ? (int) reset($category) : (int) $category;
            if ($category_id) {
                $attrs['categoryId'] = $category_id;
            }

            $tags = $data['tags'] ?? 0;
            $tag_id = is_array($tags) ? (int) reset($tags) : (int) $tags;
            if ($tag_id) {
                $attrs['tagId'] = $tag_id;
            }

            $orderby_map = ['name' => 'title', 'rating_overall' => 'rating', 'date' => 'date', 'modified' => 'date'];
            $orderby = $orderby_map[(string) ($data['orderby'] ?? '')] ?? null;
            if ($orderby !== null) {
                $attrs['orderBy'] = $orderby;
            }

            $order = (string) ($data['order'] ?? '');
            if (in_array($order, ['asc', 'desc'], true)) {
                $attrs['order'] = $order;
            }

            $ppp = (int) ($data['ppp'] ?? -1);
            if ($ppp > 0) {
                $attrs['numberOfItems'] = $ppp;
            }
        } else {
            // Manual casino picks have no casino-listing equivalent at
            // all (no per-item picker attribute exists) — explicitly
            // decided: no filters, just the same item count as the
            // original manually-curated list.
            $count = (int) ($data['casinos'] ?? 0);
            if ($count > 0) {
                $attrs['numberOfItems'] = $count;
            }
        }

        if ($kind === 'grid') {
            $view_map = ['4' => 'compact']; // casinos-grid style 4 = "Compact"
            $terms_map = ['2' => 'full', '3' => 'link'];
        } else {
            $view_map = ['2' => 'compact', '5' => 'compact']; // casinos-table "Compact" styles
            $terms_map = ['3' => 'full', '4' => 'link', '5' => 'link'];
        }

        if (isset($view_map[$style])) {
            $attrs['viewMode'] = $view_map[$style];
        } elseif ($kind === 'table') {
            $attrs['viewMode'] = 'table';
        }

        if (isset($terms_map[$style])) {
            $attrs['termsDisplay'] = $terms_map[$style];
        }

        return $this->buildBlock('spinoko/casino-listing', $attrs);
    }

    /**
     * Mirrors v2's own casino-bonuses render.php exactly: the containing
     * casino post itself if that's what this is on, else the block's
     * own 'casino' field — never the block's freeform bonuses[]
     * repeater, which has no v3 attribute to carry it (see class
     * docblock). One bonus-card per bonus the casino *actually has* in
     * v3, wrapped in a group when there's more than one.
     */
    private function convertCasinoBonuses(array $block, int $post_id, string $post_type): array
    {
        $casino_id = $post_type === CasinoCpt::POST_TYPE ? $post_id : (int) $this->acfData($block, 'casino', 0);

        $cards = [];
        if ($casino_id) {
            $bonuses = get_post_meta($casino_id, 'bonuses', true);
            $bonuses = is_array($bonuses) ? array_filter($bonuses, 'is_array') : [];

            foreach ($bonuses as $bonus) {
                $attrs = ['casinoId' => $casino_id];
                $type = (string) ($bonus['type'] ?? '');
                if ($type !== '') {
                    $attrs['bonusType'] = $type;
                }
                $cards[] = $this->buildBlock('spinoko/bonus-card', $attrs);
            }
        }

        if ($cards === []) {
            // No resolvable casino, or it has no bonuses[] yet — fall
            // back to one context-driven card, same as bonus-card's own
            // casinoId=0 default behavior.
            $cards[] = $this->buildBlock('spinoko/bonus-card', $casino_id ? ['casinoId' => $casino_id] : []);
        }

        if (count($cards) === 1) {
            return $cards;
        }

        $open = '<div class="wp-block-group">';
        $close = '</div>';

        return [[
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerBlocks' => $cards,
            'innerHTML' => $open.$close,
            'innerContent' => array_merge([$open], array_fill(0, count($cards), null), [$close]),
        ]];
    }

    /**
     * Per page: a linked image (the page's real featured image — no v3
     * block has an image attribute at all, so this is built as plain
     * HTML rather than dropping the image like link-tile would) plus a
     * linked title, inside a core/column; every column inside one
     * core/columns. Built as literal HTML content within the wrapper
     * blocks rather than further-decomposed core/image/core/paragraph
     * child blocks — equally valid, less markup to get exactly right.
     */
    private function convertPageLinks(array $block): array
    {
        $data = $block['attrs']['data'] ?? [];
        $count = (int) ($data['pages'] ?? 0);

        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $page_id = (int) ($data["pages_{$i}_page"] ?? 0);
            if (! $page_id) {
                continue;
            }

            $permalink = (string) get_permalink($page_id);
            $title = (string) get_the_title($page_id);
            $image_url = (string) get_the_post_thumbnail_url($page_id, 'large');

            $image_html = $image_url !== ''
                ? '<figure class="wp-block-image"><a href="'.esc_url($permalink).'"><img src="'.esc_url($image_url).'" alt=""/></a></figure>'
                : '';
            $title_html = '<p><a href="'.esc_url($permalink).'">'.esc_html($title).'</a></p>';

            $col_open = '<div class="wp-block-column">'.$image_html.$title_html;
            $col_close = '</div>';

            $columns[] = [
                'blockName' => 'core/column',
                'attrs' => [],
                'innerBlocks' => [],
                'innerHTML' => $col_open.$col_close,
                'innerContent' => [$col_open.$col_close],
            ];
        }

        if ($columns === []) {
            return [];
        }

        $open = '<div class="wp-block-columns">';
        $close = '</div>';

        return [[
            'blockName' => 'core/columns',
            'attrs' => [],
            'innerBlocks' => $columns,
            'innerHTML' => $open.$close,
            'innerContent' => array_merge([$open], array_fill(0, count($columns), null), [$close]),
        ]];
    }

    /**
     * A core/group holding v2's always-visible text (text_visible), plus
     * a core/details inside it (summary = v2's "show more" button text,
     * body = v2's text_invisible). v2's wysiwyg fields store raw
     * paragraph-break text, not baked HTML — run through wp_kses_post()
     * (strip anything unsafe) then wpautop() (real <p> tags) before
     * embedding. v2's text_button_less has no native equivalent
     * (core/details has one static summary label, not a toggling pair)
     * and is dropped.
     */
    private function convertShowMore(array $block): array
    {
        $text_visible = trim((string) $this->acfData($block, 'text_visible', ''));
        $text_invisible = trim((string) $this->acfData($block, 'text_invisible', ''));
        $button_more = trim((string) $this->acfData($block, 'text_button_more', ''));
        $summary = $button_more !== '' ? $button_more : __('Read more', 'default');

        $visible_html = $text_visible !== '' ? wpautop(wp_kses_post($text_visible)) : '';
        $invisible_html = $text_invisible !== '' ? wpautop(wp_kses_post($text_invisible)) : '';

        $details_open = '<details class="wp-block-details"><summary>'.esc_html($summary).'</summary>';
        $details_close = '</details>';
        $details_block = [
            'blockName' => 'core/details',
            'attrs' => ['summary' => $summary],
            'innerBlocks' => [],
            'innerHTML' => $details_open.$invisible_html.$details_close,
            'innerContent' => [$details_open.$invisible_html.$details_close],
        ];

        if ($visible_html === '') {
            return $details_block;
        }

        $group_open = '<div class="wp-block-group">'.$visible_html;
        $group_close = '</div>';

        return [
            'blockName' => 'core/group',
            'attrs' => ['layout' => ['type' => 'constrained']],
            'innerBlocks' => [$details_block],
            'innerHTML' => $group_open.$group_close,
            'innerContent' => [$group_open, null, $group_close],
        ];
    }

    /**
     * Same regex as the import command's flagging logic — used both to
     * decide whether a post needs any attention at all, and (run again
     * on the post-conversion content) to find out which block names
     * genuinely remain, regardless of nesting depth.
     */
    private function detectAcfBlocks(string $content): array
    {
        preg_match_all('#<!--\s*wp:(acf/[a-z0-9\-]+)#i', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    // ---------------------------------------------------------------
    // Report
    // ---------------------------------------------------------------

    private function printReport(array $tally, bool $dry_run): void
    {
        $prefix = $dry_run ? '[DRY RUN] ' : '';

        WP_CLI::log('');
        WP_CLI::log(sprintf(
            '%s%d post(s)/page(s) fully converted, %d partially converted, %d left untouched (no convertible blocks found).',
            $prefix,
            $tally['converted'],
            $tally['partial'],
            $tally['left_untouched']
        ));

        if ($this->touchedPosts !== []) {
            WP_CLI::log('');
            WP_CLI::log('Converted:');
            foreach ($this->touchedPosts as $touched) {
                $status = $touched['left'] === [] ? 'fully converted' : 'partial — still has: '.implode(', ', $touched['left']);
                WP_CLI::log(sprintf('  - [%s] %s — converted %s (%s)', $touched['type'], $touched['title'], implode(', ', $touched['converted']), $status));
            }
        }

        if ($this->untouchedPosts !== []) {
            WP_CLI::log('');
            WP_CLI::warning(count($this->untouchedPosts).' post(s)/page(s) have acf blocks with no v3 equivalent at all — still need manual rebuilding:');
            foreach ($this->untouchedPosts as $untouched) {
                WP_CLI::log(sprintf('  - [%s] %s  (%s)  %s', $untouched['type'], $untouched['title'], implode(', ', $untouched['left']), admin_url("post.php?post={$untouched['id']}&action=edit")));
            }
        }

        WP_CLI::log('');
        WP_CLI::log('Worth doing before trusting this blindly: open one converted page in the block editor to confirm it doesn\'t show a "block contains unexpected or invalid content" recovery notice — see this class\'s docblock caveat.');

        WP_CLI::log('');
        WP_CLI::success($dry_run ? 'Dry run complete — nothing was written.' : 'Conversion complete.');
    }

    private function writeReportFile(string $path, array $tally, bool $dry_run): void
    {
        $lines = [];
        $lines[] = '# Spinoko v2 Block Conversion Report';
        $lines[] = '';
        $lines[] = ($dry_run ? '**DRY RUN** — nothing was written. ' : '**Real run.** ').'Generated '.current_time('mysql').'.';
        $lines[] = '';
        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = "- Fully converted: {$tally['converted']}";
        $lines[] = "- Partially converted: {$tally['partial']}";
        $lines[] = "- Left untouched (nothing convertible found): {$tally['left_untouched']}";
        $lines[] = '';

        if ($this->touchedPosts !== []) {
            $lines[] = '## Converted';
            $lines[] = '';
            $lines[] = '| Type | Title | Converted | Still needs manual rebuild |';
            $lines[] = '|---|---|---|---|';
            foreach ($this->touchedPosts as $touched) {
                $lines[] = sprintf(
                    '| %s | %s | %s | %s |',
                    $touched['type'],
                    str_replace('|', '\\|', $touched['title']),
                    implode(', ', $touched['converted']),
                    $touched['left'] === [] ? '—' : implode(', ', $touched['left'])
                );
            }
            $lines[] = '';
        }

        if ($this->untouchedPosts !== []) {
            $lines[] = '## No v3 equivalent — still need manual rebuilding';
            $lines[] = '';
            $lines[] = '| Type | Title | Blocks | Edit |';
            $lines[] = '|---|---|---|---|';
            foreach ($this->untouchedPosts as $untouched) {
                $lines[] = sprintf(
                    '| %s | %s | `%s` | [Edit](%s) |',
                    $untouched['type'],
                    str_replace('|', '\\|', $untouched['title']),
                    implode('`, `', $untouched['left']),
                    admin_url("post.php?post={$untouched['id']}&action=edit")
                );
            }
            $lines[] = '';
        }

        $lines[] = '## Before trusting this';
        $lines[] = '';
        $lines[] = 'Open one converted page in the block editor to confirm it doesn\'t show a "block contains unexpected or invalid content" recovery notice — the group/details/columns/image markup this command hand-builds is valid and functional but not guaranteed byte-identical to what the block editor\'s own save() would generate.';
        $lines[] = '';

        file_put_contents($path, implode("\n", $lines));
    }
}
