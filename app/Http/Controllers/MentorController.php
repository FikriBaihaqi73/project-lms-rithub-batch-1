<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class MentorController extends Controller
{
    public function getMentorById(Request $request, int $mentorId): JsonResponse
    {
        $userId = $request->user()->id;
        $mentor = User::with('classesTaught')
            ->where('role', 'mentor')
            ->findOrFail($mentorId);

        $mentor->is_bought = $this->isBought($userId, $mentorId);
        $mentor->is_bookmarked = $this->isBookmarked($userId, $mentorId);

        return response()->json($mentor, 200);
    }

    public function getMentors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'min_price' => 'nullable|integer|min:0|max_digits:10',
            'max_price' => 'nullable|integer|min:0|max_digits:10|gt:min_price',
            'is_verified' => 'nullable|boolean',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:15|max:100',
        ]);

        $perPage = $validated['per_page'] ?? 15;

        $mentors = User::with('classesTaught')
            ->where('role', 'mentor')
            ->when(isset($validated['min_price']), function ($query) use ($validated) {
                $query->where('mentor_subscription_price', '>=', $validated['min_price']);
            })
            ->when(isset($validated['max_price']), function ($query) use ($validated) {
                $query->where('mentor_subscription_price', '<=', $validated['max_price']);
            })
            ->when(isset($validated['is_verified']), function ($query) use ($validated) {
                $query->where('is_verified', (bool) $validated['is_verified']);
            })
            ->when(isset($validated['search']), function ($query) use ($validated) {
                $query->where('name', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('username', 'like', '%' . $validated['search'] . '%')
                    ->orWhere('profession', 'like', '%' . $validated['search'] . '%');
            })
            ->paginate($perPage);

        return response()->json($mentors);
    }

    private function isBought($userId, $mentorId): bool
    {
        return Enrollment::where('user_id', $userId)
            ->whereHas('userSubscription', function ($query) {
                $query->where('status', 'active');
            })
            ->whereHasMorph('enrollable', [User::class], function ($query) use ($mentorId) {
                $query->where('id', $mentorId);
            })
            ->exists();
    }

    private function isBookmarked($userId, $mentorId): bool
    {
        return Bookmark::where('user_id', $userId)
            ->whereHasMorph('bookmarkable', [User::class], function ($query) use ($mentorId) {
                $query->where('bookmarkable_id', $mentorId);
            })
            ->exists();
    }
}
