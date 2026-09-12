<?php

if (! defined('ABSPATH')) {
    exit;
}

/**
 * `wp spinoko-migrator export` — step 1, run while the Spinoko v2 theme
 * is active. Dumps casinos and games to one JSON file, using v2's own
 * get_field() and inc/_lists.php lookups so ACF's actual stored values
 * are decoded correctly instead of reverse-engineered from raw postmeta.
 *
 * Casinos/games are the *only* things this exports, deliberately: they're
 * the only data that actually requires v2's runtime to read correctly
 * (get_field() stops working the moment the theme switches). Posts,
 * pages, menus and site options are all plain core WordPress data,
 * completely unaffected by which theme is active — the import step
 * reads those directly off the live site instead of through this JSON,
 * since (being a same-install, theme-switch-in-place migration, not a
 * cross-site import) they're already sitting right there in the same
 * database either way. See the import command's docblock.
 *
 * Deliberately v3-agnostic: field names here stay close to v2's own ACF
 * field names (name, bonus_main, deposit_min, ...), not v3's. All the
 * v2->v3 renaming/reshaping (bonus wrapping, restricted-countries
 * complement, ...) happens in the import command instead, against this
 * file as a static, reviewable intermediate artifact — so a human can
 * inspect or hand-edit it between the two steps if something looks off.
 */
class Spinoko_Migrator_Export_Command
{
    /**
     * Exports v2 casinos and games to a JSON file.
     *
     * ## OPTIONS
     *
     * [--output=<file>]
     * : Path to write the JSON export to.
     * ---
     * default: spinoko-v2-export.json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp spinoko-migrator export --output=v2-export.json
     *
     * @when after_wp_load
     */
    public function __invoke($args, $assoc_args): void
    {
        if (! class_exists('SpinokoCasino') || ! function_exists('get_field')) {
            WP_CLI::error('This must be run while the Spinoko v2 theme is active — SpinokoCasino/ACF (get_field) not found.');
        }

        $output = (string) ($assoc_args['output'] ?? 'spinoko-v2-export.json');

        $data = [
            'casinos' => $this->exportCasinos(),
            'games' => $this->exportGames(),
        ];

        $json = (string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === file_put_contents($output, $json)) {
            WP_CLI::error("Could not write to {$output}");
        }

        WP_CLI::success(sprintf(
            'Exported %d casino(s), %d game(s) to %s',
            count($data['casinos']),
            count($data['games']),
            $output
        ));
    }

    // ---------------------------------------------------------------
    // Casinos
    // ---------------------------------------------------------------

    private function exportCasinos(): array
    {
        $ids = get_posts([
            'post_type' => 'casino',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        return array_values(array_map([$this, 'exportCasino'], $ids));
    }

    private function exportCasino(int $post_id): array
    {
        // Raw post_title/post_name, not get_the_title() — that runs the
        // 'the_title' filter chain (wptexturize etc.), which can alter
        // the exact text for round-trip fidelity. Same reasoning as
        // exportPost() already used for posts/pages.
        $post = get_post($post_id);

        return [
            'id' => $post_id,
            'title' => $post?->post_title ?? '',
            // v3 registers the same 'casino' CPT slug as v2, so on an
            // in-place migration (same install, theme switched) v2's
            // original casino posts are still sitting in the same table
            // under that post_type when the import step runs. Carrying
            // the exact slug through lets that step recreate the casino
            // at its original URL — see the import command's
            // suffixLegacyCasinoSlugs() for how the collision is
            // resolved so the original slug is actually free to reuse.
            'slug' => $post?->post_name ?? '',
            'status' => get_post_status($post_id),
            'name' => (string) get_field('name', $post_id),
            'affiliate_link' => (string) get_field('affiliate_link', $post_id),
            'logo_url' => $this->imageUrl(get_field('logo', $post_id)),
            'logo_square_url' => $this->imageUrl(get_field('logo_square', $post_id)),
            'website' => $this->groupValue(get_field('website', $post_id)),
            'established' => $this->groupValue(get_field('established', $post_id)),
            'license' => $this->groupValue(get_field('license', $post_id)),
            'deposit_min' => $this->groupValue(get_field('deposit_min', $post_id)),
            'deposit_max' => $this->groupValue(get_field('deposit_max', $post_id)),
            'bonus_main' => (string) get_field('bonus_main', $post_id),
            'bonus_short' => (string) get_field('bonus_short', $post_id),
            'bonus_amount' => get_field('bonus_amount', $post_id),
            'bonus_percentage' => get_field('bonus_percentage', $post_id),
            'terms' => (string) get_field('terms', $post_id),
            'terms_url' => (string) get_field('terms_url', $post_id),
            'pros' => $this->repeaterColumn(get_field('pros', $post_id), 'pro'),
            'cons' => $this->repeaterColumn(get_field('cons', $post_id), 'con'),
            'rating_overall' => get_field('rating_overall', $post_id),
            'rating_overall_text' => (string) get_field('rating_overall_text', $post_id),
            // No CPT-meta home in v3 (see this plugin's docs) — carried
            // through anyway so the import step can at least list which
            // casinos have a rating breakdown to re-author manually.
            'ratings' => array_map(
                static fn (array $row): array => [
                    'name' => (string) ($row['rating_name'] ?? ''),
                    'value' => (string) ($row['rating_value'] ?? ''),
                ],
                array_filter((array) get_field('ratings', $post_id), 'is_array')
            ),
            'payment_methods' => $this->resolveToLabels(
                get_field('payment_methods', $post_id),
                _spinoko_payment_methods()
            ),
            'payment_methods_additional' => $this->repeaterColumn(get_field('payment_methods_additional', $post_id), 'payment_method'),
            // Kept as raw ISO codes (not labels) — the import step needs
            // codes to compute the restricted-countries complement.
            'countries' => $this->resolveToCodes(get_field('countries', $post_id), _spinoko_countries()),
            // v2 actually carries both an allow-list (countries, above)
            // and a block-list (this one) on the same CPT — editors use
            // whichever one; see spinoko_get_casino_countries() in
            // inc/functions/casino/countries.php for v2's own precedence
            // (restricted wins over supported when both are somehow set).
            // The import step mirrors that same precedence.
            'restricted_countries' => $this->resolveToCodes(get_field('restricted_countries', $post_id), _spinoko_countries()),
            'games' => $this->resolveToLabels(
                get_field('games', $post_id),
                _spinoko_casino_games()
            ),
            'game_providers' => $this->resolveToLabels(
                get_field('game_providers', $post_id),
                _spinoko_game_providers()
            ),
            'game_providers_additional' => $this->repeaterColumn(get_field('game_providers_additional', $post_id), 'provider'),
            'categories' => $this->termNames($post_id, 'casino_category'),
            'tags' => $this->termNames($post_id, 'casino_tag'),
        ];
    }

    // ---------------------------------------------------------------
    // Games (-> v3 Slot)
    // ---------------------------------------------------------------

    private function exportGames(): array
    {
        $ids = get_posts([
            'post_type' => 'game',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        return array_values(array_map([$this, 'exportGame'], $ids));
    }

    private function exportGame(int $post_id): array
    {
        $casino_id = (int) get_field('casino', $post_id);
        $top_3 = (array) get_field('top_3_casinos', $post_id);

        $top_casino_titles = [];
        foreach (['casino_1', 'casino_2', 'casino_3'] as $key) {
            $id = (int) ($top_3[$key] ?? 0);
            if ($id) {
                $top_casino_titles[] = get_the_title($id);
            }
        }

        $game_post = get_post($post_id);

        return [
            'id' => $post_id,
            'title' => $game_post?->post_title ?? '', // raw, see exportCasino()'s note
            // v2's 'game' CPT and v3's 'slot' CPT don't share a slug, so
            // there's no collision to work around here (unlike casinos)
            // — but the slug is still carried through so the import step
            // can put the migrated slot at its original v2 URL.
            'slug' => $game_post?->post_name ?? '',
            'status' => get_post_status($post_id),
            'name' => (string) get_field('name', $post_id),
            'image_url' => $this->imageUrl(get_field('image', $post_id)),
            'rating' => get_field('rating', $post_id),
            'linked_casino_title' => $casino_id ? get_the_title($casino_id) : '',
            'top_casino_titles' => $top_casino_titles,
            'reels' => get_field('reels', $post_id),
            'rows' => get_field('rows', $post_id),
            'paylines' => get_field('paylines', $post_id),
            'rtp' => get_field('rtp', $post_id),
            'wilds' => (bool) get_field('wilds', $post_id),
            'free_spins' => (bool) get_field('free_spins', $post_id),
            'progressive' => (bool) get_field('progressive', $post_id),
            'mobile' => (bool) get_field('mobile', $post_id),
            'volatility' => (string) get_field('volatility', $post_id),
            'game_providers' => $this->resolveToLabels(
                get_field('game_providers', $post_id),
                _spinoko_game_providers()
            ),
            'pros' => $this->repeaterColumn(get_field('pros', $post_id), 'pro'),
            'cons' => $this->repeaterColumn(get_field('cons', $post_id), 'con'),
            'categories' => $this->termNames($post_id, 'game_category'),
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function imageUrl(mixed $image): string
    {
        if (is_array($image)) {
            return (string) ($image['url'] ?? '');
        }

        if (is_numeric($image)) {
            return (string) wp_get_attachment_url((int) $image);
        }

        return (string) $image;
    }

    /**
     * v2's website/established/license/deposit_min/deposit_max fields
     * are ACF groups of {label, value} sub-fields — `label` is an
     * editor-chosen display caption (e.g. "Est."), `value` is the actual
     * data ("2015"). Only `value` is portable/meaningful on its own.
     */
    private function groupValue(mixed $group): string
    {
        return is_array($group) ? (string) ($group['value'] ?? '') : '';
    }

    /**
     * Repeater rows come back as a list of associative arrays keyed by
     * sub-field name — this flattens one column into a plain string
     * list, dropping empty rows.
     */
    private function repeaterColumn(mixed $rows, string $sub_field): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $values = array_map(
            static fn ($row): string => is_array($row) ? trim((string) ($row[$sub_field] ?? '')) : '',
            $rows
        );

        return array_values(array_filter($values, static fn (string $v): bool => $v !== ''));
    }

    private function termNames(int $post_id, string $taxonomy): array
    {
        if (! taxonomy_exists($taxonomy)) {
            return [];
        }

        $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'names']);

        return is_wp_error($terms) ? [] : $terms;
    }

    /**
     * v2's payment_methods/games/game_providers/countries/
     * restricted_countries select fields are all confirmed (via
     * `wp eval` against a live ACF install, not just the field-group
     * source) to use `return_format => 'array'`: get_field() hands back
     * a list of `['value' => slug, 'label' => display]` pairs, one per
     * selected choice — not raw slugs or plain label strings. Reading
     * either sub-key straight off each item is the reliable path; the
     * slug_to_label lookup below is only a fallback for a bare
     * string item, in case a field's return_format ever changes.
     */
    private function resolveToLabels(mixed $raw, array $slug_to_label): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $lower_label_to_label = array_combine(
            array_map('strtolower', $slug_to_label),
            $slug_to_label
        );

        $labels = array_map(
            static function (mixed $item) use ($slug_to_label, $lower_label_to_label): string {
                if (is_array($item)) {
                    $label = trim((string) ($item['label'] ?? ''));
                    if ($label !== '') {
                        return $label;
                    }

                    $value = trim((string) ($item['value'] ?? ''));

                    return $slug_to_label[$value] ?? $value;
                }

                $value = trim((string) $item);
                if ($value === '') {
                    return '';
                }

                if (isset($slug_to_label[$value])) {
                    return $slug_to_label[$value];
                }

                return $lower_label_to_label[strtolower($value)] ?? $value;
            },
            $raw
        );

        return array_values(array_filter($labels, static fn (string $v): bool => $v !== ''));
    }

    /**
     * Same item shape as resolveToLabels() (see its docblock), but
     * returns the lowercase ISO alpha-2 *code* (the item's `value`)
     * instead of the label — countries need the code for the import
     * step's restricted-countries logic, not the display name.
     */
    private function resolveToCodes(mixed $raw, array $code_to_label): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $lower_label_to_code = array_combine(
            array_map('strtolower', $code_to_label),
            array_keys($code_to_label)
        );

        $codes = array_map(
            static function (mixed $item) use ($code_to_label, $lower_label_to_code): string {
                if (is_array($item)) {
                    $value = strtolower(trim((string) ($item['value'] ?? '')));
                    if ($value !== '' && isset($code_to_label[$value])) {
                        return $value;
                    }

                    $label = trim((string) ($item['label'] ?? ''));

                    return $lower_label_to_code[strtolower($label)] ?? $value;
                }

                $value = trim((string) $item);
                if ($value === '') {
                    return '';
                }

                if (isset($code_to_label[strtolower($value)])) {
                    return strtolower($value);
                }

                return $lower_label_to_code[strtolower($value)] ?? '';
            },
            $raw
        );

        return array_values(array_filter($codes, static fn (string $v): bool => $v !== ''));
    }
}
