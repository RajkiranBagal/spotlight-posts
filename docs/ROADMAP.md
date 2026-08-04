# Roadmap

Taking Spotlight Posts from its original proof of concept to something that holds up
under both a VIP code review and a real editorial team.

## Status

| Phase | | |
| --- | --- | --- |
| 0 | Correctness — cache invalidation follows the data | ✅ done |
| 1 | Ordered ID index, PHPUnit suite, CI | ✅ done |
| 2 | Editorial UX — list table, ordering, scheduling | ✅ done |
| 3 | Display — Query Loop variation | ✅ done |
| 4 | Interactivity API — search, filtering, pagination | ❌ declined |
| 5 | Polish — post-type agnostic, uninstall, a11y, i18n/RTL | ✅ done |
| 6 | Named slots, fallback fill, labels | planned |

**Phases 0–3 and 5 are complete. Phase 4 was considered and declined.** Phase 4 is
where scope risk concentrates: search on VIP means Elasticsearch, which is a research
task rather than an afternoon.

**Two goals, deliberately paired:**

1. **Portfolio / interview piece** — what a VIP reviewer sees in ten minutes.
2. **Real client site** — what editors need day to day.

They agree on more than they conflict. Correctness, tests and fast queries serve both.
Where they diverge it is called out explicitly.

## Sequencing rationale

**The storage decision gates everything else.** The list table, manual ordering and
filtering all read from wherever "featured" lives. Build them on the current
unindexed meta lookup and all three get rewritten when the scale problem is fixed. So
storage lands in Phase 1, before any feature that depends on it.

Each phase ships as its own pull request.

---

## Phase 0 — Correctness · ~half a day

Not a feature. A live bug that Phase 2 would hit on every interaction.

Cache invalidation is currently hooked to `save_post_post` and `deleted_post` only.
Nothing watches the meta key itself, so this sequence serves stale data:

```
1. Read the featured list                    → cached under featured_v3_n5
2. update_post_meta( 5, '_spotlight_featured', 1 ) → no save_post fires
3. Read again                                 → still v3, still stale
```

Reachable today through WP-CLI, the REST meta API, or any other plugin. An AJAX toggle
in the posts list table — Phase 2 — would hit it every single time, because it updates
meta directly without going through `save_post`.

**Work**

- Invalidate on `added_post_meta`, `updated_post_meta` and `deleted_post_meta`, filtered
  to `_spotlight_featured`.
- Keep the `save_post` hook — it still covers status transitions and deletions.
- Prove the fix rather than assert it.

**Done when** a direct meta write invalidates the cache, demonstrated before and after.

---

## Phase 1 — Make it scale, and make it provable · ~2–3 days

The largest single win for both goals, and the phase that most changes how a reviewer
reads the repository.

### Storage: keep the meta, add an index beside it

| Layer | Role |
| --- | --- |
| `_spotlight_featured` post meta | Source of truth, per post. Unchanged. |
| `spotlight_featured_post_ids` option | Ordered array of IDs. The fast index. |
| WP-CLI `rebuild` command | Regenerates the option from meta. Repair and migration path. |

The query becomes `'post__in' => $ids, 'orderby' => 'post__in'` — a primary-key lookup.
Three things fall out at once:

- the unindexed `meta_value` scan leaves the read path entirely,
- **manual ordering comes free**, because the array is already ordered,
- a draft keeps its position, because the index tracks the flag rather than
  publication state.

*Correction to an earlier claim:* the `phpcs:ignore` does not disappear. Something has
to find the flagged posts at least once, so it survives inside `Index\rebuild()` — but
it moves off the hot path to a maintenance path that runs on activation and on demand,
which is where a suppression like that genuinely belongs.

Two caveats to design for rather than discover:

- **Concurrent writes can race** on the option. Because it is derived and rebuildable, a
  lost update self-heals — but ship the rebuild command before you need it.
- **Cap the list** at roughly 100 IDs. VIP runs an `alloptions-limit` mu-plugin precisely
  because large autoloaded options are a platform problem. Under about 1 KB it is safe to
  autoload; beyond that set `autoload => no`.

### Professional-repo baseline

- PHPUnit against the WordPress test suite: save guards, the 1–10 clamp, REST validation,
  cache invalidation, ordering.
- GitHub Actions running PHPCS, PHPUnit and the JS build across PHP 8.1 / 8.2 / 8.3.

**Done when** lint reports zero findings, CI is green, and the tests genuinely fail when a
guard is removed.

The last of those is the one that matters. A suite that passes proves nothing on its own —
it has to be shown to fail. Removing the capability check, the meta-key filter on index
sync, or the count clamp each turns the suite red, so the coverage is load-bearing rather
than decorative.

CI additionally asserts that **every `phpcs:ignore` still suppresses something**. A
suppression that has stopped matching outlives the finding it silenced and quietly
misleads the next reader; the build fails if the count drifts.

---

## Phase 2 — Editorial UX · ~2–3 days

Primarily client value, but it demonstrates well.

- List-table column, which lands in Screen Options automatically via
  `manage_post_posts_columns`.
- **Bulk actions** — "Mark as featured" / "Unmark as featured". What editors actually
  reach for, more than a per-row toggle.
- Quick Edit checkbox.
- AJAX row toggle, with a nonce and a per-object `current_user_can( 'edit_post', $id )`.
- Drag-to-reorder admin screen — nearly free once Phase 1 stores an ordered array.
- **Scheduling**: feature a post until a given date. A common editorial request, and it
  turned out to be a caching problem rather than a UI one. A cron event clears the flag
  at the expiry moment, which routes through the same index sync as any other unfeature
  and so invalidates the cached lists then. A read-time check is the safety net for a
  cron run that has not happened yet. The non-obvious part, found against the running
  site rather than in a test: *writing* an expiry has to invalidate the cache too,
  because the read-time check only runs on a cache miss.

The list table already primes the post meta cache for the current page, so reading meta
per row costs no additional queries. Do not hand-roll a lookup.

---

## Phase 3 — Display · ~2–4 days

### Extend Query Loop rather than rebuild it

Core already ships the "cards with toggleable parts" system: Query Loop, Post Template,
Post Title, Post Featured Image, Post Excerpt, Post Date. Rebuilding it means owning a
card layout engine, a styling UI, `theme.json` integration, and missing every future core
improvement.

- Register a **Query Loop block variation** plus a `query_loop_block_query_vars` filter
  that injects the featured condition.
- **Keep the existing block** as the simple, opinionated list; add featured-image support
  reading from the cached ID list.
- Ship **variations and patterns, not settings.** Three good presets beat twenty
  checkboxes, and it is the direction core is moving.

For the portfolio goal this is worth more than a bespoke card builder: using core's
extension points reads as senior; rebuilding Query Loop reads as not knowing it existed.

---

## Phase 4 — Interactivity · **declined**

Originally scoped as the Interactivity API for front-end search, filtering and
pagination, with search routed through VIP Search because a public endpoint running `s=`
against MySQL `LIKE` is a textbook VIP performance finding.

**Not built, and the reason is worth recording.** Every risk in that scope assumed an
unbounded dataset. This plugin's own constants say otherwise:

| Constant | Value |
| --- | --- |
| `Index::MAX_IDS` | 100 — the entire universe of featured posts |
| `Repository::MAX_POSTS` | 10 — how many the block displays |

Elasticsearch exists for corpora you cannot hold in memory; filtering 100 curated titles
is not that. Pagination over ten items is meaningless. The cache-key explosion the plan
warned about only occurs with unbounded query permutations.

So the expensive parts — recreating the dev environment with `--elasticsearch y`,
reworking `no_found_rows`, per-query cache keys — solved problems the plugin does not
have. The original plan reasoned from a generic scaling worry rather than from these
constants.

A client-side variant (filter and sort in the browser over the already-rendered list,
no round trip, no search backend) remains available and cheap if interactive filtering is
ever wanted. It is not scheduled, because a curated list of ten items does not need
filtering.

---

## Phase 6 — Named slots, fallback fill and labels · ~1 week

The one structural gap, plus two cheap wins that are easier to build on top of it than
before it.

### Why now rather than later

There is one featured list. Real editorial use wants several: a homepage hero, sidebar
picks, a newsletter block, category features. Adding that changes three things that are
public contracts:

```
spotlight_featured_post_ids   the option key
_spotlight_featured           the per-post meta shape
/spotlight/v1/posts           the REST route
```

Retrofitting means a data migration, a REST version bump and a block deprecation.
Doing it now costs renaming demo data. This is the same timing argument that made the
VIP → Spotlight rename nearly free when it happened and expensive afterwards.

### Data model

Slots are registered through a filter, defaulting to a single `default` slot so an
existing install behaves as it does today.

**Meta becomes multi-value rather than boolean.** One key, `_spotlight_slot`, with a row
per slot the post belongs to — the WordPress-idiomatic shape for a set, and it keeps
`rebuild()` a single query per slot rather than one per meta key.

**One option per slot**, `spotlight_slot_{slug}`, each capped at `MAX_IDS`. A single map
option would grow with slots × 100 IDs and land in `alloptions` on every request; separate
options keep each under a kilobyte and let a removed slot be deleted cleanly.

> **Open question for the build:** the cap is currently 100 IDs total. With five slots
> that is 500 autoloaded integers. Either the cap becomes per-site rather than per-slot,
> or slots beyond the first few stop autoloading. Decide before writing the migration,
> not after.

### Delivery — four stacked PRs

| PR | Contents |
| --- | --- |
| 1 | `Support\Slots`, the meta and option shape, a slot-aware `Index`, and the migration |
| 2 | Slot-aware `Repository`, REST `?slot=`, block attribute, Query Loop variation, CLI `--slot` |
| 3 | Slot-aware admin: column shows membership, per-slot bulk actions, meta box, order screen switcher |
| 4 | Fallback fill and per-post labels |

### Fallback fill

When fewer posts are featured than the block asks for, optionally top up with recent
published posts not already in the list. Prevents a homepage that looks broken because
someone unfeatured two things.

The top-up is a second query, so it stays bounded and caches under the same versioned key
as the list it completes.

### Per-post labels

A short sanitized string — "Editor's pick", "Trending" — stored per post and rendered as
a badge. Escaped at output like everything else. Per post rather than per slot: a post
that is an editor's pick is one everywhere it appears.

### Risks

- **Migration is mandatory**, not optional. Existing installs hold data under the old key.
  It needs a WP-CLI command, an idempotent upgrade routine, and a block deprecation so
  saved content keeps rendering.
- **Bulk actions turn combinatorial.** "Mark as featured" becomes one action per slot.
  Beyond three or four slots the dropdown is unusable and it needs a different UI.
- **Cache keys now vary by slot.** The versioned key already handles this; it is called
  out so nobody adds a second cache layer to solve it.

### Deliberately excluded

**View-count analytics**, despite appearing in most plugins of this kind. On VIP the edge
cache means PHP does not run for most pageviews, so counts are silently wrong, and writing
to the database per request is the anti-pattern the platform exists to prevent. Doing it
properly means sampling, a queue, or reading from an analytics provider — a project, not a
feature.

**Auto-feature rules.** Editors want control; automation that overrides them gets turned
off.

---

## Phase 5 — Polish

Post-type agnostic · a custom `manage_featured_posts` capability so curating and writing
are separable · accessibility pass (heading levels, focus states, decorative-image `alt`)
· i18n and RTL · uninstall cleanup.

---

## Recommended cut line

**Phases 0–3 satisfy both goals completely.** Phase 4 is where this stops being a tight
plugin and becomes a project; search-on-VIP is a research task, not an afternoon.

### Where the two goals diverge

| Idea | Client site | Portfolio |
| --- | --- | --- |
| Multiple featured "slots" (homepage vs sidebar) | Real value, eventually | Complexity without reward — defer |
| Settings UI | Not needed | Not needed |
| Distributable-plugin concerns (wp.org, back-compat) | Out of scope | Out of scope |

### Deliberately rejected

**"Ultimate customizability."** Every toggle is a permutation to test, a cache key to
manage and a support question to answer. Presets and variations instead.
