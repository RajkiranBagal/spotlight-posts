# Spotlight Posts

A WordPress plugin that lets editors flag posts as **featured** — from the editor, the
posts list, bulk actions or Quick Edit — and surfaces them three ways: a variation of
core's Query Loop, a dedicated dynamic block, and a public REST endpoint.

> **Not affiliated with WordPress VIP or Automattic.** The name and the `vip_` prefixes
> refer to the platform this targets, not to its authorship. It is independent work,
> built to the standards a VIP engagement depends on: object caching, primary-key
> queries, disciplined escaping and sanitization, nonce plus capability checks, and a
> clean `WordPress-VIP-Go` run.

**What that rests on:** 106 integration tests against a real WordPress install · CI on
PHP 8.1 / 8.2 / 8.3 and WordPress trunk · zero PHPCS findings · every feature exercised
end to end in a VIP local environment.

It has not run against VIP production traffic, so read "VIP-ready" as *built to the
standard and verified locally*, not as *proven at scale*.

- **Requires:** WordPress 6.4+, PHP 8.1+
- **Text domain:** `spotlight-posts`
- **Meta key:** `_spotlight_featured`

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

A post can also be featured **until a given date and time**, set alongside the checkbox in
the editor. See [scheduled expiry](#scheduled-expiry-is-a-caching-problem) for how that
interacts with caching.

**Posts → Featured Order** arranges the list by dragging, or with keyboard move controls
for anyone not using a mouse. That order is what the block and the REST endpoint return.
Curation is gated on `edit_others_posts` rather than post authorship, filterable via
`spotlight_posts_manage_capability` — deciding what the homepage promotes and being
able to write posts are different jobs.

Out of the box this applies to posts. Any post type can opt in through the
`spotlight_posts_post_types` filter, and they share one ordered list rather than being
grouped by type.

Flagged posts appear in three places:

- a **Featured Posts** variation of core's Query Loop — full card layouts with featured
  images, titles, excerpts and dates, styled by your theme,
- the **Featured Posts** block (dynamic, server-rendered, configurable heading and count), and
- `GET /wp-json/spotlight/v1/posts?count=5`

All three read the same index and apply the same expiry rule, so they cannot disagree
about what is featured.

---

## Setup

```bash
composer install     # PHPCS + WordPress VIP coding standards
npm install          # @wordpress/scripts build toolchain
npm run build        # compiles blocks/ -> build/
```

`src/` is PHP and `blocks/` is JavaScript — deliberately separated, because PSR-4 wants
PHP in `src/` while `wp-scripts` defaults to `src/` for JavaScript, and a directory
holding both tells you nothing at a glance. `--webpack-src-dir=blocks` redirects the
bundler.

`npm run build` produces two entry points: `build/featured-list` for the dedicated block,
and `build/query-loop` for the Query Loop variation. They are separate `wp-scripts` runs
because `wp-scripts build` only discovers entries from `block.json`, and a block variation
has no `block.json` of its own.

Building is **required before activating the plugin**. Both registrations deliberately
no-op when their build output is missing, so a fresh clone that has not been built yet
degrades to "block absent" rather than a fatal error.

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

127 tests cover the save guards, the count clamp, REST validation, cache invalidation,
index ordering, the draft round-trip, the list-table controls, the ordering screen, scheduled expiry, the Query Loop variation, multi-post-type support and the block's heading levels. They have been
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
  ./ ~/vip-skeleton/plugins/spotlight-posts/

vip dev-env create --slug vip-featured --title "VIP Featured" \
  --multisite false --php 8.2 --wordpress 6.7 \
  --app-code ~/vip-skeleton --mu-plugins demo \
  --elasticsearch n --phpmyadmin n --xdebug n --cron n --mailpit n --photon n

vip dev-env start --slug vip-featured
vip dev-env exec --slug vip-featured -- wp plugin activate spotlight-posts
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

## Architecture

Every class implements a small `Registrable` contract and declares its own hooks.
`Plugin` is the composition root: it builds the object graph once and lets each service
attach itself. The main plugin file registers **no hooks at all** — it holds a plugin
header, a PSR-4 autoloader and one call to `Plugin::boot()`.

Dependencies arrive through constructors. Nothing reaches out for a service it was not
given, which is what keeps the graph readable:

```
Cache, PostTypes, Request      depend on nothing
Index, Schedule                depend on Cache + PostTypes
Repository                     depends on Index, Schedule, Cache, PostTypes
Frontend / Admin / Rest / Cli  depend on Repository or Index
```

**That direction is the point.** Cache invalidation used to live inside the index and the
scheduler, so both called into the query module while it called back into them — two
circular dependencies. Nothing was broken, because PHP resolves those at call time, but
neither module could be reasoned about or tested in isolation. Extracting `Support\Cache`
gave all three a collaborator they depend on one way only.

### What was deliberately not done

Wrapping the existing functions in static classes would have added ceremony and kept both
cycles. There are no static-only classes here, no interfaces with a single
implementation, and no getters around plain data. `Registrable` earns its place because a
dozen classes implement it; `FeaturedPost` earns its because an array shape documented in
a docblock is enforced by nothing.

`Plugin::instance()` exists for exactly two callers — the activation hook, which runs
before anything holds a reference, and the test suite, which is a composition root of its
own. It is not a general-purpose service locator, and application code does not use it.

## VIP-relevant design decisions

These are the parts worth reviewing.

### Caching: versioned keys, not key enumeration

`Support\Cache` stores each result set under a key that embeds a **cache version
integer**:

```
featured_v<version>_n<count>
```

Invalidation is `wp_cache_incr()` on that single version key. Every previously cached
permutation is orphaned at once and ages out on its own.

It fires from two directions. Every index mutation routes through `Index::set()`, which
flushes as it writes — so featuring a post through the meta box, a bulk action, Quick
Edit, the row toggle, WP-CLI or the REST meta API all converge on the same invalidation.
Separately, `save_post` covers edits that change what the list *renders* without changing
who is in it: a retitled post, a new excerpt, a draft going live.

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
| `_spotlight_featured` post meta | Source of truth, per post |
| `spotlight_featured_post_ids` option | Ordered index of IDs, maintained on every meta write |
| `wp spotlight rebuild` | Regenerates the index from meta |

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

**One suppression remains**, in `Index::rebuild()` — the one place that still has to
search by meta. It runs on activation and on demand via WP-CLI, never on a front-end
request.

### Extending Query Loop instead of rebuilding it

Core already ships the "cards with toggleable parts" system people usually try to
rebuild: Query Loop, Post Template, Post Title, Post Featured Image, Post Excerpt, Post
Date. Building your own means owning a card layout engine, a styling UI, `theme.json`
integration — and missing every future improvement to those blocks.

So the plugin contributes the one thing core cannot know, which posts are featured and in
what order, and lets core own the rest. A block variation registers in the inserter; a
`query_loop_block_query_vars` filter narrows the query core already assembled, so
pagination, post type and every other editor control keep working.

**The part that is not obvious.** The documented way to identify a variation server-side
is a top-level `namespace` attribute. It does not work here, and the failure is silent —
the filter runs, finds nothing, and every Query Loop on the site renders unfiltered.

`core/query` provides only `query` and `enhancedPagination` as block context, and
`query_loop_block_query_vars` receives the **Post Template**, not the Query block. A
top-level attribute never reaches it. Verified on WordPress 6.7:

```
context keys : query, enhancedPagination
namespace in query context : (absent)
```

The variation therefore sets `namespace` in **both** places — top level for core's
variation matching and `isActive`, and inside `query` where the server can actually read
it. Both are load-bearing.

### Deleting the plugin removes its data, and only its data

`uninstall.php` clears both meta keys, the index option and any scheduled expiry events.
Post content is never touched: a site that deletes the plugin keeps its posts and simply
stops treating any of them as featured.

Two details are load-bearing. The option is deleted **after** the meta, because
`delete_post_meta_by_key()` fires `deleted_post_meta` for every row — and if the plugin's
hooks are still attached, the index sync sees a missing option and rebuilds it. And
`wp_unschedule_hook()` is used rather than `wp_clear_scheduled_hook()`, because expiry
events carry per-post arguments that a bare hook-name clear would not match.

The object cache group is dropped only where the backend supports `flush_group`,
deliberately *not* falling back to `wp_cache_flush()` — that would evict every other
plugin's cached data on the way out.

### Scheduled expiry is a caching problem

"Feature this until Friday" sounds like a UI feature. It is really a question about how
long a cached list is allowed to disagree with reality. Two mechanisms cover it, and each
covers the other's weakness.

**A cron event fires at the expiry moment** and clears the flag. Because it clears the
flag through `delete_post_meta()` rather than editing the index directly, it takes the
same path as any other unfeature — the index sync removes the post and invalidates the
cached lists *at the expiry moment*, not whenever the TTL happens to lapse.

**A read-time check filters expired posts** before the payload is cached. WP-Cron is
request-driven on stock WordPress, so on a quiet site an event can fire late; VIP backs
it with real cron, where this rarely matters. Without the read-time check, a late cron
would leave an expired post visible indefinitely.

**Writing an expiry invalidates the cached lists too.** This one was found against the
running site rather than in a test, and it is the non-obvious part: the read-time check
only runs on a cache *miss*. Setting an expiry without invalidating meant a cache hit
returned before the check was ever reached, so a post expired "now" stayed visible until
the TTL lapsed. Changing when something expires changes what the list contains, so it has
to invalidate exactly like changing the flag does.

The residual window, stated plainly: if a post expires just after a list is cached *and*
cron never fires, it can remain visible for up to the 300-second TTL. Cron closes that in
practice; the read-time check guarantees it cannot outlive a single TTL.

Expiries are stored as UTC timestamps and converted to the site's timezone for display.
A `datetime-local` input sends wall-clock time with no offset, so interpreting it as
server time would make "featured until 5pm" land at a different real moment depending on
where the server sits.

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
spotlight-posts.php          Plugin header, PSR-4 autoloader, Plugin::boot()
uninstall.php                Removes the plugin's data on delete
src/
├── Plugin.php               Composition root — builds services, registers them
├── Registrable.php          Contract: a component that attaches its own hooks
├── Support/
│   ├── Cache.php            Versioned object cache
│   ├── PostTypes.php        Which post types can be spotlighted
│   ├── Request.php          Sanitized superglobal reads
│   └── Translations.php     Text domain loading
├── Featured/
│   ├── Index.php            Ordered ID index — why reads hit the primary key
│   ├── Repository.php       Cached, bounded read path
│   ├── Schedule.php         Expiry meta, cron, read-time filter
│   └── FeaturedPost.php     Readonly DTO
├── Frontend/
│   ├── Block.php            Dynamic block + server render
│   └── QueryLoopVariation.php
├── Admin/
│   ├── MetaBox.php          Editor checkbox and expiry field
│   ├── OrderScreen.php      Drag-to-reorder screen
│   └── ListTable/
│       ├── Column.php       The Featured column and its toggle
│       ├── BulkActions.php  Mark / unmark, and the result notice
│       ├── QuickEdit.php    Inline checkbox
│       ├── Filter.php       Featured / Not featured dropdown
│       └── AjaxToggle.php   admin-ajax handler and its assets
├── Rest/PostsController.php GET /wp-json/spotlight/v1/posts
└── Cli/Command.php          wp spotlight rebuild | list
blocks/                      Block editor sources, compiled to build/
├── featured-list/           block.json + editor script for the dedicated block
└── query-loop.js            registers the Query Loop variation
assets/                      Hand-written admin CSS and JS, shipped as-is
build/                       Compiled block output (gitignored)
```

Every class implements `Registrable` and declares its own hooks — the main plugin file
registers none. Dependencies arrive through constructors; nothing reaches for a service
it was not given.

---

## License

GPL-2.0-or-later
