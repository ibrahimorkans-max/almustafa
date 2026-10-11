<?php

declare(strict_types=1);

namespace App\Policies\Hr;

use App\Models\LoginAccount;
use Illuminate\Database\Eloquent\Model;

/** Staff claims: seen by HR, supervisors and accounts; changed only through their workflow actions. */
class ReimbursementPolicy
{
    /** @var list<string> */
    protected array $viewers = ['hr.payroll.run', 'hr.attendance.review', 'ledger.post', 'payment.create'];

    /** @var list<string> */
    protected array $creators = ['hr.payroll.run'];

    public function viewAny(LoginAccount $user): bool
    {
        return array_any($this->viewers, fn (string $p) => $user->hasPermission($p));
    }

    public function view(LoginAccount $user, Model $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(LoginAccount $user): bool
    {
        return array_any($this->creators, fn (string $p) => $user->hasPermission($p));
    }

    public function update(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function delete(LoginAccount $user, Model $record): bool
    {
        return false;
    }

    public function deleteAny(LoginAccount $user): bool
    {
        return false;
    }
}
