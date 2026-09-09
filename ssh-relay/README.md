# Galaxy API private SSH relay

This directory is a private subservice of the `galaxy-api` management-center
module. It is not part of the `galaxy-cli` user distribution.

The relay accepts public-key-only SSH sessions. Authentication, authorization,
target-node resolution and Agent-session routing remain in `galaxy-api`.
The relay never connects to Docker API or Docker Exec directly. Every container
terminal is routed through Galaxy API to the target node's `galaxy-agent`.

Container commands carry an explicit Swarm node id so node-local Docker
operations cannot accidentally fall back to the Manager:

```text
exec --org 29 --cluster 3 --node worker-node-id --container abcdef123456 --project 42
```

Run locally after starting the API:

```bash
export SSH_RELAY_INTERNAL_TOKEN='replace-with-a-random-secret'
go run .
```

The API must use the same token. A generated Ed25519 host key is stored at
`runtime/ssh/host_key`; persist that file in production so clients see a stable
host fingerprint.
