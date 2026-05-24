<?php

namespace WerdsWords\LinkStack\SharedProfiles\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use WerdsWords\LinkStack\SharedProfiles\Events\PendingLinkSubmitted;

class ApiLinkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        $rows = DB::table('links')
            ->where('user_id', $user->getKey())
            ->where('status', 'pending')
            ->orderBy('created_at', 'asc')
            ->get(['id', 'link', 'title', 'button_id', 'type_params', 'created_at']);

        $data = [];
        foreach ($rows as $row) {
            $data[] = [
                'id' => $row->id,
                'link' => $row->link,
                'title' => $row->title,
                'button_id' => $row->button_id,
                'meta' => is_string($row->type_params) ? json_decode($row->type_params, true) : null,
                'submitted_at' => $row->created_at,
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);

        $validated = $request->validate([
            'link' => 'required|url|max:2048',
            'title' => 'required|string|max:255',
            'button_id' => 'required|integer|exists:buttons,id',
            'meta' => 'sometimes|array',
        ]);

        $perUser = DB::table('users')->where('id', $user->getKey())->value('auto_approve');
        /** @var bool $autoApprove */
        $autoApprove = $perUser !== null
            ? $perUser
            : config('linkstack-shared-profiles.auto_approve');

        $status = $autoApprove ? 'published' : 'pending';

        $linkId = DB::table('links')->insertGetId([
            'user_id' => $user->getKey(),
            'link' => $validated['link'],
            'title' => $validated['title'],
            'button_id' => $validated['button_id'],
            'type' => 'predefined',
            'type_params' => isset($validated['meta']) ? json_encode($validated['meta']) : null,
            'status' => $status,
            'order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($status === 'pending') {
            /** @var int $profileId */
            $profileId = $user->getKey();
            event(new PendingLinkSubmitted($profileId, $linkId, $validated['link'], $validated['title']));
        }

        return response()->json(['status' => 'queued'], 201);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);

        $updated = DB::table('links')
            ->where('id', $id)
            ->where('user_id', $user->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'published', 'updated_at' => now()]);

        if (! $updated) {
            return response()->json(['error' => 'Link not found'], 404);
        }

        return response()->json(['status' => 'approved']);
    }

    public function deny(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);

        $deleted = DB::table('links')
            ->where('id', $id)
            ->where('user_id', $user->getKey())
            ->where('status', 'pending')
            ->delete();

        if (! $deleted) {
            return response()->json(['error' => 'Link not found'], 404);
        }

        return response()->json(['status' => 'denied']);
    }

    private function resolveUser(Request $request): Model
    {
        $token = $request->bearerToken();

        if (! $token) {
            abort(401, 'Unauthenticated.');
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('auth.providers.users.model');
        $user = $userModel::where('api_token', $token)->first();

        if (! $user) {
            abort(401, 'Invalid API token.');
        }

        return $user;
    }
}
