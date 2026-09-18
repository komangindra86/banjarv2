<?php

namespace App\Http\Controllers;

use App\Modules\Notification\NotificationManager;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(private NotificationManager $manager) {}

    public function index(Request $request)
    {
        $notifications = $this->manager->visible($request->user())->orderByDesc('id')->paginate(20);

        return view('notifications.index', [
            'notifications' => $notifications,
            'vapidPublicKey' => config('webpush.enabled') ? config('webpush.public_key') : null,
        ]);
    }

    public function read(Request $request, int $id)
    {
        $notification = $this->manager->markRead($request->user(), $id);

        return $notification->url ? redirect($notification->url) : back();
    }

    public function readAll(Request $request)
    {
        $this->manager->markAllRead($request->user());

        return back()->with('success', 'Semua notifikasi ditandai dibaca.');
    }

    public function subscribe(Request $request)
    {
        $this->manager->subscribe($request->user(), $request->all());

        return response()->noContent();
    }

    public function unsubscribe(Request $request)
    {
        $request->validate(['endpoint' => ['required', 'string']]);
        $this->manager->unsubscribe($request->user(), (string) $request->input('endpoint'));

        return response()->noContent();
    }
}
