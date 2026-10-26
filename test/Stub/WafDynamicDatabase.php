<?php

namespace HyperfTest\Stub;

use Hyperf\Database\Connection;
use Hyperf\Database\Query\Builder;
use Hyperf\Database\Query\Grammars\Grammar;
use PDO;

/** One isolated schema for dynamic-control unit tests and the development HTTP fixture. */
final class WafDynamicDatabase
{
    public static function connect(string $path = ':memory:', bool $initialize = true): Connection
    {
        $db = new Connection(new PDO('sqlite:' . $path), $path, 'galaxy_');
        $db->setQueryGrammar(new class extends Grammar {
            public function compileInsertOrIgnore(Builder $query, array $values): string
            { return preg_replace('/^insert into /', 'insert or ignore into ', $this->compileInsert($query, $values)); }
            protected function wrapJsonSelector($value): string
            {
                $parts = explode('->', $value); $column = parent::wrap(array_shift($parts));
                return 'json_extract(' . $column . ", '$." . implode('.', $parts) . "')";
            }
            protected function compileJsonContains($column, $value): string
            { return 'exists (select 1 from json_each(' . $this->wrapJsonSelector($column) . ') where value = json_extract(' . $value . ", '$'))"; }
        });
        $db->setTablePrefix('galaxy_');
        if ($initialize) foreach ([
            'waf_policy' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, route_source TEXT, route_id INTEGER, settings TEXT, service_id TEXT DEFAULT "", service_name TEXT DEFAULT "", applied_mode TEXT DEFAULT "off", status TEXT DEFAULT "pending", error TEXT, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, synced_at INTEGER DEFAULT 0',
            'waf_runtime_config' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, config_hash TEXT, settings TEXT, created_at INTEGER, UNIQUE(policy_id,config_hash)',
            'waf_gateway_credential' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER UNIQUE, token TEXT, created_at INTEGER',
            'waf_ban' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, client_ip TEXT, config_hash TEXT, reason TEXT, events_count INTEGER, status TEXT, triggered_at INTEGER, expires_at INTEGER, released_at INTEGER, released_by INTEGER, UNIQUE(policy_id,client_ip)',
            'waf_signal' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, signal_key TEXT UNIQUE, client_ip TEXT, kind TEXT, occurred_at INTEGER',
            'waf_event' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, event_key TEXT UNIQUE, org_id INTEGER, cluster_id INTEGER, policy_id INTEGER, occurred_at INTEGER, client_ip TEXT, method TEXT, uri TEXT, action TEXT, status_code INTEGER, messages TEXT',
            'waf_data_source' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, org_id INTEGER, source_key TEXT, provider TEXT, kind TEXT, title TEXT, url TEXT DEFAULT "", license_url TEXT, attribution TEXT, enabled INTEGER DEFAULT 1, refresh_interval INTEGER DEFAULT 86400, max_age INTEGER DEFAULT 259200, config_version INTEGER DEFAULT 1, content_hash TEXT DEFAULT "", version TEXT DEFAULT "", published_at INTEGER DEFAULT 0, validated_at INTEGER DEFAULT 0, last_checked_at INTEGER DEFAULT 0, next_check_at INTEGER DEFAULT 0, updating_until INTEGER DEFAULT 0, record_count INTEGER DEFAULT 0, file_bytes INTEGER DEFAULT 0, last_error TEXT DEFAULT "", created_by INTEGER DEFAULT 0, updated_by INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, UNIQUE(org_id,source_key)',
        ] as $table => $columns) $db->statement('CREATE TABLE IF NOT EXISTS galaxy_' . $table . ' (' . $columns . ')');
        return $db;
    }
}
