<?php

namespace App\Auth;

use App\Enums\ClientStage;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent user provider for the `portal` guard and the `portal` password broker.
 *
 * It structurally constrains EVERY user retrieval (by id, by credentials, by
 * reset token) to people whose client is in the Active stage. This is the
 * durable mechanism that keeps prospect contacts out of:
 *   - the `login` attempt (Auth::guard('portal')->attempt)
 *   - the password-reset broker (sendResetLink / reset / createToken)
 * Both resolve their Person through this provider, so a prospect is simply never
 * found — no session is granted and no reset token is minted for them.
 *
 * Direct Person lookups that bypass the provider (sendAccessLink, verifyAccess,
 * and the staff invite/toggle/impersonate actions) are gated separately on
 * `client.stage`, and PortalAuthenticate re-checks it as defense-in-depth.
 */
class PortalUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)
            ->whereHas('client', fn (Builder $q) => $q->where('stage', ClientStage::Active));
    }
}
