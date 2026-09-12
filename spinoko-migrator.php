<?php
/**
 * Plugin Name: Spinoko v2 -> v3 Migrator
 * Description: One-off migration tool for an in-place v2->v3 upgrade of the same WordPress install. Step 1 ("export") runs while the Spinoko v2 theme is active and dumps casinos and games to a JSON file using v2's own ACF field reads — the only data that actually needs v2's runtime to read correctly. Step 2 ("import") runs after switching to the Spinoko v3 theme: loads that JSON into v3's native casino/slot data model via its existing SiteImporter/CasinoImporter/SlotImporter classes, and separately handles posts/pages/menus by querying the live site directly (they're plain core WordPress data, unaffected by which theme is active, so there's no reason to route them through the export JSON). Step 3 ("cleanup-legacy") removes the leftover v2-era casino/game posts once you've confirmed the import looks right. A separate, optional command ("convert-blocks") rewrites existing posts'/pages' content in place, swapping v2's acf/* blocks for native v3 blocks wherever a real equivalent exists. Install once, keep active across the theme switch, delete when done.
 * Version: 1.0.0
 * Author: DinoMatic
 * License: GPL-2.0-or-later
 *
 * Deliberately WP-CLI only, not an admin UI: this is a one-time
 * migration event per site, not a product feature, so it stays
 * completely inert (no hooks, no admin pages, no REST routes) on any
 * request that isn't `wp spinoko-migrator ...`.
 *
 * Why a plugin and not code inside either theme: v2 and v3 can never
 * be the active theme at the same time, but a plugin stays active
 * across a theme switch — so the same install can run `export` while
 * v2 is active (using v2's own get_field() to decode its ACF data
 * reliably) and `import`/`cleanup-legacy` after switching to v3 (using
 * v3's own SiteImporter/CasinoImporter/SlotImporter classes directly,
 * via the global Spinoko\* autoloader v3's functions.php registers).
 */

if (! defined('ABSPATH')) {
    exit;
}

define('SPINOKO_MIGRATOR_DIR', __DIR__);
define('SPINOKO_MIGRATOR_VERSION', '1.0.0');

if (! defined('WP_CLI') || ! WP_CLI) {
    return;
}

require_once SPINOKO_MIGRATOR_DIR.'/includes/class-spinoko-migrator-export-command.php';
require_once SPINOKO_MIGRATOR_DIR.'/includes/class-spinoko-migrator-import-command.php';
require_once SPINOKO_MIGRATOR_DIR.'/includes/class-spinoko-migrator-cleanup-command.php';
require_once SPINOKO_MIGRATOR_DIR.'/includes/class-spinoko-migrator-convert-blocks-command.php';

WP_CLI::add_command('spinoko-migrator export', 'Spinoko_Migrator_Export_Command');
WP_CLI::add_command('spinoko-migrator import', 'Spinoko_Migrator_Import_Command');
WP_CLI::add_command('spinoko-migrator cleanup-legacy', 'Spinoko_Migrator_Cleanup_Command');
WP_CLI::add_command('spinoko-migrator convert-blocks', 'Spinoko_Migrator_Convert_Blocks_Command');
