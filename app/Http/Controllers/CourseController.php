<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function getCourseContents(Request $request, int $courseId): JsonResponse
    {
        $user = $request->user();

        $course = ClassModel::with('mentor')->findOrFail($courseId);
        $contents = $this->groupingContent($course, $this->isBought($user->id, $courseId)) ?? null;
        $course->contents = $contents;
        $course->is_bought = $this->isBought($user->id, $courseId);
        $course->is_bookmarked = $this->isBookmarked($user->id, $courseId);

        $finishedContents = $user->userCompletedContents()
        ->where('class_id', $courseId)
        ->select('content_id')
        ->get();

        if (!$course) return response()->json(['status' => 'error', 'message' => 'course not found'], 404);

        return response()->json([$course, $finishedContents], 200);
    }

    public function finishedCourse(Request $request)
    {
        $validated = $request->validate([
            'course_id' => 'required|integer|exists:class_models,id',
            'content_id' => 'required|integer|exists:educational_contents,id'
        ]);

        $user = $request->user();

        //Optional
        $content = ClassModel::findOrFail($validated['course_id'])
            ->whereHas('modules', function ($query) use ($validated) {
                $query->where('class_id', $validated['course_id'])
                    ->whereHas('contents', function ($q) use ($validated) {
                        $q->where('id', $validated['content_id']);
                    });
            })
            ->first();

        if (!$content) {
            return new Exception("Content not found in the specified course.", 404);
        }

        UserClassContents::updateOrInsert([
            'user_id' => $user->id,
            'class_id' => $validated['course_id'],
            'content_id' => $validated['content_id'],
        ], [
            'completed_at' => now()
        ]);

        return true;
    }

    private function isBought($UserId, $courseId): bool
    {
        $mentor_id = ClassModel::where('id', $courseId)->value('mentor_id');

        return Enrollment::where('user_id', $UserId)
            ->whereHas('userSubscription', function ($query) {
                $query->where('status', 'active');
            })
            ->where(function ($query) use ($mentor_id, $courseId) {
                $query->whereHasMorph('enrollable', [User::class], function ($query) use ($mentor_id) {
                    $query->where('enrollable_id', $mentor_id);
                })
                    ->orWhereHasMorph('enrollable', [ClassModel::class], function ($query) use ($courseId) {
                        $query->where('enrollable_id', $courseId);
                    });
            })
            ->exists();
    }

    public function groupingContent(object $contents, bool $isBought): mixed
    {
        return $contents->allContents()
            ->sortBy('module.order_index') // sortir berdasarkan urutan modul
            ->groupBy(fn($content) => $content->module->order_index) // module_index
            ->map(function ($moduleGroup, $moduleIndex) use ($isBought) {
                return [
                    'module_index' => (int)$moduleIndex,
                    'module' => $moduleGroup
                        ->sortBy(fn($c) => $c->module->title) // module_name
                        ->groupBy(fn($c) => $c->module->title)
                        ->map(function ($contents, $moduleName) use ($isBought) {
                            return [
                                'module_name' => $moduleName,
                                'contents' => $contents
                                    ->sortBy('order_index')
                                    ->map(function ($content) use ($isBought) {
                                        $contentArray = $content->toArray();

                                        $contentArray['content_path'] = ($content->is_preview == 1)
                                            ? $content->content_path
                                            : (($isBought && $content->is_preview == 0)
                                                ? $content->content_path
                                                : null);

                                        // Cleanup jika ingin
                                        unset($contentArray['module']);
                                        unset($contentArray['module_of_course_id']);

                                        return $contentArray;
                                    })
                                    ->values()
                                    ->toArray()
                            ];
                        })
                        ->values()
                        ->toArray()
                ];
            })
            ->values()
            ->toArray();
    }

    private function isBookmarked($userId, $courseId): bool
    {
        return Bookmark::where('user_id', $userId)
            ->whereHasMorph('bookmarkable', [ClassModel::class], function ($query) use ($courseId) {
                $query->where('bookmarkable_id', $courseId);
            })
            ->exists();
    }
}
