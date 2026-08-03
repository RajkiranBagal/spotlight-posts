# VIP Featured Posts

A small WordPress plugin that lets editors flag posts as **featured** and surfaces
them two ways: a dynamic Gutenberg block and a public REST endpoint.

> **This is a self-directed proof of concept.** It is not a client deliverable and
> is not affiliated with WordPress VIP. I built it to demonstrate the habits a VIP
> engagement actually depends on — object caching, bounded `WP_Query`, disciplined
> escaping and sanitization, nonce plus capability checks, and a clean
> `WordPress-VIP-Go` PHPCS run.

- **Requires:** WordPress 6.4+, PHP 8.1+
- **Text domain:** `vip-featured-posts`
- **Meta key:** `_vip_featured`

---

## What it does

Editors get a **Featured** checkbox in the post sidebar. Checked posts appear in:

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
| `npm run build` | One-off production build |
| `npm run start` | Watch mode for development |

---

## VIP-relevant design decisions

These are the parts worth reviewing.

### Caching: versioned keys, not key enumeration

`includes/query.php` caches each result set in the object cache under a key that
embeds a **cache version integer**:

```
featured_v<version>_n<count>
```

Invalidation is `wp_cache_incr()` on that single version key, hooked to
`save_post_post` and `deleted_post`. Every previously cached permutation is
orphaned at once and ages out on its own.

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

### The slow-query exception, and how it goes away at scale

A `meta_key` / `meta_value` lookup is unindexed — `wp_postmeta` has no composite
index that makes it selective — so it trips `WordPress.DB.SlowDBQuery`.

It is suppressed on **one line**, with a justification, and nowhere else:

```php
'meta_value' => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded to MAX_POSTS and object-cached; see comment above.
```

The suppression is defensible here because the query is capped at 10 rows, cached
behind a versioned key, and runs with `no_found_rows`. Steady-state traffic never
reaches the database.

**At real scale I would not run this query at all.** The fix is to stop asking the
database to find featured posts and instead maintain the answer on write:

1. Keep the canonical list in an option — `vip_featured_post_ids`, an array of IDs.
2. Update it in the `save_post` handler that already runs, capping its length.
3. Read with `'post__in' => $ids, 'orderby' => 'post__in'`, which hits the primary key.

That turns an unindexed table scan into a primary-key lookup plus one autoloaded
option read, and the `phpcs:ignore` disappears with it. The meta key stays as the
per-post source of truth so the option can always be rebuilt.

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
│   ├── query.php            Cached, bounded featured-posts query
│   ├── meta-box.php         Editor checkbox, meta registration, save handler
│   ├── block.php            Dynamic block registration + server render
│   └── rest.php             GET /wp-json/vip-featured/v1/posts
├── src/featured-list/       Block editor source (compiled to build/)
├── .phpcs.xml               WordPress-VIP-Go ruleset, PHP 8.1+
├── composer.json            PHPCS tooling
└── package.json             @wordpress/scripts
```

---

## License

GPL-2.0-or-later
