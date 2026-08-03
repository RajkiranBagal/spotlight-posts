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
| 4 | Interactivity API — search, filtering, pagination | deferred |
| 5 | Polish — post-type agnostic, capability, a11y, i18n | not started |

**Phases 0–3 were the recommended cut line, and both goals are met there.** Phase 4 is
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

## Phase 4 — Interactivity · ~3–5 days · highest risk

The modern approach, and where scope risk concentrates.

- Interactivity API for front-end filtering, sorting and pagination.
- **Pagination via an `N+1` fetch**, not `found_rows` — otherwise it undoes the existing
  `no_found_rows` optimization.
- **Search must go through VIP Search / Elasticsearch.** A public endpoint running `s=`
  against MySQL `LIKE` is a textbook VIP performance finding. Requires recreating the dev
  environment with `--elasticsearch y`.

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
