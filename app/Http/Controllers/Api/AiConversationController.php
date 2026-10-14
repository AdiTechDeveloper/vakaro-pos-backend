<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use Illuminate\Http\Request;

class AiConversationController extends Controller
{
    /**
     * List the authenticated user's conversations.
     */
    public function index(Request $request)
    {
        $conversations = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->paginate(30);

        return response()->json([
            'status' => true,
            'data' => $conversations,
        ]);
    }

    /**
     * Create a new conversation.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'integer', 'min:0'],
        ]);

        $branchId = $validated['branch_id'] ?? null;

        // Admin's "All branches" selection.
        if ((int) $branchId === 0) {
            $branchId = null;
        }

        $conversation = AiConversation::create([
            'user_id' => $request->user()->id,
            'branch_id' => $branchId,
            'title' => $validated['title'] ?? 'New chat',
            'last_message_at' => null,
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Conversation created.',
            'data' => $conversation,
        ], 201);
    }

    /**
     * Load one conversation and its messages.
     */
    public function show(Request $request, int $id)
    {
        $conversation = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->with('messages')
            ->firstOrFail();

        return response()->json([
            'status' => true,
            'data' => $conversation,
        ]);
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(Request $request, int $id)
    {
        $conversation = AiConversation::query()
            ->where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $conversation->delete();

        return response()->json([
            'status' => true,
            'message' => 'Conversation deleted.',
        ]);
    }
}
