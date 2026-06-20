<?php

namespace App\Policies\Api;

use App\Enums\ApiToken;
use App\Enums\ModelAttribute;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesUserApiActions
{
    protected function userCanAccessModel(User $user, Model $model): bool
    {
        if ($model->user_id === $user->id) {
            return true;
        }
        if ($user->isSystemUser()) {
            if ($model->visibility === ModelAttribute::VISIBILITY_PRIVATE) {
                return $user->tokenCan($this->readAbility) && $user->tokenCan(ApiToken::ABILITY_SYSTEM_ACCESS_PRIVATE);
            }
            return $user->tokenCan($this->readAbility);
        }
        return $model->visibility !== ModelAttribute::VISIBILITY_PRIVATE;
    }

    protected function userCanUpdateModel(User $user, Model $model): bool
    {
        if ($model->user_id === $user->id) {
            return true;
        }
        if ($user->isSystemUser()) {
            if ($model->visibility === ModelAttribute::VISIBILITY_PRIVATE) {
                return $user->tokenCan($this->updateAbility) && $user->tokenCan(ApiToken::ABILITY_SYSTEM_ACCESS_PRIVATE);
            }
            return $user->tokenCan($this->updateAbility);
        }
        return false;
    }

    protected function userCanDeleteModel(User $user, Model $model): bool
    {
        if ($model->user_id === $user->id) {
            return true;
        }
        if ($user->isSystemUser()) {
            if ($model->visibility === ModelAttribute::VISIBILITY_PRIVATE) {
                return $user->tokenCan($this->deleteAbility) && $user->tokenCan(ApiToken::ABILITY_SYSTEM_ACCESS_PRIVATE);
            }
            return $user->tokenCan($this->deleteAbility);
        }
        return false;
    }
}
