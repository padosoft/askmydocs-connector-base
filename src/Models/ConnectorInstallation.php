<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorBase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Padosoft\AskMyDocsConnectorBase\Models\Concerns\BelongsToTenant;
use Padosoft\AskMyDocsConnectorBase\Support\TenantContext;

/**
 * Connector installation row.
 *
 * Represents one (tenant_id, connector_name, label) installation —
 * a tenant may install the same connector multiple times under
 * distinct labels (e.g. "support" + "sales" IMAP mailboxes). The
 * companion
 * {@see ConnectorCredential} carries the encrypted OAuth tokens; this
 * model carries the operational state (status, last_sync_at,
 * error_json, per-connector config).
 *
 * R31 — `tenant_id` auto-fills from
 *       {@see TenantContext}
 *       on `creating` via the {@see BelongsToTenant} trait.
 * R30 — every read query MUST scope by tenant via `forTenant($id)`
 *       (the trait scope) or an explicit `where('tenant_id', ...)`.
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $connector_name
 * @property string $label Account discriminator within a connector (default 'default')
 * @property string|null $project_key Optional KB project binding; null inherits the tenant default
 * @property array<string,mixed>|null $config_json
 * @property string $status One of pending|active|disabled|errored
 * @property Carbon|null $last_sync_at
 * @property array<string,mixed>|null $error_json
 * @property int|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ConnectorInstallation extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_ERRORED = 'errored';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_DISABLED,
        self::STATUS_ERRORED,
    ];

    protected $table = 'connector_installations';

    protected $fillable = [
        'tenant_id',
        'connector_name',
        'label',
        'project_key',
        'config_json',
        'status',
        'last_sync_at',
        'error_json',
        'created_by',
    ];

    protected $casts = [
        'config_json' => 'array',
        'error_json' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function credential(): HasOne
    {
        return $this->hasOne(ConnectorCredential::class, 'connector_installation_id');
    }
}
