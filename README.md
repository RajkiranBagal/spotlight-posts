# VIP Featured Posts

A small WordPress plugin that lets editors flag posts as **featured** — from the editor,
the posts list, bulk actions or Quick Edit — and surfaces them two ways: a dynamic
Gutenberg block and a public REST endpoint.

> **This is a self-directed proof of concept.** It is not a client deliverable and
> is not affiliated with WordPress VIP. I built it to demonstrate the habits a VIP
> engagement actually depends on — object caching, bounded `WP_Query`, disciplined
> escaping and sanitization, nonce plus capability checks, and a clean
> `WordPress-VIP-Go` PHPCS run.

- **Requires:** WordPress 6.4+, PHP 8.1+
- **Text domain:** `vip-featured-posts`
- **Meta key:** `_vip_featured`

📘 **[docs/VIP-GUIDE.md](docs/VIP-GUIDE.md)** — a full walkthrough of how the VIP platform
works end to end: repo anatomy, the request lifecycle, the local environment, this plugin
file by file, and how to test it all on localhost.

---

## What it does

Editors can flag a post four ways, because different jobs want different ones:

| Where | For |
| --- | --- |
| **Featured** checkbox in the post sidebar | Editing a single post |
| Star toggle in the posts list table | Changing one post without opening it |
| **Mark as featured** bulk action | Curating many at once |
| Quick Edit checkbox | Alongside the other inline fields |

A **Featured / Not featured** filter sits above the list table, and the column appears in
Screen Options so anyone who does not curate can hide it.

Flagged posts appear in:

- the **Featured Posts** block (dynamic, server-rendered, configurable heading and count), and
- `GET /wp-json/vip-featured/v1/posts?count=5`

Both read through the same cached query, so they cannot disagree with each other.

---

## Setup

```bash
composer install     # PHPCS + WordPress VIP coding standards
npm install          # @wordpress/scripts build toolchain
npm run build        # compiles src/featured-list -> build/featured-list
```

`npm run build` is **required before activating the plugin**. Block registration
deliberately no-ops when `build/block.json` is missing, so a fresh clone that has
not been built yet degrades to "block absent" rather than a fatal error.

### Commands

| Command | Purpose |
| --- | --- |
| `composer lint` | PHPCS against `WordPress-VIP-Go` |
| `composer lint:fix` | PHPCBF auto-fix pass |
| `composer test` | PHPUnit integration suite |
| `npm run build` | One-off production build |
| `npm run start` | Watch mode for development |

### Running the tests

The suite runs against a real WordPress install, not mocks — the things worth testing here
are cache behaviour, `WP_Query` results and REST dispatch, none of which survive being
mocked.

```bash
bin/install-wp-tests.sh wordpress_test <db-user> <db-pass> <db-host> 6.7
composer test
```

If you are using the VIP dev environment, its MySQL is already exposed on the host — take
the port from `docker ps` and pass `--skip-db-creation` style arguments as needed:

```bash
bin/install-wp-tests.sh wordpress_test wordpress wordpress 127.0.0.1:50400 6.7 true
WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test
```

61 tests cover the save guards, the count clamp, REST validation, cache invalidation,
index ordering, the draft round-trip, and the list-table controls. They have been
mutation-checked: removing the capability check, the meta-key filter or the count clamp
each turns the suite red.

---

## Running it in a VIP local environment

Verified against VIP-CLI 4.1.0 with the plugin loaded through a local checkout of
[`Automattic/vip-go-skeleton`](https://github.com/Automattic/vip-go-skeleton), so
the layout matches a real VIP application.

```bash
git clone --depth 1 https://github.com/Automattic/vip-go-skeleton.git ~/vip-skeleton
rsync -a --exclude .git --exclude node_modules --exclude vendor \
  ./ ~/vip-skeleton/plugins/vip-featured-posts/

vip dev-env create --slug vip-featured --title "VIP Featured" \
  --multisite false --php 8.2 --wordpress 6.7 \
  --app-code ~/vip-skeleton --mu-plugins demo \
  --elasticsearch n --phpmyadmin n --xdebug n --cron n --mailpit n --photon n

vip dev-env start --slug vip-featured
vip dev-env exec --slug vip-featured -- wp plugin activate vip-featured-posts
vip dev-env exec --slug vip-featured -- wp theme activate twentytwentyfive
```

Two things worth knowing:

- **Use `--app-code <path>`, not `--app-code demo`.** The `demo` value mounts a
  *read-only* image of the skeleton, so there is nowhere to put your plugin. A
  local clone is writable and otherwise identical.
- **Pin WordPress to 6.7 or later for the skeleton.** The skeleton bundles the
  `twentytwentyfive` theme, which requires WP 6.7. On WP 6.4 it cannot activate,
  no theme renders, and every front-end request returns HTTP 200 with an empty
  body — which looks like a plugin fault but is not one. The plugin itself
  supports 6.4+; only the skeleton's theme forces the higher floor.

Because the plugin is copied rather than symlinked (Docker bind mounts do not
follow symlinks out of the mount), re-run the `rsync` after editing, and remember
that `build/` must exist in the copy for the block to register.

---

## VIP-relevant design decisions

These are the parts worth reviewing.

### Caching: versioned keys, not key enumeration

`includes/query.php` caches each result set in the object cache under a key that
embeds a **cache version integer**:

```
featured_v<version>_n<count>
```

Invalidation is `wp_cache_incr()` on that single version key. Every previously cached
permutation is orphaned at once and ages out on its own.

It fires from two directions. Every index mutation routes through `Index\set_ids()`,
which invalidates as it writes — so featuring a post through the meta box, a bulk action,
Quick Edit, the row toggle, WP-CLI or the REST meta API all converge on the same
invalidation. Separately, `save_post_post` covers edits that change what the list
*renders* without changing who is in it: a retitled post, a new excerpt, a draft going
live.

This matters on VIP specifically. The object cache is shared and remote, and it
offers no "delete by prefix" primitive — so the alternative is tracking and
deleting every `n` permutation by hand, which is both racy and chatty. One
`incr()` is atomic and costs a single round trip.

The 300-second TTL is only a backstop against a stale entry outliving a cache
reset; the version bump is what provides correctness. The TTL is written as a
literal rather than a named constant so VIP's `LowExpiryCacheTime` sniff can
statically verify it clears the 300s floor.

### The query is bounded on every axis

```php
'posts_per_page'         => $number_of_posts,  // clamped to 1..10
'no_found_rows'          => true,              // skips SQL_CALC_FOUND_ROWS
'update_post_term_cache' => false,             // terms are never rendered
'ignore_sticky_posts'    => true,              // no second query for stickies
```

The count is clamped **before** it reaches the cache key, so an attacker cannot
spray `?count=` values to blow out the cache with distinct entries.

### Why `WP_Query` rather than a convenience wrapper

`get_posts()` and `query_posts()` are the two easy answers, and both are wrong here.

`query_posts()` clobbers the main query global and forces a re-query — it is
disqualified on any site, VIP or not. `get_posts()` is closer, but it silently
sets `suppress_filters => true`, which means caching and query plugins in the
stack never see the query — on VIP that quietly bypasses platform-level query
behaviour. It also gives no clean way to set `no_found_rows`.

`WP_Query` is explicit: every performance flag above is visible at the call site,
which is exactly what a reviewer needs to audit.

### The read path never searches by meta

A `meta_key` / `meta_value` lookup is unindexed — `wp_postmeta` has no composite index
that makes it selective — so it trips `WordPress.DB.SlowDBQuery`. Rather than suppress
that warning on the hot path, the plugin stops asking the database to *find* featured
posts at all and maintains the answer on write.

| Layer | Role |
| --- | --- |
| `_vip_featured` post meta | Source of truth, per post |
| `vip_featured_post_ids` option | Ordered index of IDs, maintained on every meta write |
| `wp vip-featured rebuild` | Regenerates the index from meta |

The resulting query, captured from the running site:

```sql
SELECT wp_posts.* FROM wp_posts
WHERE 1=1 AND wp_posts.ID IN (7,5)
  AND wp_posts.post_type = 'post'
  AND ((wp_posts.post_status = 'publish'))
ORDER BY FIELD(wp_posts.ID,7,5) LIMIT 0, 5
```

No `wp_postmeta` join. A primary-key lookup with explicit ordering.

Three consequences worth noting:

- **Ordering is free.** The index is an ordered array, so newly featured posts lead and
  an editor can impose any order without a second storage mechanism.
- **Publication state is not the flag.** Posts are indexed in any non-trashed status and
  filtered to `publish` at read time, so a post going back to draft keeps its position
  and reappears where it was when republished.
- **The index is disposable.** It is derived from meta, so a lost update self-heals via
  rebuild rather than becoming corruption — which is what makes the small race window on
  concurrent option writes an acceptable trade.

The cap of 100 IDs is a platform constraint, not a product one. The option is autoloaded,
so it lands in `alloptions` on every request; VIP runs an `alloptions-limit` mu-plugin
precisely because oversized autoloaded options degrade every page load.

**One suppression remains**, in `Index\rebuild()` — the one place that still has to
search by meta. It runs on activation and on demand via WP-CLI, never on a front-end
request.

### Escaping is late and context-matched

Nothing is pre-escaped and stashed. Every dynamic value is escaped at the point of
output, with the escaper matching the context: `esc_url()` for `href`,
`esc_html()` for text nodes, `esc_attr()` for attributes. The block wrapper uses
`get_block_wrapper_attributes()` so core owns the class and style attributes.

### Sanitization, nonces, capabilities

The save handler guards in a deliberate order — cheapest and most likely first:

1. bail on autosave (`DOING_AUTOSAVE`),
2. verify the nonce,
3. `current_user_can( 'edit_post', $post_id )` — the **per-object** check, not the
   blanket `edit_posts`.

Every superglobal read is `wp_unslash()`-ed and then sanitized before use — never
trusted, never used raw. The meta itself is registered via `register_post_meta()`
with both a `sanitize_callback` and an `auth_callback`, so *any* write path is
covered, not just this form.

### `permission_callback` is `__return_true` on purpose

An explicit `permission_callback` is mandatory — omitting it is a WordPress
`_doing_it_wrong()` notice and an automatic VIP review finding.

Here `__return_true` is the correct value, and the code says why in a comment:
the endpoint is read-only, the query is pinned to `post_status => 'publish'`, and
each row returns only title, permalink, and excerpt — all of which any anonymous
visitor can already read on the front end. A capability check would gate data the
site already publishes.

The `count` argument is separately defended with `sanitize_callback => 'absint'`
and a `validate_callback` that rejects anything outside 1–10 with a `400`.

---

## Why `build/` is not committed

`build/` is gitignored here. It is generated output, fully reproducible from
`src/` via `npm run build`, and committing it produces unreviewable diffs and
pointless merge conflicts.

**On a real VIP Go application the tradeoff flips.** VIP deploys the contents of
the deploy branch as-is, so built assets *must* exist there. The standard pattern
is to keep `src/` on the working branch and have CI build and commit artifacts to
the deploy branch — the artifact gets committed, but by a machine, to a branch
humans do not review. This repo is the source side of that split.

---

## Layout

```
vip-featured-posts/
├── vip-featured-posts.php   Plugin header, constants, hook registration
├── includes/
│   ├── index.php            Ordered ID index — the reason reads hit the primary key
│   ├── query.php            Cached, bounded featured-posts query
│   ├── meta-box.php         Editor checkbox, meta registration, save handler
│   ├── block.php            Dynamic block registration + server render
│   ├── rest.php             GET /wp-json/vip-featured/v1/posts
│   ├── admin/list-table.php Column, bulk actions, Quick Edit, filter, AJAX toggle
│   └── cli.php              wp vip-featured rebuild | list
├── src/featured-list/       Block editor source (compiled to build/)
├── .phpcs.xml               WordPress-VIP-Go ruleset, PHP 8.1+
├── composer.json            PHPCS tooling
└── package.json             @wordpress/scripts
```

---

## License

GPL-2.0-or-later
