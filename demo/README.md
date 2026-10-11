# Demo files

Example Postman files with **no real credentials**. They test this app's own
endpoints, so they run as soon as the stack is up.

| File | Import it on |
|---|---|
| `collections/api-automation-self-check.postman_collection.json` | a project's **Collections** tab |
| `environments/webstack-docker.postman_environment.json` | an environment's page → **Import from Postman** |

The environment uses `base_url = http://nginx` and `host = api-automation.local`:
the runner container reaches other webstack sites through the `nginx` service
name, and the `Host` header picks the vhost. `localhost` would mean the runner
container itself.

Expected result: **PASS, 6/6**. The second request *expects* to be refused —
an error status can be the right answer.

## Outbound capture demo

`collections/outbound-capture-demo.postman_collection.json` with
`environments/webstack-docker-outbound.postman_environment.json` shows
outbound testing: step 1 stands in for *your API* calling a webhook, step 2
asks this app what arrived (`/api/v1/captures/...?since={{run_started_at}}`)
and asserts on method, body and headers, retrying up to 5 times (~10 s) for
calls sent later from a queue.

1. On the project's **Captures** tab, create a capture URL for a
   non-production environment, copy the path shown once, then **Enable** it.
2. Import the outbound environment file into that environment, then on its
   page set: `project` = project slug, `capture_bucket` = bucket slug,
   `capture_path` = the copied `/capture/<id>/<token>` path, `api_token` =
   your automation API token, `webhook_secret` = anything.
3. Import the collection and run it. Expected: **PASS, 5/5**.

In real use, replace step 1 with the request that makes your API send its
outgoing call, and point that API's outgoing URL at the capture URL.
`run_id` and `run_started_at` are provided to every run by the app.
