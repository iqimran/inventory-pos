<?php

namespace App\Http\Controllers\Parties;

use App\Actions\Parties\RecordLedgerAdjustment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\LedgerAdjustmentRequest;
use App\Models\Party;
use Illuminate\Http\RedirectResponse;

class PartyLedgerAdjustmentController extends Controller
{
    public function store(LedgerAdjustmentRequest $request, Party $party, RecordLedgerAdjustment $adjust): RedirectResponse
    {
        $adjust->handle($party, $request->validated('side'), (string) $request->validated('amount'), $request->validated('reason'));

        return back()->with('success', 'Ledger adjustment recorded.');
    }
}
