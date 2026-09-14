<?php
/**
 * Plugin Name: Spinoko v2 -> v3 Migrator
 * Description: One-off migration tool for an in-place v2->v3 upgrade of the same WordPress install.
 * Version: 1.0.0
 * Author: DinoMatic
 * License: GPL-2.0-or-later
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
