<?php

namespace JarredCain\CanvasLms\Models;

use JarredCain\CanvasLms\Relations\HasMany;

/**
 * A Canvas subaccount — structurally identical to Account but accessed via
 * GET /api/v1/accounts/:parent_id/sub_accounts.
 *
 * @property string      $id
 * @property string      $name
 * @property string|null $uuid
 * @property string|null $parent_account_id
 * @property string|null $root_account_id
 * @property string      $workflow_state
 * @property string|null $default_time_zone
 * @property string|null $sis_account_id
 * @property string|null $integration_id
 * @property \Carbon\Carbon|null $created_at
 */
class SubAccount extends CanvasModel
{
    protected static string $endpoint = 'sub_accounts';

    protected static bool $requiresContext = true;

    protected array $casts = [
        'id'                => 'string',
        'parent_account_id' => 'string',
        'root_account_id'   => 'string',
        'created_at'        => 'datetime',
    ];

    /**
     * When used as a HasMany parent, route through accounts/:id not sub_accounts/:id.
     * Canvas subaccounts share the same /accounts/:id/* URL space as root accounts.
     */
    public static function getRelationshipEndpoint(): string
    {
        return 'accounts';
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function subAccounts(): HasMany
    {
        return $this->hasMany(SubAccount::class);
    }
}
