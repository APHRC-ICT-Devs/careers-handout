# Careers API: Posting Job Ads Automatically from the Dynamics Job Portal

*A plain-language walkthrough for the WordPress and Dynamics dev teams. Read top to bottom — each section builds on the previous one.*

---

## 1. The big picture

Today, when HR approves a job ad in the Dynamics job portal, someone manually re-creates it on the WordPress careers page. We are replacing that manual step with an automatic hand-off:

```
HR approves in Dynamics  →  Dynamics sends the ad to WordPress  →  ad appears on the careers page
```

The key design choice: **approval lives entirely in Dynamics.** WordPress trusts that anything it receives has already been approved, and publishes it immediately. There is no second review queue on the WordPress side.

The connection is **push-based**: Dynamics sends data to WordPress at the moment something happens. WordPress never polls or asks Dynamics for anything, and Dynamics never needs to log into WordPress like a human would.

**Why a separate plugin?** The site already runs a plugin called **mel API**, which shares site content read-only and is live in production. Rather than modify it — and risk the production API — all the job-ad functionality lives in its own small, standalone plugin: the **Careers API**. The two share nothing; mel API was not touched in any way and remains strictly read-only.

---

## 2. Where it starts: the approval process in Dynamics

This is the part the Dynamics team owns. The trigger is HR's approval action in the job portal (however that's modelled internally — a workflow stage, a status field flipping to "Approved," a Power Automate flow, etc.). Whatever the mechanism, the moment a job reaches its approved state, Dynamics should make one outbound web call to WordPress carrying the ad's details.

The same trigger logic applies to two later events:

- **The ad changes after approval** (deadline extended, description corrected) → send the same call again with the updated details.
- **The ad must be pulled entirely** (posted by mistake) → send a "take it down" call.

### What the Dynamics dev team hands over

1. **The trigger point** — where in their approval workflow the outbound call will fire, and confirmation it can also fire on *updates* to an already-approved job.
2. **A stable job identifier** — each job's unique reference in Dynamics (e.g. `JOB-2026-014`). This ID is the thread that ties everything together (explained in section 4), so it must never change for a given job.
3. **The field list** — which Dynamics fields hold the job title, full description, short summary, country, and closing date. Their OData entity will have its own names for these — that's fine and expected; section 5 explains how the naming difference is handled.
4. **Sample data** — one or two real (or realistic) approved job records exported as JSON, so the WordPress side can check formats, especially dates.
5. **Confirmation of outbound HTTPS capability** — that their environment can make authenticated HTTPS calls to an external website, and where they will securely store the credentials WordPress issues them (e.g. Azure Key Vault or their platform's secure configuration — not hard-coded in source).

### A note on OData

Dynamics exposes and moves its data through **OData** — a standard way of describing records over the web, with its own field-naming conventions and metadata. Two things matter for this project:

- OData is how the Dynamics side *reads its own data* when building the outbound message. WordPress never talks OData.
- The hand-off between the two systems is **plain, simple JSON** in a fixed shape that WordPress defines (section 3). Dynamics reads the job via OData internally, then repackages just five fields into that simple JSON before sending. This keeps WordPress completely decoupled from Dynamics' internal data model — Dynamics can rename or restructure its entities freely, as long as the little JSON package it sends stays the same.

---

## 3. The hand-off itself: two kinds of message

WordPress has a built-in way for other software to talk to it directly, called a **REST API** — a set of special web addresses (**endpoints**) where programs, rather than people, send and receive information. The **Careers API plugin** adds exactly two writable endpoints, both at:

```
https://the-site.com/wp-json/careers-api/v1/jobs/{job-id}
```

where `{job-id}` is **Dynamics' own job reference**, right there in the address.

### Message 1 — PUT: "Here is the ad; make the website match it"

`PUT` is the web's verb for *"store this at this address, replacing whatever's there."* It covers **create and update with one message**:

- WordPress has never seen that job ID → it creates and publishes a new ad.
- WordPress already has that job ID → it overwrites the existing ad with the new details.

Dynamics never needs to ask "does this ad exist yet?" It just sends the current state of the job, every time anything changes, and WordPress makes the site match. Sending the same ad twice is harmless — you always end up with exactly one ad per job ID, never duplicates.

The JSON that travels with a PUT — this is the entire contract between the two systems:

| JSON field | Required? | Format | Example |
|---|---|---|---|
| `title` | yes | text | `"Research Officer – Nairobi"` |
| `description` | yes | text/HTML | full job description |
| `short_description` | no | text/HTML | one-paragraph summary for the listing card |
| `location` | yes | 2-letter country code | `"KE"` |
| `application_deadline` | yes | `YYYY-MM-DD` | `"2026-08-31"` |

**Closing a job needs no special message.** The careers page automatically shows a job under its "Closed" tab once the deadline date has passed. So "position filled early" or "deadline passed" is handled by a normal PUT with a deadline in the past. The ad stays visible as history, which is the intended behaviour.

### Message 2 — DELETE: "That ad should never have been up"

`DELETE` to the same address removes the ad from the site entirely (it goes to the WordPress trash, so an admin can still recover it). This is **only** for retracting mistakes — never for routine closing.

A helpful analogy: **PUT is pinning or editing a notice on a noticeboard; DELETE is ripping it off the board.** A vacancy that simply closed stays on the board, just in the "Closed" section.

---

## 4. The job ID: how the two systems stay in sync

When WordPress creates an ad through this API, it quietly stores the Dynamics job ID on the ad (in WordPress terms, a hidden "meta field" named `_careers_api_job_id` — think of a labelled sticky note on the back of the notice). Every later PUT or DELETE looks up the ad by that sticky note.

This is why the ID must be **stable**: if Dynamics ever sent the same job under a different reference, WordPress would treat it as a brand-new job and create a duplicate. The internal WordPress post number is never exposed to Dynamics and neither side needs to store anything about the other beyond this one shared ID.

---

## 5. Different field names on each side — how that's handled

Dynamics' internal field names (from its OData schema) will not match the five JSON names above, and **they don't need to**. The rule agreed here is:

> **WordPress defines the contract (the five JSON fields). Dynamics translates its own field names into that contract when it builds the outbound message.**

Concretely, somewhere in the Dynamics-side integration code there will be a small, boring, explicit mapping — pseudocode:

```
json.title                = job.msdyn_position_name
json.description          = job.aphrc_full_description
json.short_description    = job.aphrc_summary
json.location             = job.aphrc_country_code        // must be 2 letters, e.g. "KE"
json.application_deadline = job.aphrc_closing_date.ToString("yyyy-MM-dd")
```

Why translate on the Dynamics side rather than teach WordPress the Dynamics names?

- The knowledge of "what our fields mean" belongs to the team that owns those fields.
- Dynamics can rename/restructure internally and only this one mapping needs updating — WordPress never has to be redeployed for a Dynamics-side rename.
- WordPress stays simple: it accepts exactly one shape and rejects anything else, which makes failures obvious instead of silent.

Two conversions deserve explicit attention in the mapping, because they're the most likely sources of bugs:

- **Dates**: Dynamics stores full timestamps (`2026-08-31T00:00:00Z`); WordPress wants just the date part, `2026-08-31`. The mapping must format it — and be deliberate about time zones, so a deadline of "midnight Aug 31" doesn't arrive as Aug 30.
- **Country**: WordPress wants the 2-letter ISO code (`KE`), not the country name (`Kenya`). If Dynamics stores names or its own lookup values, the mapping converts them.

If the incoming message is wrong anyway — missing field, 3-letter country code, wrong date format — WordPress **rejects the entire request with a clear error message and changes nothing**. Saving is all-or-nothing: either the complete, valid ad goes onto the site, or nothing does. A faulty message from Dynamics can never leave a half-finished ad on the careers page. Those errors should be logged on the Dynamics side (section 8).

---

## 6. Security: how each side is protected

### How Dynamics proves who it is to WordPress

WordPress needs to be sure that every incoming message really comes from the job portal. That is done with **one dedicated user account**, built from three pieces that stack on top of each other:

1. **The account.** An ordinary WordPress user (e.g. `dynamics-integration`), created under **Users → Add New** exactly like any editor or admin account. Dynamics identifies itself as this user on every call. It is managed — edited, deactivated, deleted — from the same Users screen as everyone else.

2. **The role, which limits what the account can do — this is the one custom piece, and the Careers API plugin creates it for you.** The role **"Job Portal Integration"** does not exist in WordPress out of the box: it is registered automatically the moment the Careers API plugin is activated, and from then on it appears in the standard role dropdown alongside Administrator, Editor, and the rest — assigned and managed exactly like them. It carries exactly one permission — *"may write job ads via the Careers API"* — and that is the only thing the two endpoints check. The account cannot edit other content, see settings, install anything, or touch the rest of the site. If its credentials ever leaked, the damage is limited to job ads.

3. **The Application Password, which is the actual credential — a standard WordPress feature, nothing custom.** Application Passwords have been built into WordPress core since version 5.6 (2020) and appear on every user's profile page. Instead of sharing the account's normal login password, the admin opens the account's profile and generates one — a separate, machine-only password meant for exactly one application. WordPress shows it a single time (copy it immediately), and it can be **revoked with one click** at any time without affecting anything else. Dynamics sends it with every call using standard HTTP Basic authentication — a one-liner in .NET's `HttpClient`.

**So no special plugin is needed to manage any of this.** The only custom piece — the role — is supplied by the Careers API plugin itself; everything else is built into WordPress itself, and all three pieces are managed from the normal Users screens, the same place admins and editors are managed. One note: if the Application Passwords section is missing from a profile page, the usual cause is that the site isn't served over HTTPS yet — WordPress hides the feature on unencrypted sites.

**Setup — three steps for the WordPress admin** (also displayed on the plugin's settings screen):

1. Users → Add New → create e.g. `dynamics-integration`, role **Job Portal Integration**.
2. That user's Profile → Application Passwords → generate one named *"Dynamics Job Portal."*
3. Hand the Dynamics team: the site URL, the username, and the generated password (visible only once, so copy it immediately).

### How the credential is protected on the Dynamics side

- Store the username/password in a secrets store (Azure Key Vault or equivalent secure configuration), never in source code or plain config files.
- All calls go over **HTTPS** (encrypted). WordPress actually enforces this: Application Passwords are disabled on unencrypted connections by design, because the credential travels with every request. *(Practical note: the local WordPress development copy runs on plain HTTP, so end-to-end testing must happen against the HTTPS staging/production site, or with a temporary developer-only override that must never go live.)*

### Why the rest of the site is unaffected

The Careers API plugin registers **only** these two job-ad endpoints — it contains no other routes and touches no other plugin. The production mel API remains strictly read-only and was not modified. And every single call to the Careers API runs the identity and permission checks above; there is no unauthenticated path.

---

## 7. What happens inside WordPress when a message arrives — step by step

Walking through one PUT from start to finish:

1. **The request arrives over HTTPS** at `/wp-json/careers-api/v1/jobs/JOB-2026-014`, carrying the JSON and the Basic-auth credentials.
2. **WordPress verifies the credentials.** The username + application password are checked; WordPress now knows this is the `dynamics-integration` user. No valid credentials → the request is refused (401) and nothing further happens.
3. **The permission check runs.** Does this user hold the *"may write job ads"* permission? The integration role does; any other account gets refused (403).
4. **The JSON is validated field by field.** Title present? Description present? Location exactly 2 letters? Deadline a real date in `YYYY-MM-DD` form? Any failure → the whole request is rejected (400) with a message naming the problem field, and the site is untouched.
5. **The content is sanitized.** The description and short description pass through WordPress's standard HTML filter, which keeps normal formatting (paragraphs, lists, links) and strips anything dangerous (scripts). The location is uppercased.
6. **WordPress looks up the job ID** — searches for an existing career post whose hidden `_careers_api_job_id` sticky note says `JOB-2026-014` (any status, including trashed, so a previously retracted ad is restored rather than duplicated).
7. **Create or update:**
   - Not found → a new `career` post is created with status **Published** (or as a hidden **draft** if Test Mode is on — see section 11).
   - Found → that post's title and description are overwritten.
8. **The extra fields are saved** onto the post: the deadline, the location code, the short description, and (on first creation) the job ID sticky note itself.
9. **The careers page cache clears itself automatically.** The careers page doesn't rebuild its HTML for every visitor — it keeps a pre-built copy (a "transient" cache) for up to an hour for speed. Saving a career post fires a WordPress event that the Career Deadline Checker snippet already listens for, and it throws the cached copy away. **The very next visitor sees the new ad.** No code had to be added for this — the existing cache-flush hook covers posts created via the API too. *(Production caveat: the live host also has a server-level page cache in front of WordPress — see section 10 — which must be purged or configured too, or new ads will be delayed by its timeout.)*
10. **WordPress replies to Dynamics** with a small JSON receipt: the WordPress post ID, the external ID, the status, and the ad's public URL — plus code **201** ("created") or **200** ("updated"). Dynamics can log the URL or show it to HR as confirmation.

A DELETE follows steps 1–3 and 6 identically, then moves the found post to the trash (or replies 404 "no such job" if the ID is unknown) — and the same cache-flush event fires, so the ad disappears from the page immediately.

---

## 8. Performance and reliability

**On the WordPress side, the design is already light:**

- Each message is one small JSON payload and results in a handful of database writes. There is no bulk transfer, no polling, no scheduled sync job.
- The public careers page never gets slower because of this integration: visitors are served the cached page, and the cache is only rebuilt once after an actual change (step 9 above). A hundred visitors after a new job posting cost one page rebuild, not a hundred.
- The job-ID lookup is a single indexed database query. At the scale of a careers page (dozens of ads, not millions), this is instantaneous.

**On the Dynamics side, two practices are worth agreeing on:**

- **Send only on change.** Fire the call when a job is approved or edited — don't re-send the whole job list on a schedule. (Re-sends are *safe*, thanks to the PUT design; they're just unnecessary load.)
- **Retry on failure, sensibly.** If WordPress is briefly unreachable (server restart, network blip), the call fails. The Dynamics side should queue and retry (e.g. a few attempts with increasing delays) rather than silently dropping the ad — and log/alert on repeated failures. Because PUT is safely repeatable, retrying can never create duplicates, so the retry logic can be simple and aggressive. A **4xx** response (bad credentials, invalid data) should *not* be blindly retried — the same message will fail the same way; it needs a log entry and a human look.

---

## 9. What the Careers API plugin consists of

A small standalone plugin at `wp-content/plugins/careers-api/`:

| File | Purpose |
|---|---|
| `careers-api.php` | Plugin bootstrap: creates the "Job Portal Integration" role and switches the endpoints on only when enabled in settings. |
| `includes/class-endpoint.php` | The two endpoints: auth check, validation, create/update/trash logic. |
| `includes/class-admin.php` | The settings screen (**Settings → Careers API**): on/off switch, Test Mode (ads arrive as hidden drafts — see section 11), target post type, endpoint slug, job-ID field name, plus a built-in cheat-sheet of the JSON contract and setup steps. |
| `docs/` | This guide and the one-page meeting hand-out. |

**Deliberately untouched:** the production **mel API** plugin (still strictly read-only, zero changes), the Career Deadline Checker (which lives as a Code Snippet in the database — the old `career-deadline-checker` plugin folder on disk is a confirmed-inactive leftover), the careers page itself, and the `career` post type. The Careers API simply produces data in exactly the shape the existing page already consumes. The three field names it writes (`application_deadline`, `location`, `career_short`) are intentionally hard-wired to match what the snippet reads — making them configurable would only create a knob that could silently break the careers page.

---

## 10. Security & performance review — risks and how they're handled

A dedicated review of the code and the wider setup was done after the build. The issues it found are addressed in the shipped code; the rest are risks to be aware of, each with its mitigation.

### Addressed in the shipped code

- **Retract-then-repost duplicates.** A quirk of WordPress meant that after a DELETE, re-sending the same job would have created a second copy instead of restoring the first. The lookup explicitly checks trashed ads too — a re-sent job always updates the original.
- **Isolation from the production API.** The write functionality lives in its own plugin rather than inside mel API, so there is no shared code path at all between the writable job-ads routes and the production read-only API — an entire class of "loosened the wrong thing" risk is designed out.
- **Protected job-ID field.** The settings screen force-prefixes the job-ID field name with an underscore, which WordPress treats as *protected* — hidden from editing screens and blocked from normal API edits, so lower-privileged users can't tamper with the job matching.

### Security risks, accepted with mitigations

| Risk | In plain terms | Mitigation |
|---|---|---|
| **Leaked credential = instant publishing** | Because approval lives in Dynamics, whoever holds the password can put content on the public careers page immediately. | The role can *only* write job ads (nothing else on the site); all content is scrubbed of scripts; the password is revocable in one click. **Recommended additions:** an email alert to HR/webmaster whenever an ad arrives via the API (a cheap tripwire), and if the Dynamics server has a fixed outgoing IP, restrict the endpoint to that IP — this single measure neutralises most credential-abuse scenarios. |
| **Password guessing (brute force)** | WordPress doesn't lock out repeated failed API login attempts by itself. | The generated password is 24 random characters — guessing it is not practical. The site's security plugin (MalCare) adds firewall protection on production; confirm it covers `/wp-json/` traffic. The IP restriction above also closes this completely. |
| **Credential travelling unencrypted** | The password is sent with every request; over plain HTTP anyone on the network path could read it. | WordPress itself refuses Application Passwords on non-HTTPS connections. The only danger is a developer shortcut that disables that check leaking into production — any such override must be wrapped so it only ever activates on the local development environment. |
| **No record of what the API did** | If a bad ad appears, there's no trail showing when it arrived or from where. | Recommended: log every API create/update/delete (timestamp, job ID, IP) — either a one-line log entry in the plugin or an audit plugin like Simple History — and have Dynamics log every send and the response it got. Between the two, any incident can be reconstructed. |

Also checked and confirmed safe: a logged-in admin's browser cannot be tricked into firing these requests (WordPress's built-in cross-site protection covers the API), and the two-layer permission check runs on every single call.

### Performance risks

| Risk | In plain terms | Mitigation |
|---|---|---|
| **Page cache delays new ads on production** ⚠ | The careers page's own cache is flushed correctly when an ad arrives — but the live site runs **W3 Total Cache**, whose page cache sits *in front of* WordPress and keeps serving the old copy of the page until it expires or is purged. W3TC automatically purges the edited post's *own* page on save, but not the careers *listing* page, which is a different page. Confirmed production settings: cache lifetime **3600 s**, garbage collection **3600 s**, preloader cycle **900 s** — so with no changes, a new/changed/retracted ad reaches the public page within **~15–60 minutes typically, up to ~2 hours worst case**. Tolerable for new ads; slow for urgent retractions or corrections. | A go-live decision, one of: **(a)** *(recommended)* add the careers page URL to **Performance → Page Cache → Never cache the following pages** — instant updates, zero code, and the page stays fast because its built-in hourly cache (flushed instantly on every API write) does the heavy lifting; **(b)** purge it programmatically on save via W3TC's `w3tc_flush_url()` / `w3tc_flush_post()`; or **(c)** accept the delay above as-is. |
| **Revision build-up** | Every update PUT stores a "previous version" of the post, forever. | Harmless at job-ad volume. If Dynamics ever re-sends frequently, cap stored revisions for career posts (a one-line WordPress filter). |
| **Simultaneous first-time sends could race** | Two identical PUTs arriving in the same instant for a brand-new job could, in theory, both create a post. | Practically excluded by the "send only on change" rule — Dynamics sends one event per approval. Not worth extra machinery. |

Non-issues verified: the job-ID lookup is instantaneous at careers-page scale, the role setup adds no measurable load, and public visitors are never slowed by the integration (they're served the cached page; one rebuild happens per actual change, not per visitor).

---

## 11. Joint test plan

### Stage 1 — local testing first (no risk to the live site)

Because the live site is high-traffic, first-round testing happens entirely on a developer's localhost copy using a companion plugin, **Careers API — Local Test** (`wp-content/plugins/careers-api-local-test/`). It is an exact twin of the real plugin — same URL path, same JSON contract, same validation and responses — with one difference: **authentication is removed**, so it works on plain HTTP where application passwords aren't available. Two built-in safety catches: it hard-refuses to run on any host that isn't localhost (uploaded to production by mistake, it registers nothing and shows a red error notice), and it pauses itself if the real plugin is enabled on the same site.

The Dynamics team tests against it with **Postman** — the step-by-step walkthrough, including ready-to-paste requests and the full negative-test checklist, is in [`docs/postman-testing-guide.md`](postman-testing-guide.md). A Postman collection built in stage 1 works unchanged in stage 2: only the base URL changes and Basic Auth credentials are added.

### Stage 2 — the live HTTPS site: Test Mode

The settings screen has a **Test Mode** checkbox for exactly this situation. While it's on, every ad the API receives is saved as a **hidden draft** instead of being published: drafts never appear on the careers page (it only lists published ads) and are not publicly viewable — only logged-in admins/editors can open and preview them in wp-admin. The entire pipeline still runs for real — authentication, validation, field mapping, job-ID matching, update-not-duplicate — so the integration can be fully verified on production with zero public exposure. A yellow warning banner shows on the settings screen the whole time it's on.

Going live afterwards is just: untick Test Mode, then have Dynamics re-send the jobs — each re-send publishes the existing draft (the receipt's `post_status` and `test_mode` fields confirm which mode handled each message).

### The stage-2 test plan (once credentials exist on the HTTPS site)

1. WordPress admin: activate the Careers API plugin, enable it under **Settings → Careers API** (tick **Test Mode** if testing on the live site), and confirm "Job Portal Integration" appears in the role dropdown under **Users → Add New**.
2. Create the integration user and generate its Application Password (needs the HTTPS site — see the caveat in section 6).
3. Send a `PUT` to `.../wp-json/careers-api/v1/jobs/TEST-001` with a complete job ad → expect **201**, and the ad appears Published in wp-admin and under **Open** on the careers page with correct title, country name, deadline, and blurb.
4. Re-send the same job with a changed title → expect **200**, still exactly **one** ad (no duplicate).
5. Re-send with a past deadline → the ad moves to the **Closed** tab by itself.
6. `DELETE TEST-001` → the ad vanishes from both tabs (recoverable from trash in wp-admin).
7. Re-send the `PUT` for `TEST-001` after the delete → the *original* ad is restored and republished, not duplicated.
8. Negative tests: no password → **401**; a normal WordPress user's credentials → **403**; a 3-letter country code or malformed date → **400** with a clear message. Also confirm the mel API still rejects all writes with its usual **405** read-only error (it should — it was never modified).
9. Reliability test: send a PUT while WordPress is deliberately unreachable, confirm the Dynamics retry queue delivers it once the site is back.

---

## 12. Summary of who does what next

| Team | Action |
|---|---|
| Dynamics | Identify the approval trigger point; confirm outbound HTTPS + secrets storage; write the 5-field mapping (incl. date + country-code conversion); build the PUT/DELETE calls with retry logic; log every send + response; provide sample JSON; share the server's outgoing IP (for the allowlist, if fixed). |
| WordPress | Plugin built (table in section 9). Remaining: activate + enable it on staging/production, create the integration user, generate and hand over the application password, **decide the W3 Total Cache handling for the careers page — exclude (recommended) or accept the delay (section 10)**, set up the API-write email alert and logging. |
| Both | Run the joint test plan in section 11 against the HTTPS staging site. |
