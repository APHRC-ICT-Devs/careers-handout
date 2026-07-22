# Testing the Careers API with Postman

*For the Dynamics dev team (and anyone else verifying the integration). Covers local testing first, then how the same collection is pointed at staging/production later.*

---

## 1. The two testing stages

| Stage | Where | Plugin used | Authentication |
|---|---|---|---|
| **1. Local** | The WAMP test server on the APHRC network: `http://10.176.203.71:81/aphrcnew_jan_2026/` | **Careers API — Local Test** | **None** — requests need no credentials |
| **2. Staging / production** | The live HTTPS site | **Careers API** (the real one) | Basic Auth with the integration user + application password |

The local test plugin is an exact twin of the real one — same URL path, same JSON contract, same validation, same responses — with authentication removed so testing works on plain HTTP without WordPress application passwords. It physically refuses to run on any non-localhost site, so it cannot be misused in production.

**Everything you build in Postman against stage 1 works unchanged in stage 2** — you only change the base URL and add credentials.

---

## 2. One-time WordPress setup (local machine)

1. Make sure WAMP is running (Apache + MySQL both green).
2. wp-admin → Plugins → activate **Careers API — Local Test (NO AUTH)**.
3. If the real **Careers API** plugin is also active locally, make sure it is *disabled* under Settings → Careers API (the local test plugin pauses itself if both try to register the same routes — it will tell you with a red notice).
4. You should see a yellow notice on the Plugins page: *"Careers API — Local Test is running… endpoints are OPEN."* That's the confirmation it's live.

---

## 3. Postman setup

### Create the collection

1. In Postman: **New → Collection**, name it `APHRC Careers API`.
2. Open the collection's **Variables** tab and add:

| Variable | Initial value |
|---|---|
| `base_url` | `http://10.176.203.71:81/aphrcnew_jan_2026/wp-json` |
| `job_id` | `TEST-001` |

> **The test server is on the APHRC network** at `10.176.203.71:81` — a private LAN address, so anyone on the office network (or VPN) can send Postman requests to it from their own machine; nothing is exposed to the internet. If it doesn't respond from another machine, the usual culprits are the WAMP machine's Windows Firewall blocking inbound port 81, or WAMP still in "offline" (localhost-only) mode.
>
> **If the site URL ever changes**, adjust `base_url` — it's everything before `/wp-json` plus `/wp-json`. The exact live value is also shown in the yellow admin banner on the test site's wp-admin dashboard.
>
> **If you get a 404 on every request**, the WordPress "permalinks" setting is probably on *Plain*. Either fix it in wp-admin (Settings → Permalinks → choose "Post name" → Save) or use this alternative base URL form, which always works:
> `http://10.176.203.71:81/aphrcnew_jan_2026/index.php?rest_route=` — and then e.g. the jobs request becomes `http://10.176.203.71:81/aphrcnew_jan_2026/index.php?rest_route=/careers-api/v1/jobs/TEST-001`.

### Request 1 — Create a job ad

- **Method:** `PUT`
- **URL:** `{{base_url}}/careers-api/v1/jobs/{{job_id}}`
- **Auth tab:** No Auth *(local testing only)*
- **Body tab:** select **raw**, type **JSON**, paste:

```json
{
  "title": "CONSULTANCY TO EVALUATE THE EAST AFRICAN COMMUNITY PANDEMIC PREPAREDNESS PROJECT",
  "description": "<p class=\"ql-align-justify\"><strong>Background</strong></p><p class=\"ql-align-justify\">The African Population and Health Research Center (APHRC) is a premier research-to-policy institution...</p><h3><strong>Application Process</strong></h3><ul><li>A cover letter outlining eligibility for the assignment.</li><li>A technical proposal detailing the proposed approach.</li></ul>",
  "short_description": "<p>Consultancy to evaluate the EAC Pandemic Preparedness Project (2023–2026).</p>",
  "location": "Dakar, Senegal",
  "application_deadline": "2026-12-31"
}
```

> `location` accepts either free text like `"Dakar, Senegal"` (recommended: `Duty_Station, Duty_Country` — displayed as sent) or a 2-letter ISO code like `"KE"` (displayed as the full country name). In real use, `{{job_id}}` is the `Recruitment_Need_Code` (e.g. `RTN-00095`) and the deadline is `RecruitmentAdvertList.Closing_Date`, already in `YYYY-MM-DD` form.

- **Send.** Expected: **`201 Created`** with a body like:

```json
{
  "id": 1234,
  "external_id": "TEST-001",
  "post_status": "publish",
  "permalink": "http://10.176.203.71:81/aphrcnew_jan_2026/career/consultancy-to-evaluate-the-east-african-community-pandemic-preparedness-project/",
  "test_mode": false,
  "local_test": true
}
```

- **Verify visually:** open the local careers page — the ad appears under the **Open** tab. Open the `permalink` URL — the full description renders.

### Request 2 — Update the same ad (no duplicate)

Duplicate Request 1, change `"title"` to something else, **Send**.

- Expected: **`200 OK`** (not 201), same `id` as before.
- The careers page shows the new title, still exactly one card.

### Request 3 — Close the ad

Duplicate Request 1, set `"application_deadline": "2020-01-01"` (any past date), **Send**.

- Expected: **`200 OK`**.
- The ad moves to the **Closed** tab on the careers page by itself.

### Request 4 — Retract (delete) the ad

- **Method:** `DELETE`
- **URL:** `{{base_url}}/careers-api/v1/jobs/{{job_id}}`
- No body needed. **Send.**
- Expected: **`200 OK`**, `"post_status": "trash"`. The ad disappears from both tabs.

### Request 5 — Re-send after delete (restore, not duplicate)

Send Request 1 again unchanged.

- Expected: **`201`or `200`** with the **same `id`** as the original — the trashed ad is restored and republished, never duplicated.

### Negative tests (all should fail cleanly, changing nothing)

| Send | Expect |
|---|---|
| Request 1 with `"location": ""` (empty) | **`400`** — error names the `location` field |
| Request 1 with `"application_deadline": "31/12/2026"` | **`400`** — error names the deadline field |
| Request 1 with `"title"` removed | **`400`** — error names the `title` field |
| `DELETE {{base_url}}/careers-api/v1/jobs/NO-SUCH-ID` | **`404`** — "No job ad found for that external_id" |

A 400 means WordPress rejected the *entire* message — check wp-admin to confirm nothing was created or changed.

---

## 4. Pointing the same collection at staging/production later

When testing moves to the real HTTPS site (real **Careers API** plugin, Test Mode ticked so ads arrive as hidden drafts):

1. Change the collection variable `base_url` to `https://<the-site>/wp-json`.
2. On each request (or once at collection level): **Auth tab → Basic Auth** — username = the integration user, password = the WordPress **application password** you were given. Postman handles the header automatically.
3. Everything else — URLs, bodies, expected responses — is identical. Two additions:
   - The response's `test_mode` field will be `true` and `post_status` will be `draft` while Test Mode is on (ads visible only in wp-admin, never on the public page).
   - An extra negative test becomes possible: send any request **without** credentials → expect **`401`**; with a normal (non-integration) user's credentials → **`403`**.

---

## 5. What the Dynamics code should take from this

The Postman requests above are exactly what the .NET integration must produce:

- `PUT` (create/update/close) and `DELETE` (retract) to `/wp-json/careers-api/v1/jobs/{your-job-id}`
- `Content-Type: application/json`, body with the 5 fields (`short_description` optional)
- Basic Auth over HTTPS in production
- Treat **2xx** as success, log **4xx** and alert (don't retry), retry **5xx**/network errors with backoff

---

## 6. Safety notes

- The Local Test plugin has **no authentication by design** and hard-refuses to run on any host that isn't local: `localhost` / `127.0.0.1` / `*.local` / `*.test`, or a **private-network IP address** (such as the test server's `10.176.203.71` — private addresses are unreachable from the internet, so they can never be the production site). Even so: it must never be copied to staging or production. It is not part of the deployable site.
- The real Careers API plugin is the only one that ships. The single difference between the two is the authentication check, so passing tests locally is meaningful.