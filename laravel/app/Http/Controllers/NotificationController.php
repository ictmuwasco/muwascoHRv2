<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query();

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('is_read')) {
            $query->where('is_read', $request->is_read);
        }

        return response()->json($query->orderBy('created_at', 'desc')->paginate(15));
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $notification->update(['is_read' => true]);

        return response()->json($notification);
    }

    public function markAllAsRead(): JsonResponse
    {
        Notification::where('user_id', auth()->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['message' => 'All notifications marked as read']);
    }
}

