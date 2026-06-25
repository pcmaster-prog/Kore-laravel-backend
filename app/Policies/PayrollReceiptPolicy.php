<?php

namespace App\Policies;

use App\Models\PayrollReceipt;
use App\Models\User;

class PayrollReceiptPolicy
{
    public function view(User $user, PayrollReceipt $receipt): bool
    {
        if ($user->role === 'admin') {
            return true;
        }

        return $receipt->user_id === $user->id;
    }

    public function sign(User $user, PayrollReceipt $receipt): bool
    {
        return $receipt->user_id === $user->id && $receipt->status === 'pending';
    }

    public function viewAnyAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }
}
