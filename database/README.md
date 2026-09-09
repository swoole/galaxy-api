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
