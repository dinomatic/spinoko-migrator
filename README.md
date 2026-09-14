# Spinoko v2 → v3 Migrator

One-off WP-CLI tool for moving a Spinoko v2 website to v3, in place — 
same WordPress install, same database, just a theme switch in between.

## Requirements

- SSH access to the server
- WP-CLI
- Both v2 and v3 version of [Spinoko Theme](https://dinomatic.com/themes/spinoko).

## Install

Upload and activate like any other plugin.

## Commands, run in this order

1. `wp spinoko-migrator export [--output=<file>]`
   Run while v2 is still active. Saves casino and slot data to a JSON file.

2. `wp spinoko-migrator import <file> [--dry-run] [--v2-theme=<slug>] [--report=<file>]`
   Run after switching to v3. Creates the casinos and slots, copies your
   nav menus, and flags any posts, pages, casinos, or slots still using
   v2 blocks.

3. `wp spinoko-migrator cleanup-legacy [--dry-run] [--force]`
   Run once you've checked the import. Removes the old v2 casino and
   game posts.

4. `wp spinoko-migrator convert-blocks [--dry-run] [--report=<file>]`
   Optional. Swaps v2 blocks for v3 blocks in existing posts, pages,
   casinos, and slots.

Each command supports `--dry-run` to preview without writing anything.

## More info

See the "Upgrading from v2" page in DinoMatic's documentation for the
full walkthrough, what carries over, and troubleshooting.
