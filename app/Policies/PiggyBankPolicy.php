<?php

namespace App\Policies;

use App\Models\PiggyBank;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PiggyBankPolicy
{
    use HandlesAuthorization;

    public function isOwnItem(User $user, PiggyBank $piggyBank): bool
    {
        return $user->id === $piggyBank->user_id;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, PiggyBank $piggyBank): bool
    {
        return $this->isOwnItem($user, $piggyBank);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, PiggyBank $piggyBank): bool
    {
        return $this->isOwnItem($user, $piggyBank);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, PiggyBank $piggyBank): bool
    {
        return $this->isOwnItem($user, $piggyBank);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, PiggyBank $piggyBank): bool
    {
        return $this->isOwnItem($user, $piggyBank);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, PiggyBank $piggyBank): bool
    {
        return $this->isOwnItem($user, $piggyBank);
    }
}
