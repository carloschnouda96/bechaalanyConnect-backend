<?php

namespace App\Http\Controllers\Cms;

use App\CreditLedgerEntry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Credits\CreditLedger;
use App\Services\Credits\DuplicateLedgerEntryException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The one way staff change a balance by hand: a signed amount and a reason, written
 * through the ledger under the user row lock, so the change names who made it and why.
 *
 * Reached under /users/{id}/balance, so AdminMiddleware requires the Users page's edit
 * right for the PUT. The form's token is the idempotency key, so a double click or a
 * resubmitted page applies once.
 */
class UserBalanceController extends Controller
{
    private const HISTORY_ROWS = 25;

    public function show($id)
    {
        $user = User::findOrFail($id);

        return view('cms::pages/users/balance', [
            'user' => $user,
            'token' => (string) Str::uuid(),
            'entries' => CreditLedgerEntry::where('user_id', $user->id)
                ->latest('id')
                ->limit(self::HISTORY_ROWS)
                ->get(),
        ]);
    }

    public function adjust(Request $request, $id)
    {
        $data = $request->validate([
            'amount' => [
                'required',
                'regex:/^-?\d{1,9}(\.\d{1,2})?$/',
                function (string $attribute, $value, Closure $fail) {
                    if ((float) $value === 0.0) {
                        $fail('The amount cannot be zero.');
                    }
                },
            ],
            'reason' => ['required', 'string', 'max:500'],
            'token' => ['required', 'uuid'],
        ], [
            'amount.regex' => 'Enter an amount with at most 2 decimals, negative to remove credit.',
        ]);

        $amount = (float) $data['amount'];

        try {
            DB::transaction(function () use ($id, $amount, $data) {
                $user = User::where('id', $id)->lockForUpdate()->firstOrFail();

                if ($amount < 0 && (float) $user->credits_balance + $amount < 0) {
                    throw ValidationException::withMessages([
                        'amount' => 'This would take the balance below zero. Current balance: '
                            . number_format((float) $user->credits_balance, 3) . '.',
                    ]);
                }

                CreditLedger::record(
                    $user,
                    $amount,
                    CreditLedgerEntry::REASON_ADMIN_ADJUSTMENT,
                    null,
                    ['reason' => $data['reason']],
                    'admin-adjust:' . $data['token']
                );
            });
        } catch (DuplicateLedgerEntryException $e) {
            return back()->with('success', 'That adjustment was already applied.');
        }

        return back()->with('success', 'Balance adjusted.');
    }
}
