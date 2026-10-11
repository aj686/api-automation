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
