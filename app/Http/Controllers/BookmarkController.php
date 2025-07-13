<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BookmarkController extends Controller
{
    public function addBookmark(Request $request): void
    {
        $validated = $request->validate([
            'item_id' => 'required|integer',
            'item_type' => [
                'required',
                'string',
                Rule::in([ClassModel::class, User::class])
            ],
        ]);

        $user = $request->user();

        $user->bookmarks()->create([
            'bookmarkable_id' => $validated['item_id'],
            'bookmarkable_type' => $validated['item_type']
        ]);
    }

    public function getBookmarkeds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255'
        ]);

        $user = $request->user();

        $query = $user->bookmarks()
            ->with(['bookmarkable' => function ($morphTo) {
                $morphTo->morphWith([
                    ClassModel::class => [
                        'modules.contents',
                        'mentor',
                        'subCategory.category'
                    ],
                    User::class => []
                ]);
            }]);

        if (isset($validated['search'])) {
            $query->whereHasMorph(
                'bookmarkable',
                [
                    ClassModel::class,
                    User::class,
                ],
                function ($morphQuery) use ($validated) {
                    $this->applySearchFilter($morphQuery, $validated['search']);
                }
            );
        }

        $bookmarks = $query->paginate(15);

        return response()->json($bookmarks, 200);
    }

    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => 'required|integer',
            'item_type' => [
                'required',
                'string',
                Rule::in([ClassModel::class, User::class])
            ]
        ]);

        $user = $request->user();
        $deleted = $user->bookmarks()
            ->where('bookmarkable_id', $validated['item_id'])
            ->where('bookmarkable_type', $validated['item_type'])
            ->delete();

        return response()->json([
            'message' => $deleted ? 'Bookmark deleted successfully' : 'Bookmark not found',
            'status' => $deleted ? 'success' : 'not found'
        ], $deleted ? 200 : 404);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bookmarks' => 'required|array|min:1',
            'bookmarks.*.item_id' => 'required|integer',
            'bookmarks.*.item_type' => ['required', 'string'],
        ]);

        $user = $request->user();

        // Start building the query
        $query = $user->bookmarks();

        // Add a nested where clause for the specific (id, type) pairs
        $query->where(function ($q) use ($validated) {
            foreach ($validated['bookmarks'] as $bookmark) {
                $q->orWhere(function ($subQ) use ($bookmark) {
                    $subQ->where('bookmarkable_id', $bookmark['item_id'])
                         ->where('bookmarkable_type', $bookmark['item_type']);
                });
            }
        });

        $deletedCount = $query->delete();

        return response()->json([
            'status' => 'success',
            'message' => $deletedCount . ' bookmark(s) deleted successfully',
        ]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $user->bookmarks()->delete();

        return response()->json([
            'message' => $count > 0 ? $count . " bookmarks cleared successfully" : "No bookmark to clear",
            'status' => 'success',
        ]);
    }

    private function applySearchFilter($query, string $search): void
    {
        if ($query->getModel() instanceof User) {
            $query->where('name', 'like', '%' . $search . '%')
                ->orWhere('username', 'like', '%' . $search . '%');
        } else {
            $query->where('title', 'like', '%' . $search . '%')
                ->orWhereHas('mentor', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->orWhere('username', 'like', '%' . $search . '%');
                });
        }
    }
}
