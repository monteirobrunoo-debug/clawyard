<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    // Users list
    public function users(Request $request)
    {
        $query = User::withCount('conversations')->latest();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        $users = $query->paginate(20);

        return view('admin.users', compact('users'));
    }

    // Create user
    public function createUser(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:8',
            'role'     => 'required|in:admin,manager,user,guest',
        ]);

        User::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => $request->role,
            'is_active' => true,
        ]);

        return back()->with('success', 'Utilizador criado com sucesso!');
    }

    // Update user
    public function updateUser(Request $request, User $user)
    {
        $request->validate([
            'name'  => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'role'  => 'required|in:admin,manager,user,guest',
        ]);

        $data = [
            'name'      => $request->name,
            'email'     => $request->email,
            'role'      => $request->role,
            'is_active' => $request->boolean('is_active'),
        ];

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return back()->with('success', 'Utilizador atualizado!');
    }

    // Toggle active
    public function toggleUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Nao pode desativar a sua propria conta.']);
        }

        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', $user->is_active ? 'Utilizador ativado!' : 'Utilizador bloqueado!');
    }

    // Delete user
    public function deleteUser(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['error' => 'Nao pode apagar a sua propria conta.']);
        }

        $user->delete();

        return back()->with('success', 'Utilizador removido!');
    }

    // View conversations
    public function conversations(Request $request)
    {
        $conversations = Conversation::with(['messages'])
            ->latest()
            ->paginate(30);

        return view('admin.conversations', compact('conversations'));
    }

    // View single conversation
    public function conversation(Conversation $conversation)
    {
        $conversation->load('messages');
        return view('admin.conversation', compact('conversation'));
    }

    // Stats
    public function stats()
    {
        $stats = [
            'total_users'         => User::count(),
            'active_users'        => User::where('is_active', true)->count(),
            'total_conversations' => Conversation::count(),
            'total_messages'      => \App\Models\Message::count(),
            'users_by_role'       => User::selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role'),
        ];

        return view('admin.stats', compact('stats'));
    }
}
