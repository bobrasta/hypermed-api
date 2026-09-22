# HyperMed: Additive Prompt, Sections 16-17
# Keep this as a SEPARATE file from the main change request. Give it to Claude Code
# as its own task (or two separate tasks), not merged into the main .md file.

The "Rules that apply to EVERY section" from the main change request (Section 0) still
apply here: API first, all rules enforced server-side, web and Flutter parity, backward
compatible rollout, audit logging, no risky bulk operations without an explicit reversible
step, document the API, no step-by-step card/carousel widgets in the UI.

---

# Section 16. Transit and delivery vendor fees: receipt required, no exceptions

**Vendors covered:**
- The **clearing/transit company** that moves goods from the airport or harbor to our stores.
- **USIRI**, the transportation agency that delivers goods from our stores to customers.
- Any other logistics vendor billing Hypermed a fee, present or future.

**The single rule (no exceptions, enforced by the API, not just the UI):**

> No fee from a transit or delivery vendor is payable without an attached,
> government-acceptable receipt (EFD or other TRA-accepted receipt) covering that exact
> amount.

Do not build: quote comparisons, expected-cost ranges, overcharge blocking, scorecards,
or any override/exception path for paying without a receipt. Those were considered and
explicitly rejected. The only two things payment depends on are (1) a valid, verified
receipt, and (2) it being properly approved through the normal chain.

## 16.1 Data model

- **Vendor** (clearing company, USIRI, others): name, type (clearing/transit, delivery),
  registered payment account (bank or mobile money), TIN, contact, active/inactive.
  Vendor staff get **named individual logins** to upload documents — never a shared
  login — so every upload is attributed to a person.
- **Vendor fee / charge**: vendor, linked shipment (inbound) or delivery job (outbound,
  see 16.3), description, billed amount, currency, status (Pending receipt, Ready for
  payment, Paid, Rejected).
- **Receipt**: linked to a fee/charge, receipt type (EFD / other government-accepted),
  receipt number, issuer name, issuer TIN, date, amount, attached file (reuse the file
  upload component from the main prompt's Section 3). The **same receipt number from the
  same issuer cannot be attached to more than one payment** — enforce this uniqueness
  server-side.
- **Verification**: a `verified_by`, `verified_at` field on the receipt. Finance (or
  whichever role you designate) marks a receipt verified before payment can proceed.
  Start with a manual "Verified" checkbox/action — do not attempt automatic
  government-portal verification unless told to.

## 16.2 Payment rule enforcement

- A fee/charge cannot move to "Ready for payment" unless:
  - it has at least one attached receipt,
  - the sum of attached receipt amounts **equals** the billed/payable amount (not less,
    not more — if they don't match, block and show the difference),
  - every attached receipt is marked Verified.
- The payment request/approval endpoint **rejects** (proper validation error, not a
  silent pass) any attempt to submit a fee for payment that fails the above. This must
  hold even if the request comes from the Flutter app or a direct API call — it is not a
  UI-only check.
- **No override role, no exception flow.** If a receipt is missing, the fee is not paid.
  Period. Do not add an admin/Director bypass.
- Payment, once approved, goes to the vendor's **registered account only**, by transfer.
  No cash disbursement to an individual for a vendor fee.
- Payment for vendor fees reuses the existing approval chain and notification system from
  the main prompt (Accountant → Director, stage notifications, in-app + email) — do not
  build a parallel approval flow.

## 16.3 USIRI-specific: delivery jobs

- A **Delivery job** is created per delivery (from an allocation or invoice): unique job
  number, goods list (item, serial, quantity), origin store, destination customer/site,
  linked vendor (USIRI).
- A delivery job can be **billed and paid exactly once**. A second fee/charge referencing
  the same job number is blocked server-side ("this job has already been paid").
- Payment for a delivery job additionally requires a **signed delivery note** uploaded
  (separate from the receipt): list of delivered goods, receiver's full name, and date.
  The goods list on the note must match the job's goods list (flag if it doesn't).
  Both the delivery note and the receipt are required — the note proves delivery
  happened, the receipt proves the fee is accounted for; neither substitutes for the
  other.

## 16.4 UI

- A vendor fee / delivery job detail page shows: billed amount, receipt(s) attached (with
  verified status), the outstanding unreceipted gap (should be zero before payment is
  possible), and for USIRI jobs, the delivery note.
- The "Ready for payment" / submit-for-approval action is disabled in the UI while the
  gap is non-zero or any receipt is unverified, with the reason shown in plain text
  (e.g. "Missing receipt for TSh 250,000" or "1 receipt awaiting verification"). This is
  a UX convenience — the real enforcement is server-side per 16.2.
- Same screens and behavior on Flutter (parity per the main prompt's Section 0/14).
  Vendor staff logins may be web-only if that fits the existing app better — confirm
  with the user if unclear, otherwise assume web-only for vendor uploads.

## 16.5 Tests / acceptance criteria

1. A fee with no receipt cannot be submitted for payment (API returns a validation
   error).
2. A fee with a receipt for less than the billed amount cannot be submitted; the gap is
   shown.
3. A fee with receipts summing to more than the billed amount is also blocked until
   corrected (should not silently accept overpayment claims).
4. The same receipt number + issuer cannot be attached twice, even across different
   fees.
5. A fee cannot move to "Ready for payment" while any attached receipt is unverified.
6. A USIRI delivery job cannot be paid twice.
7. A USIRI delivery job cannot be paid without a signed delivery note attached.
8. Every step (upload, verify, approve, pay) is in the audit log with who/when.

## 16.6 Assumptions (confirm or correct)

1. Vendor staff (clearing company, USIRI) get individual named logins to upload their
   own documents.
2. Finance is the role that marks receipts "Verified" (adjust if it should be a
   different role).
3. Receipt verification is a manual action, not an automated check against a government
   system.

---

# Section 17. Performance and caching

**Symptom:** the app has felt heavy for about a month. Example flow: Machines list ~4s
to load → open a machine (e.g. XRAY123) ~3s to load → go back to the list → another ~4s
wait, even though nothing changed. A working Redis-based caching setup existed in early
versions and was lost somewhere along the way.

**Do not jump straight to adding cache layers.** A 3-4 second load on a cache miss
usually means something is doing too much work per request; caching alone would hide
that, not fix it. Work in this order:

## 17.1 Diagnose first

- Confirm what's actually configured in production right now: `CACHE_DRIVER`/`CACHE_STORE`,
  `SESSION_DRIVER`, `QUEUE_CONNECTION`. Check whether they silently fell back to
  `file`/`database`/`sync` instead of `redis`, and whether the Redis service on Railway
  is still provisioned and reachable from the app.
- Confirm `config:cache`, `route:cache`, and OPcache are actually enabled in the
  production deploy (not just available).
- Add timing/profiling to the slow endpoints (Machines list, machine detail, dashboard).
  Use Telescope/Debugbar locally and slow-query logging in production. Capture: total
  response time, DB query count, slowest queries, payload size.
- Check for N+1 queries, missing indexes on filtered/sorted/joined columns (hospital,
  status, machine, ticket dates, etc.), unpaginated queries, and on-the-fly aggregate
  calculations (dashboard tiles, machine status counts, uptime %) recalculated from
  scratch on every request.
- Confirm whether the Laravel web app calls `hypermed-api` over HTTP (extra network hop
  per page) or talks to the same database directly, and how many sequential API calls one
  screen makes.
- Report findings (with numbers) before making changes, so the fix targets the real
  cause.

## 17.2 Fix root causes found in 17.1

- Add missing indexes.
- Eager-load relations to remove N+1 queries; select only needed columns.
- Paginate every list server-side; trim API response payloads to what each screen
  actually renders.
- Replace on-the-fly aggregates (dashboard counts, uptime, region breakdown, cost totals
  from Section 12 of the main prompt) with precomputed/cached values, refreshed
  periodically or on the relevant data change — not recalculated from raw rows on every
  page load.
- Make the 13,000+ hospital search (Section 4 of the main prompt) use an indexed,
  server-side query — never a full table scan or full in-memory filter.

## 17.3 Redis caching layers (after 17.1/17.2)

- **Reference data** (regions, districts, equipment types, machine models, roles,
  settings): long TTL (hours), cleared on edit.
- **Aggregates/dashboard data**: short TTL (30-120s) or background-refreshed, so nobody
  waits on the live calculation.
- **List pages**: cache paginated results per filter combination, short TTL.
- **Detail pages** (e.g. a machine's summary): cached, invalidated via model
  observers/events when that record or its related tickets/costs change (e.g. a
  `machine:{id}` cache tag).
- Use **stale-while-revalidate** where the framework supports it (e.g. Laravel's
  `Cache::flexible`) so users get an instant, slightly-stale response while it refreshes
  in the background, and to avoid a cache-expiry stampede.
- **Never cache across users incorrectly**: any permission-dependent data (cost tabs,
  approvals, per-technician views) must be cache-keyed by user/role so cached data can
  never leak between users.
- Approval/payment status (Sections 1, 8, 11 of the main prompt) uses a short TTL or is
  invalidated immediately on every stage transition — never shows a stale approval
  status.
- Put sessions and the queue on Redis as well, separate from the main database.

## 17.4 HTTP-level

- Support conditional requests (ETag or Last-Modified) so unchanged data returns a small
  "not modified" response.
- Enable response compression (gzip/brotli).
- Use `Cache-Control: private` appropriately for user-specific data.
- Keep-alive connections between the web app and the API; keep both close to the
  database region.

## 17.5 Client-side behavior (this is what fixes the exact flow described)

- **Preserve list state on navigation back.** Returning from a machine detail to the
  Machines list must show the previously loaded list (same filters, page, scroll
  position) instantly, not re-fetch and re-render from a blank state.
- **Stale-while-revalidate in the UI**: show the cached list immediately, silently
  refresh in the background, and only update the view if something actually changed.
- **Prefetch** machine details on hover (web) or as list rows become visible, so opening
  a record feels instant.
- **Skeleton loaders** instead of blank/spinner-only screens.
- **Lazy-load heavy tabs** (Service Costs, Revenue, etc. from the main prompt) only when
  opened, not as part of the initial page load.
- **Flutter**: add a local cache (in-memory + a local store such as Hive/Isar/Drift) so
  lists open instantly from cache and refresh in the background; this also helps
  technicians on weak connections. Cache images via an image cache manager.

## 17.6 Targets (adjust once 17.1 gives real baseline numbers)

- Cached/warm list view: under ~0.5s perceived load.
- Detail view: under ~1s.
- Cold (uncached) requests should still be reasonable — caching must not become a crutch
  for an unindexed query.

## 17.7 What NOT to do

- Do not add caching before profiling — you may cache a slow query and still be slow on
  every cache miss, or worse, cache stale/wrong permission-scoped data.
- Do not cache approval/payment status carelessly (Sections 1, 8, 9, 11, 16 of the main
  prompt all depend on current, correct status).
- Do not skip client-side state preservation — server caching alone will not fix the
  "list reloads every time I go back" complaint, since that is a client re-fetch/re-render
  issue.

## 17.8 Questions to confirm with the user before/while implementing

1. What does the web front end use — plain Blade, Livewire, Inertia, or a separate JS
   framework? This decides how to implement list-state preservation and background
   refresh.
2. Does the Laravel web app call `hypermed-api` over HTTP, or share the database
   directly?
3. Is the Redis service still provisioned on Railway, and are its environment
   variables still wired into the app config?

Start Section 17 with the diagnosis (17.1) and report findings before writing any cache
code.
