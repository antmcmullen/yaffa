<?php

namespace App\Http\Controllers;

use App\Http\Requests\PiggyBankRequest;
use App\Models\PiggyBank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;
use Laracasts\Utilities\JavaScript\JavaScriptFacade as JavaScript;

class PiggyBankController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            ['auth', 'verified'],
            new Middleware('can:viewAny,App\Models\PiggyBank', only: ['index']),
            new Middleware('can:view,piggyBank', only: ['show']),
            new Middleware('can:create,App\Models\PiggyBank', only: ['create', 'store']),
            new Middleware('can:update,piggyBank', only: ['edit', 'update']),
            new Middleware('can:delete,piggyBank', only: ['destroy']),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $piggyBanks = $request->user()
            ->piggyBanks()
            ->select('id', 'name', 'target_amount', 'current_amount', 'target_date', 'active')
            ->get()
            ->append('percentage');

        JavaScript::put([
            'piggyBanks' => $piggyBanks,
        ]);

        return view('piggy-bank.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('piggy-bank.form');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(PiggyBankRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $request->user()->piggyBanks()->create($validated);

        self::addSimpleSuccessMessage(__('Piggy bank added'));

        return to_route('piggy-bank.index');
    }

    /**
     * Display the specified resource.
     */
    public function show(PiggyBank $piggyBank): View
    {
        return view('piggy-bank.show', ['piggyBank' => $piggyBank]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PiggyBank $piggyBank): View
    {
        return view('piggy-bank.form', ['piggyBank' => $piggyBank]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(PiggyBankRequest $request, PiggyBank $piggyBank): RedirectResponse
    {
        $validated = $request->validated();

        $piggyBank->fill($validated)->save();

        self::addSimpleSuccessMessage(__('Piggy bank updated'));

        return to_route('piggy-bank.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PiggyBank $piggyBank): RedirectResponse
    {
        $piggyBank->delete();

        self::addSimpleSuccessMessage(__('Piggy bank deleted'));

        return to_route('piggy-bank.index');
    }
}
