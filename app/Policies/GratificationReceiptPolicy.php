<?php

namespace App\Policies;

use App\Models\GratificationReceipt;
use App\Models\User;

class GratificationReceiptPolicy
{
    public function view(User $user, GratificationReceipt $receipt): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        return $receipt->user_id === $user->id;
    }

    public function sign(User $user, GratificationReceipt $receipt): bool
    {
        return $receipt->user_id === $user->id && $receipt->status === 'approved';
    }

    public function viewAnyAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }
}
