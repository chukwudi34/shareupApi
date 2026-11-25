<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', $request->user()->id)->latest();

        return response()->json([
            'data' => $query->limit(60)->get()->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'title' => $notification->title,
                'body' => $notification->body,
                'kind' => $notification->kind,
                'href' => $notification->href,
                'readAt' => Presenter::date($notification->read_at),
                'createdAt' => Presenter::date($notification->created_at),
            ]),
            'meta' => [
                'unread' => Notification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
            ],
        ]);
    }

    public function read(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['message' => 'Marked as read.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications read.']);
    }
}
