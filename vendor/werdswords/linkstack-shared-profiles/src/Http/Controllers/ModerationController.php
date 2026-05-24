<?php

declare(strict_types=1);

namespace WerdsWords\LinkStack\SharedProfiles\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ModerationController extends Controller
{
    public function index(): View
    {
        $links = DB::table('links')
            ->join('buttons', 'buttons.id', '=', 'links.button_id')
            ->select('links.*', 'buttons.name as button_name')
            ->where('links.user_id', Auth::id())
            ->where('links.status', 'pending')
            ->orderBy('links.created_at', 'asc')
            ->get();

        return view('linkstack-shared-profiles::moderation.index', compact('links'));
    }

    public function approve(int $id): RedirectResponse
    {
        DB::table('links')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->update(['status' => 'published']);

        return back();
    }

    public function reject(int $id): RedirectResponse
    {
        DB::table('links')
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->update(['status' => 'rejected']);

        return back();
    }
}
