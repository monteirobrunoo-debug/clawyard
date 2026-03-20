<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /** GET /conversations */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $prefix = 'u' . $userId . '_';

        $conversations = Conversation::where('session_id', 'like', $prefix . '%')
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->paginate(30);

        return view('conversations.index', compact('conversations'));
    }

    /** GET /conversations/{id} */
    public function show(Conversation $conversation)
    {
        $userId = auth()->id();
        $prefix = 'u' . $userId . '_';

        // Security: only owner can view
        abort_unless(str_starts_with($conversation->session_id, $prefix), 403);

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return view('conversations.show', compact('conversation', 'messages'));
    }

    /** GET /conversations/{id}/pdf */
    public function pdf(Conversation $conversation)
    {
        $userId = auth()->id();
        abort_unless(str_starts_with($conversation->session_id, 'u' . $userId . '_'), 403);

        $messages = $conversation->messages()->orderBy('created_at')->get();

        return view('conversations.pdf', compact('conversation', 'messages'));
    }

    /** DELETE /conversations/{id} */
    public function destroy(Conversation $conversation)
    {
        $userId = auth()->id();
        abort_unless(str_starts_with($conversation->session_id, 'u' . $userId . '_'), 403);

        $conversation->messages()->delete();
        $conversation->delete();

        return redirect()->route('conversations')->with('success', 'Conversa eliminada.');
    }
}
