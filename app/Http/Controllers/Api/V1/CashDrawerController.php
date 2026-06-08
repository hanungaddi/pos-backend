<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\CashDrawerSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashDrawerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CashDrawerSession::query()->with(['user', 'closedBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('from')) {
            $query->whereDate('opened_at', '>=', $request->date('from')->toDateString());
        }

        if ($request->filled('to')) {
            $query->whereDate('opened_at', '<=', $request->date('to')->toDateString());
        }

        $sortBy = $request->input('sort_by', 'opened_at');
        $sortOrder = strtolower($request->input('sort_order', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSortColumns = ['opened_at', 'closed_at', 'expected_cash', 'difference', 'status'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->latest('opened_at');
        }

        return $this->responsePaginated($query->paginate($request->integer('per_page', 15)));
    }

    public function current(Request $request): JsonResponse
    {
        $session = CashDrawerSession::query()
            ->with(['movements' => fn ($query) => $query->latest()->limit(25), 'user'])
            ->open()
            ->where('user_id', $request->user()->id)
            ->first();

        return $this->responseSuccess($session, 'Status cash drawer berhasil dimuat.');
    }

    public function show(Request $request, CashDrawerSession $session): JsonResponse
    {
        $this->authorizeView($session, $request->user());

        return $this->responseSuccess(
            $session->load(['user', 'closedBy', 'movements.user', 'transactions.items']),
            'Detail cash drawer berhasil dimuat.'
        );
    }

    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'opening_balance' => ['required', 'integer', 'min:0'],
            'opening_note' => ['nullable', 'string', 'max:500'],
        ]);

        $session = DB::transaction(function () use ($validated, $request) {
            $user = $request->user();

            $existingSession = CashDrawerSession::query()
                ->open()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingSession) {
                throw ValidationException::withMessages([
                    'cash_drawer' => ['Masih ada cash drawer yang sedang terbuka untuk kasir ini.'],
                ]);
            }

            $openingBalance = (int) $validated['opening_balance'];

            $session = CashDrawerSession::create([
                'store_id' => $user->store_id,
                'user_id' => $user->id,
                'opening_balance' => $openingBalance,
                'expected_cash' => $openingBalance,
                'status' => 'open',
                'opening_note' => $validated['opening_note'] ?? null,
                'opened_at' => now(),
            ]);

            $session->recordMovement(
                'opening',
                $openingBalance,
                0,
                $openingBalance,
                $user,
                $validated['opening_note'] ?? 'Saldo awal cash drawer.'
            );

            return $session->load(['user', 'movements']);
        });

        ActivityLog::log('open_cash_drawer', "Cash drawer #{$session->id} was opened.", $session, [
            'opening_balance' => $session->opening_balance,
        ]);

        return $this->responseSuccess($session, 'Cash drawer berhasil dibuka.', 201);
    }

    public function cashIn(Request $request, CashDrawerSession $session): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $session = DB::transaction(function () use ($validated, $request, $session) {
            $lockedSession = $this->lockOpenSession($session);
            $this->authorizeOperate($lockedSession, $request->user());

            $amount = (int) $validated['amount'];
            $balanceBefore = $lockedSession->expected_cash;
            $balanceAfter = $balanceBefore + $amount;

            $lockedSession->update([
                'expected_cash' => $balanceAfter,
                'cash_in_total' => $lockedSession->cash_in_total + $amount,
            ]);

            $lockedSession->recordMovement(
                'cash_in',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $request->user(),
                $validated['note'] ?? null
            );

            return $lockedSession->fresh(['user', 'movements']);
        });

        ActivityLog::log('cash_drawer_cash_in', "Cash drawer #{$session->id} received cash in.", $session, [
            'amount' => (int) $validated['amount'],
        ]);

        return $this->responseSuccess($session, 'Cash in berhasil dicatat.');
    }

    public function cashOut(Request $request, CashDrawerSession $session): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:500'],
        ]);

        $session = DB::transaction(function () use ($validated, $request, $session) {
            $lockedSession = $this->lockOpenSession($session);
            $this->authorizeOperate($lockedSession, $request->user());

            $amount = (int) $validated['amount'];
            $balanceBefore = $lockedSession->expected_cash;

            if ($amount > $balanceBefore) {
                throw ValidationException::withMessages([
                    'amount' => ['Nominal cash out melebihi saldo kas yang diharapkan.'],
                ]);
            }

            $balanceAfter = $balanceBefore - $amount;

            $lockedSession->update([
                'expected_cash' => $balanceAfter,
                'cash_out_total' => $lockedSession->cash_out_total + $amount,
            ]);

            $lockedSession->recordMovement(
                'cash_out',
                $amount,
                $balanceBefore,
                $balanceAfter,
                $request->user(),
                $validated['note']
            );

            return $lockedSession->fresh(['user', 'movements']);
        });

        ActivityLog::log('cash_drawer_cash_out', "Cash drawer #{$session->id} paid cash out.", $session, [
            'amount' => (int) $validated['amount'],
        ]);

        return $this->responseSuccess($session, 'Cash out berhasil dicatat.');
    }

    public function close(Request $request, CashDrawerSession $session): JsonResponse
    {
        $validated = $request->validate([
            'actual_closing_balance' => ['required', 'integer', 'min:0'],
            'closing_note' => ['nullable', 'string', 'max:500'],
        ]);

        $session = DB::transaction(function () use ($validated, $request, $session) {
            $lockedSession = $this->lockOpenSession($session);
            $this->authorizeOperate($lockedSession, $request->user());

            $actualClosingBalance = (int) $validated['actual_closing_balance'];
            $expectedCash = $lockedSession->expected_cash;
            $difference = $actualClosingBalance - $expectedCash;

            $lockedSession->update([
                'actual_closing_balance' => $actualClosingBalance,
                'difference' => $difference,
                'status' => 'closed',
                'closing_note' => $validated['closing_note'] ?? null,
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
            ]);

            $lockedSession->recordMovement(
                'close',
                $actualClosingBalance,
                $expectedCash,
                $actualClosingBalance,
                $request->user(),
                $validated['closing_note'] ?? 'Cash drawer ditutup.'
            );

            return $lockedSession->fresh(['user', 'closedBy', 'movements']);
        });

        ActivityLog::log('close_cash_drawer', "Cash drawer #{$session->id} was closed.", $session, [
            'expected_cash' => $session->expected_cash,
            'actual_closing_balance' => (int) $validated['actual_closing_balance'],
            'difference' => $session->difference,
        ]);

        return $this->responseSuccess($session, 'Cash drawer berhasil ditutup.');
    }

    private function lockOpenSession(CashDrawerSession $session): CashDrawerSession
    {
        $lockedSession = CashDrawerSession::query()
            ->whereKey($session->id)
            ->lockForUpdate()
            ->first();

        if (!$lockedSession || $lockedSession->status !== 'open') {
            throw ValidationException::withMessages([
                'cash_drawer' => ['Cash drawer tidak ditemukan atau sudah ditutup.'],
            ]);
        }

        return $lockedSession;
    }

    private function authorizeOperate(CashDrawerSession $session, User $user): void
    {
        if ($session->user_id === $user->id || $user->can('manage_cash_drawer')) {
            return;
        }

        abort(403, 'Anda tidak memiliki akses ke cash drawer ini.');
    }

    private function authorizeView(CashDrawerSession $session, User $user): void
    {
        if ($session->user_id === $user->id || $user->can('view_cash_drawer')) {
            return;
        }

        abort(403, 'Anda tidak memiliki akses ke cash drawer ini.');
    }
}
