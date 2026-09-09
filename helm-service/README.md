# Galaxy API private Helm service

`helm-service` is an internal subservice of `galaxy-api`. It embeds the Helm
SDK so application-market installations use Helm's real rendering, hooks,
CRD, release history, upgrade and rollback semantics instead of reimplementing
them with individual Kubernetes API calls.

The service:

- listens on loopback by default;
- requires an internal bearer token;
- accepts kubeconfig only for the lifetime of a request;
- writes kubeconfig to a mode `0600` temporary file and always removes it;
- never logs kubeconfig, registry credentials or chart values;
- is not distributed with `galaxy-cli`.

Local development:

```bash
export HELM_SERVICE_INTERNAL_TOKEN='replace-with-a-random-secret'
go run .
```

Production starts the compiled binary from `docker/entrypoint-api.sh`.
