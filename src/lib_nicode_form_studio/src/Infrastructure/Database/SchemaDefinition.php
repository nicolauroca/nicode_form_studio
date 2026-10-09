<?php
declare(strict_types=1);
namespace Nicode\FormStudio\Infrastructure\Database;

/** Shared portable schema contract for generation and read-only diagnostics. */
final class SchemaDefinition
{
    public static function columnSqlType(string $column, string $type, bool $postgres): string
    {
        if ($column === 'instance_path') { return 'VARCHAR(4735)'; }
        if (!$postgres && in_array($column, ['option_value', 'value_keyword'], true)) { return 'VARBINARY(1020)'; }
        return ['uuid' => 'CHAR(36)', 'hash' => 'CHAR(64)', 'string' => 'VARCHAR(255)', 'short' => 'VARCHAR(64)', 'int' => 'INTEGER', 'bigint' => 'BIGINT', 'bool' => 'SMALLINT', 'text' => $postgres ? 'TEXT' : 'LONGTEXT', 'time' => $postgres ? 'TIMESTAMP(6) WITHOUT TIME ZONE' : 'DATETIME(6)', 'date' => 'DATE', 'decimal' => 'DECIMAL(38,12)'][rtrim($type, '!?')] ?? throw new \InvalidArgumentException('Unknown schema type.');
    }
    public static function tables(): array
    {
        return [
    'installation_state' => [
        'columns' => ['state_key' => 'string!', 'state_json' => 'text!'], 'unique' => [['state_key']],
    ],
    'forms' => [
        'columns' => ['uuid' => 'uuid!', 'name' => 'string!', 'alias' => 'string!', 'state' => 'short!', 'access' => 'int!', 'language' => 'short!', 'asset_id' => 'bigint?', 'created_at' => 'time!', 'created_by' => 'bigint!', 'modified_at' => 'time!', 'modified_by' => 'bigint!', 'publish_up' => 'time?', 'publish_down' => 'time?', 'draft_revision' => 'bigint!', 'published_version_id' => 'bigint?', 'params' => 'text!'],
        'unique' => [['uuid'], ['alias']], 'indexes' => [['state', 'modified_at', 'id'], ['published_version_id']],
    ],
    'elements' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'parent_uuid' => 'uuid?', 'element_type' => 'short!', 'ordering' => 'int!', 'properties' => 'text!'],
        'unique' => [['form_id', 'uuid']], 'indexes' => [['form_id', 'parent_uuid', 'ordering']], 'form_fk' => true,
    ],
    'fields' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'machine_name' => 'string!', 'field_type' => 'short!', 'logical_type' => 'short!', 'config' => 'text!', 'persist_value' => 'bool!', 'searchable' => 'bool!', 'filterable' => 'bool!', 'sortable' => 'bool!', 'sensitive' => 'bool!', 'include_email' => 'bool!', 'include_export' => 'bool!'],
        'unique' => [['form_id', 'uuid'], ['form_id', 'machine_name']], 'form_fk' => true,
    ],
    'field_options' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'field_uuid' => 'uuid!', 'option_value' => 'string!', 'label' => 'text!', 'ordering' => 'int!', 'enabled' => 'bool!', 'metadata' => 'text!'],
        'unique' => [['form_id', 'field_uuid', 'option_value']], 'indexes' => [['form_id', 'field_uuid', 'ordering']], 'form_fk' => true,
    ],
    'rules' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'enabled' => 'bool!', 'priority' => 'int!'],
        'unique' => [['form_id', 'uuid']], 'indexes' => [['form_id', 'priority']], 'form_fk' => true,
    ],
    'rule_conditions' => [
        'columns' => ['form_id' => 'bigint!', 'rule_uuid' => 'uuid!', 'definition' => 'text!'],
        'unique' => [['form_id', 'rule_uuid']], 'form_fk' => true,
    ],
    'rule_effects' => [
        'columns' => ['form_id' => 'bigint!', 'rule_uuid' => 'uuid!', 'ordering' => 'int!', 'definition' => 'text!'],
        'unique' => [['form_id', 'rule_uuid', 'ordering']], 'form_fk' => true,
    ],
    'actions' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'action_type' => 'short!', 'enabled' => 'bool!', 'ordering' => 'int!', 'condition_spec' => 'text?', 'config' => 'text!', 'failure_policy' => 'short!', 'retry_policy' => 'text!'],
        'unique' => [['form_id', 'uuid']], 'indexes' => [['form_id', 'ordering']], 'form_fk' => true,
    ],
    'option_sets' => [
        'columns' => ['uuid' => 'uuid!', 'name' => 'string!', 'revision' => 'bigint!', 'modified_at' => 'time!', 'modified_by' => 'bigint!'], 'unique' => [['uuid']],
    ],
    'option_set_versions' => [
        'columns' => ['option_set_id' => 'bigint!', 'revision' => 'bigint!', 'snapshot' => 'text!', 'hash' => 'hash!', 'created_at' => 'time!', 'created_by' => 'bigint!'], 'unique' => [['option_set_id', 'revision']],
    ],
    'option_set_items' => [
        'columns' => ['option_set_id' => 'bigint!', 'uuid' => 'uuid!', 'option_value' => 'string!', 'label' => 'text!', 'ordering' => 'int!', 'enabled' => 'bool!', 'metadata' => 'text!'], 'unique' => [['option_set_id', 'option_value']], 'indexes' => [['option_set_id', 'ordering']],
    ],
    'data_sources' => [
        'columns' => ['uuid' => 'uuid!', 'name' => 'string!', 'provider' => 'short!', 'provider_version' => 'short!', 'config' => 'text!', 'enabled' => 'bool!', 'revision' => 'bigint!'], 'unique' => [['uuid']],
    ],
    'email_templates' => [
        'columns' => ['uuid' => 'uuid!', 'name' => 'string!', 'language' => 'short!', 'subject' => 'text!', 'body_html' => 'text!', 'body_text' => 'text!', 'revision' => 'bigint!'], 'unique' => [['uuid']],
    ],
    'form_templates' => [
        'columns' => ['uuid' => 'uuid!', 'name' => 'string!', 'definition' => 'text!', 'revision' => 'bigint!'], 'unique' => [['uuid']],
    ],
    'translations' => [
        'columns' => ['form_id' => 'bigint!', 'entity_uuid' => 'uuid!', 'language' => 'short!', 'property_name' => 'short!', 'translated_value' => 'text!'], 'unique' => [['form_id', 'entity_uuid', 'language', 'property_name']], 'form_fk' => true,
    ],
    'form_versions' => [
        'columns' => ['form_id' => 'bigint!', 'revision' => 'bigint!', 'schema_version' => 'short!', 'spec' => 'text!', 'hash' => 'hash!', 'published_at' => 'time!', 'published_by' => 'bigint!', 'comment' => 'text!', 'revoked_at' => 'time?'], 'unique' => [['form_id', 'revision']], 'form_fk' => true,
    ],
    'version_field_policy' => [
        'columns' => ['form_id' => 'bigint!', 'form_version_id' => 'bigint!', 'field_uuid' => 'uuid!', 'sensitive' => 'bool!', 'indexed' => 'bool!'], 'unique' => [['form_version_id', 'field_uuid']], 'indexes' => [['form_id', 'field_uuid', 'sensitive', 'form_version_id']], 'form_fk' => true,
    ],
    'submissions' => [
        'columns' => ['uuid' => 'uuid!', 'form_id' => 'bigint!', 'form_version_id' => 'bigint!', 'state' => 'short!', 'received_at' => 'time!', 'processed_at' => 'time?', 'user_id' => 'bigint?', 'channel' => 'short!', 'locale' => 'short!', 'canonical_payload' => 'text!', 'payload_schema_version' => 'short!', 'action_status' => 'short!', 'expires_at' => 'time?', 'anonymized_at' => 'time?', 'attempt_hash' => 'hash!', 'index_pending' => 'bool!'],
        'unique' => [['uuid'], ['form_id', 'attempt_hash']], 'indexes' => [['received_at', 'id'], ['form_id', 'received_at', 'id'], ['form_id', 'state', 'received_at', 'id'], ['form_version_id', 'received_at', 'id'], ['user_id', 'received_at', 'id'], ['action_status', 'received_at', 'id'], ['expires_at', 'id'], ['index_pending', 'id'], ['form_id', 'id']], 'form_fk' => true,
    ],
    'submission_index' => [
        'columns' => ['submission_id' => 'bigint!', 'form_id' => 'bigint!', 'form_version_id' => 'bigint!', 'field_uuid' => 'uuid!', 'instance_path' => 'string!', 'instance_hash' => 'hash!', 'value_type' => 'short!', 'value_keyword' => 'string?', 'value_text' => 'text?', 'value_integer' => 'bigint?', 'value_decimal' => 'decimal?', 'value_boolean' => 'bool?', 'value_date' => 'date?', 'value_datetime' => 'time?', 'ordinal' => 'int!'],
        'unique' => [['submission_id', 'field_uuid', 'instance_hash', 'ordinal']], 'indexes' => array_map(static fn ($type) => ['form_id', 'field_uuid', 'value_' . $type, 'submission_id'], ['keyword', 'integer', 'decimal', 'boolean', 'date', 'datetime']), 'submission_fk' => true,
    ],
    'submission_files' => [
        'columns' => ['uuid' => 'uuid!', 'submission_id' => 'bigint!', 'field_uuid' => 'uuid!', 'instance_path' => 'string!', 'instance_hash' => 'hash!', 'provider' => 'short!', 'storage_key' => 'string!', 'original_name' => 'string!', 'mime' => 'string!', 'size_bytes' => 'bigint!', 'checksum' => 'hash!', 'created_at' => 'time!'], 'unique' => [['uuid'], ['provider', 'storage_key']], 'indexes' => [['submission_id']], 'submission_fk' => true,
    ],
    'upload_staging' => [
        'columns' => ['form_id' => 'bigint!', 'provider' => 'short!', 'storage_key' => 'string!', 'owner_token' => 'hash!', 'state' => 'short!', 'created_at' => 'time!', 'expires_at' => 'time!', 'size_bytes' => 'bigint?', 'checksum' => 'hash?'],
        'unique' => [['provider', 'storage_key']], 'indexes' => [['expires_at', 'id'], ['form_id', 'id']],
    ],
    'action_runs' => [
        'columns' => ['submission_id' => 'bigint!', 'action_uuid' => 'uuid!', 'action_type' => 'short!', 'attempt' => 'int!', 'state' => 'short!', 'created_at' => 'time!', 'started_at' => 'time?', 'finished_at' => 'time?', 'result_code' => 'short?', 'next_retry_at' => 'time?', 'lease_token' => 'hash?', 'lease_until' => 'time?', 'revision' => 'bigint!'],
        'unique' => [['submission_id', 'action_uuid', 'attempt']], 'indexes' => [['state', 'next_retry_at', 'id'], ['submission_id', 'id'], ['created_at', 'id']], 'submission_fk' => true,
    ],
    'submission_notes' => [
        'columns' => ['submission_id' => 'bigint!', 'created_by' => 'bigint!', 'created_at' => 'time!', 'body' => 'text!'], 'indexes' => [['submission_id', 'id']], 'submission_fk' => true,
    ],
    'technical_log' => [
        'columns' => ['correlation_id' => 'uuid!', 'level' => 'short!', 'event_type' => 'short!', 'form_uuid' => 'uuid?', 'version_id' => 'bigint?', 'submission_uuid' => 'uuid?', 'action_run_id' => 'bigint?', 'job_id' => 'bigint?', 'created_at' => 'time!'],
        'indexes' => [['created_at', 'id'], ['correlation_id', 'id'], ['level', 'id']],
    ],
    'audit_log' => [
        'columns' => ['correlation_id' => 'uuid!', 'actor_id' => 'bigint!', 'event_type' => 'short!', 'form_id' => 'bigint?', 'submission_uuid' => 'uuid?', 'created_at' => 'time!', 'safe_metadata' => 'text!'], 'indexes' => [['form_id', 'created_at', 'id'], ['event_type', 'created_at', 'id'], ['form_id', 'submission_uuid', 'id'], ['created_at', 'id']],
    ],
    'jobs' => [
        'columns' => ['uuid' => 'uuid!', 'job_type' => 'short!', 'form_id' => 'bigint?', 'creator_id' => 'bigint!', 'state' => 'short!', 'parameters' => 'text!', 'cursor_data' => 'text!', 'processed' => 'bigint!', 'failed' => 'bigint!', 'created_at' => 'time!', 'started_at' => 'time?', 'finished_at' => 'time?', 'available_at' => 'time!', 'lease_token' => 'hash?', 'lease_until' => 'time?', 'revision' => 'bigint!', 'result_code' => 'short?', 'artifact_key' => 'string?', 'expires_at' => 'time?'], 'unique' => [['uuid']], 'indexes' => [['state', 'available_at', 'id'], ['lease_until', 'id'], ['creator_id', 'created_at', 'id'], ['form_id', 'job_type', 'state', 'id'], ['job_type', 'id']],
    ],
    'saved_views' => [
        'columns' => ['uuid' => 'uuid!', 'owner_id' => 'bigint!', 'form_id' => 'bigint?', 'name' => 'string!', 'shared' => 'bool!', 'query_spec' => 'text!', 'columns_spec' => 'text!', 'sort_spec' => 'text!'], 'unique' => [['uuid']], 'indexes' => [['owner_id', 'form_id']],
    ],
    'rate_limits' => [
        'columns' => ['scope_hash' => 'hash!', 'window_start' => 'time!', 'expires_at' => 'time!', 'attempts' => 'bigint!'], 'unique' => [['scope_hash', 'window_start']], 'indexes' => [['expires_at']],
    ],
    'attempts' => [
        'columns' => ['attempt_hash' => 'hash!', 'form_id' => 'bigint!', 'form_version_id' => 'bigint!', 'request_hash' => 'hash!', 'state' => 'short!', 'response' => 'text?', 'submission_uuid' => 'uuid?', 'created_at' => 'time!', 'expires_at' => 'time!'], 'unique' => [['form_id', 'attempt_hash']], 'indexes' => [['expires_at']],
    ],
];
    }
}
