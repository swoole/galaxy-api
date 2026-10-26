# Database initialization

Run these commands from the `galaxy-api` repository root.

`database/init.sql` is the canonical MySQL 8 schema for a new Galaxy database.
It contains DDL for all current tables and indexes, but no application data,
credentials, demo records, or historical migration state.

Create an empty database and import the schema:

```bash
mysql -uroot -p -h127.0.0.1 \
    -e "CREATE DATABASE galaxy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot -p -h127.0.0.1 galaxy < database/init.sql
```

The initialization file is for empty databases. It does not upgrade an
existing installation. Schema changes for a running installation must be
applied deliberately before regenerating `database/init.sql` from the verified
final structure.

Run the static query-column audit after changing application queries or the
schema:

```bash
php database/audit_query_columns.php
```

The audit is deliberately conservative. Relationship projections and SQL
aliases can be reported against the initiating model and require source review.

The optional gateway WAF module adds `galaxy_waf_policy` and `galaxy_waf_event`.
For an existing installation, apply `migrations/0060_add_waf.sql` before enabling
`WAF_ENABLED`. Also apply `migrations/0064_add_waf_gateway_credentials.sql`
before deploying or synchronizing any WAF policy. The migrations are additive and idempotent; they do not change
existing gateways, routes, or application data. Adapt the table prefix when
using a prefix other than `galaxy_`.

Policy history and security alerts additionally require
`migrations/0061_add_waf_operations.sql`. This creates
`galaxy_waf_policy_revision` and `galaxy_waf_alert`; both are included in the
new-database schema. Existing WAF protection continues to work while this
migration is pending. Policy history starts on the next save and captures the
previous configuration as a baseline; it does not invent older history.

Dynamic WAF checks and automatic temporary bans require
`migrations/0062_add_waf_dynamic_control.sql`. It adds immutable control snapshots,
automatic-ban records, and short-lived deduplicated behavior signals. Configure
`WAF_CONTROL_URL` and a dedicated `WAF_CONTROL_SECRET` before enabling the feature;
no existing policy is enabled automatically. The schema is included in `init.sql`.

Shared GeoIP and IP intelligence sources require
`migrations/0063_add_waf_data_sources.sql`, the dynamic checks above, and a shared
persistent `WAF_DATA_DIRECTORY` mounted into every API instance with the same
read/write UID. Organization administrators manage source configuration; project
directors select shared sources in their own policies. The background updater
validates and atomically publishes immutable data revisions, preserves attribution
and enforces provider cooldowns. No provider data or download credentials are
shipped in the schema.

`migrations/0064_add_waf_gateway_credentials.sql` stores stable, per-policy
gateway-to-WAF request credentials. This is required for new deployments and
synchronizations, including policies without dynamic features. Existing instances
keep running; synchronize them after applying the migration to enable ingress
authentication. Secrets are never included in policy exports or forwarded to the
origin. Both new tables are included in `init.sql`.
