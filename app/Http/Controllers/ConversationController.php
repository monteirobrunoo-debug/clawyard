<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /** GET /conversations — paginated history of THIS user's conversations.
     *
     * Supports two query params for the "users complain they can't find
     * their history" case (2026-04-27):
     *   ?q=<text>      — full-text search over the user's messages,
     *                    returning conversations that contain matching
     *                    text. Case-insensitive LIKE — fine for the
     *                    typical small-to-medium per-user history.
     *   ?agent=<key>   — restrict to conversations with one specific
     *                    agent.
     *
     * Both filters are scoped within the user's own session prefix —
     * they can never see anyone else's conversations.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();
        $prefix = 'u' . $userId . '_';

        $q     = trim((string) $request->input('q', ''));
        $agent = trim((string) $request->input('agent', ''));

        $base = Conversation::query()
            ->where('session_id', 'like', $prefix . '%');

        // Filter by agent if provided. The agent column on conversations
        // is set when the chat starts; legacy rows might have it null.
        if ($agent !== '') {
            $base->where('agent', $agent);
        }

        // Full-text search runs in PHP, NOT SQL — Message::content is
        // SafeEncryptedString cast, so the on-disk column is ciphertext.
        // SQL LIKE would scan ciphertext and never match. We pull the
        // user's matching-agent conversations, eager-load messages
        // (auto-decrypt via the cast), and keep only those whose any
        // message body contains the needle (case-insensitive).
        //
        // Cost: O(N×M) per search where N=conversations, M=avg msgs/conv.
        // For a typical per-user history (<1000 messages) this is fine.
        // If history grows huge later, swap to a dedicated search index
        // table that's populated on message-create and stores a one-way
        // searchable digest.
        if ($q !== '') {
            $needle = mb_strtolower($q);
            $matches = $base
                ->with('messages:id,conversation_id,content')
                ->get();
            $matchingIds = $matches
                ->filter(function ($conv) use ($needle) {
                    foreach ($conv->messages as $m) {
                        if (str_contains(mb_strtolower((string) $m->content), $needle)) {
                            return true;
                        }
                    }
                    return false;
                })
                ->pluck('id')
                ->all();

            // Re-narrow the query so pagination still flows through SQL.
            $base = Conversation::query()
                ->whereIn('id', $matchingIds);
        }

        $conversations = $base
            ->withCount('messages')
            ->orderBy('updated_at', 'desc')
            ->paginate(30)
            ->withQueryString();

        // For the agent-filter dropdown — list every distinct agent
        // the user has ever conversed with. Cheap query (small table
        // per user). NULL agent values become '' in the dropdown.
        $userAgents = Conversation::query()
            ->where('session_id', 'like', $prefix . '%')
            ->whereNotNull('agent')
            ->where('agent', '!=', '')
            ->select('agent')
            ->distinct()
            ->orderBy('agent')
            ->pluck('agent')
            ->all();

        return view('conversations.index', [
            'conversations' => $conversations,
            'q'             => $q,
            'agent'         => $agent,
            'userAgents'    => $userAgents,
        ]);
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
