# Canvas LMS Package - Relationship Repair Plan

Audit of current model relationships against the [Canvas REST API documentation](https://canvas.instructure.com/doc/api/), with categorized issues and recommended fixes.

---

## Critical Issues

These are semantic bugs or broken functionality that will produce runtime errors.

### C1: `Group::memberships()` Returns Wrong Model Type

**File:** `src/Models/Group.php:54-57`

```php
public function memberships(): HasMany
{
    return $this->hasMany(User::class);  // Returns User objects, not GroupMembership
}
```

**Problem:** The method is named `memberships()` but returns `User` objects. Canvas has two separate endpoints:
- `GET /groups/:id/users` — returns User objects
- `GET /groups/:id/memberships` — returns GroupMembership objects with `id`, `group_id`, `user_id`, `workflow_state`, `moderator`, `created_at`, `updated_at`

**Fix:** Rename to `users()` to match what it actually returns. Add a proper `memberships()` when a `GroupMembership` model is created.

---

### C2: `BelongsTo` Fails for Context-Required Models

**File:** `src/Relations/BelongsTo.php:27-36`

```php
public function get(): ?CanvasModel
{
    $foreignId = $this->parent->getAttribute($this->foreignKey);
    return $this->builder->find($foreignId);  // Builds top-level URL only
}
```

**Problem:** `BelongsTo::get()` calls `builder->find($id)` which constructs a URL at the model's top-level endpoint. For models with `requiresContext = true`, this produces an invalid URL.

**Affected relationships:**

| Relationship | Produces | Should Produce |
|---|---|---|
| `Submission::assignment()` | `GET /api/v1/assignments/:id` | `GET /api/v1/courses/:cid/assignments/:id` |
| `ModuleItem::module()` | `GET /api/v1/modules/:id` | `GET /api/v1/courses/:cid/modules/:id` |

**Fix:** Extend `BelongsTo` to detect when the related model requires context. When the parent has attributes that can provide context (e.g., Submission has `course_id`), auto-push that context before fetching. Options:

1. Add an optional `$contextMap` parameter to `belongsTo()`:
   ```php
   return $this->belongsTo(Assignment::class, 'assignment_id', ['course_id' => Course::class]);
   ```

2. Have `BelongsTo::get()` check `$relatedClass::requiresContext()` and look for known context attributes on the parent.

---

### C3: `AssignmentGroup::assignments()` Produces Invalid URL

**File:** `src/Models/AssignmentGroup.php:39-41`

```php
public function assignments(): HasMany
{
    return $this->hasMany(Assignment::class);
}
```

**Problem:** Produces `GET /api/v1/assignment_groups/:id/assignments` — **this endpoint does not exist** in Canvas. Will return 404.

Canvas provides assignments within a group either by:
- `include[]=assignments` when fetching the assignment group itself
- Filtering: `GET /api/v1/courses/:cid/assignments?assignment_group_id=:id`

**Fix:** Replace with a filtered query through Course:

```php
public function assignments(): HasMany
{
    // Route through the course context instead of the invalid assignment_groups context
    return Course::newWithId($this->course_id)
        ->assignments()
        ->where('assignment_group_id', $this->id);
}
```

Or remove the method and document that assignments should be accessed via `include[]=assignments` on the group, or by filtering `$course->assignments()->where('assignment_group_id', $groupId)`.

---

## Structural Design Issues

### S1: No Mechanism for Context-Aware BelongsTo

Root cause of C2 above. The `BelongsTo` relation always fetches at the related model's top-level endpoint. This works for standalone models (Account, User, Course) but fails for context-required models (Assignment, Module, Section, etc.).

**Impact:** Any future `BelongsTo` relationship pointing to a `requiresContext = true` model will silently produce an invalid API URL.

**Recommendation:** Create a `BelongsToWithContext` variant or extend `BelongsTo` to accept context mappings from the parent's attributes.

---

### S2: Assignment->Submission Requires Double Context

Submission's Canvas endpoint is `GET /api/v1/courses/:cid/assignments/:aid/submissions`. When calling `$assignment->submissions()`, the HasMany only pushes `assignments/:aid` — the `courses/:cid` segment must already be in the builder's context stack.

This works when navigating from a course:
```php
Canvas::course(42)->assignments()->find(5)->submissions()->get();  // OK
```

But fails for standalone use:
```php
Assignment::newWithId(5)->submissions()->get();  // Missing courses/:cid context
```

**Recommendation:** Document this constraint. Consider having Assignment auto-push its `course_id` as context when that attribute is available.

---

### S3: SubmissionComment is Not a Standalone Resource

`src/Models/SubmissionComment.php` has `endpoint = ''` and no relationships. It's returned as nested JSON within submission responses via `include[]=submission_comments`.

**Current state:** Functions correctly as a typed wrapper for nested data. Cannot be queried via Builder (which is correct — Canvas has no standalone submission comment endpoint).

**Recommendation:** Add a doc comment clarifying it's embedded-only and not queryable. Long-term, consider a `ValueObject` base class for embedded resources like SubmissionComment.

---

### S4: No Polymorphic Context Support

Some Canvas resources belong to multiple parent types:
- **Group** has both `course_id` and `account_id`
- **Page** can belong to a Course or a Group
- **File** can belong to a Course, Group, or User

The current HasMany/BelongsTo system doesn't support polymorphic parent contexts. This is manageable for current models but would be needed for File/Folder support.

---

## Missing Relationships on Existing Models

### Account

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `enrollments()` | HasMany | `GET /accounts/:id/enrollments` | SubAccount has this but Account does not |

---

### Course

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `discussions()` | HasMany | `GET /courses/:id/discussion_topics` | Requires DiscussionTopic model |
| `files()` | HasMany | `GET /courses/:id/files` | Requires File model |
| `rubrics()` | HasMany | `GET /courses/:id/rubrics` | Requires Rubric model |
| `tabs()` | HasMany | `GET /courses/:id/tabs` | Requires Tab model |
| `groupCategories()` | HasMany | `GET /courses/:id/group_categories` | Requires GroupCategory model |

---

### Quiz

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `questions()` | HasMany | `GET /courses/:cid/quizzes/:id/questions` | Requires QuizQuestion model |
| `submissions()` | HasMany | `GET /courses/:cid/quizzes/:id/submissions` | Requires QuizSubmission model |

---

### Group

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `account()` | BelongsTo | `GET /accounts/:account_id` | Has `account_id` attribute, no method |
| `users()` | HasMany | `GET /groups/:id/users` | Current `memberships()` should be renamed to this |

---

### Assignment

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `overrides()` | HasMany | `GET /courses/:cid/assignments/:id/overrides` | Requires AssignmentOverride model |

---

### SubAccount

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `parentAccount()` | BelongsTo | `GET /accounts/:parent_account_id` | Has `parent_account_id` attribute, no method |

---

### Section

| Missing Relationship | Type | Canvas API Endpoint | Notes |
|---|---|---|---|
| `users()` | HasMany | `GET /sections/:id/users` | Direct user listing (not via enrollments) |

---

## Missing Models

### Tier 1 - High Value (frequently needed in integrations)

| Model | Canvas Endpoint | Parent Context | Key Relationships |
|---|---|---|---|
| **EnrollmentTerm** | `accounts/:id/terms` | Account | BelongsTo Account; referenced by `Course.enrollment_term_id` |
| **DiscussionTopic** | `courses/:cid/discussion_topics` | Course or Group | BelongsTo Course; HasMany DiscussionEntry |
| **File** | `courses/:cid/files` | Course, Group, or User | Polymorphic parent context |
| **GroupCategory** | `courses/:cid/group_categories` | Course or Account | HasMany Group |
| **GroupMembership** | `groups/:id/memberships` | Group | BelongsTo Group, BelongsTo User |

### Tier 2 - Medium Value (assessment workflows)

| Model | Canvas Endpoint | Parent Context | Key Relationships |
|---|---|---|---|
| **Rubric** | `courses/:cid/rubrics` | Course or Account | BelongsTo Course |
| **QuizQuestion** | `courses/:cid/quizzes/:qid/questions` | Quiz | BelongsTo Quiz |
| **QuizSubmission** | `courses/:cid/quizzes/:qid/submissions` | Quiz | BelongsTo Quiz, BelongsTo User |
| **AssignmentOverride** | `courses/:cid/assignments/:aid/overrides` | Assignment | BelongsTo Assignment |
| **DiscussionEntry** | `courses/:cid/discussion_topics/:tid/entries` | DiscussionTopic | BelongsTo DiscussionTopic |

### Tier 3 - Nice to Have (specialized use cases)

| Model | Canvas Endpoint | Parent Context |
|---|---|---|
| CalendarEvent | `calendar_events` | Various |
| Conversation | `conversations` | User |
| Tab | `courses/:cid/tabs` | Course |
| Folder | `courses/:cid/folders` | Course, Group |
| Feature / FeatureFlag | `accounts/:id/features` | Account, Course |
| GradingPeriodSet | `accounts/:id/grading_period_sets` | Account |

---

## Missing Action Methods

### User (`src/Models/User.php`)

Currently has: `suspend()`, `unsuspend()`

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| Critical | `mergeInto(User $target)` | `PUT /users/:id/merge_into/:target_id` | Merge duplicate accounts |
| Critical | `split()` | `POST /users/:id/split` | Reverse a merge |
| Critical | `terminateSessions()` | `DELETE /users/:id/sessions` | Force logout all sessions |
| High | `getProfile()` | `GET /users/:id/profile` | Extended profile data |
| High | `getPageViews()` | `GET /users/:id/page_views` | Activity tracking / analytics |
| High | `getMissingSubmissions()` | `GET /users/:id/missing_submissions` | Student intervention workflows |
| High | `getGradedSubmissions()` | `GET /users/:id/graded_submissions` | Grade review workflows |
| High | `getActivityStream()` | `GET /users/self/activity_stream` | Recent activity feed |
| High | `getTodoItems()` | `GET /users/self/todo` | Pending tasks |
| High | `getUpcomingEvents()` | `GET /users/self/upcoming_events` | Calendar awareness |
| Medium | `getSettings()` | `GET /users/:id/settings` | User preferences |
| Medium | `updateSettings(array $data)` | `PUT /users/:id/settings` | Update user preferences |
| Medium | `getCustomColor(string $asset)` | `GET /users/:id/colors/:asset_string` | Dashboard customization |
| Medium | `setCustomColor(string $asset, string $hex)` | `PUT /users/:id/colors/:asset_string` | Dashboard customization |
| Medium | `getDashboardPositions()` | `GET /users/:id/dashboard_positions` | Dashboard layout |
| Medium | `getCustomData(string $scope)` | `GET /users/:id/custom_data/:scope` | Custom key-value storage |
| Medium | `setCustomData(string $scope, array $data)` | `PUT /users/:id/custom_data/:scope` | Custom key-value storage |
| Low | `getAvatarOptions()` | `GET /users/:id/avatars` | Available avatar images |
| Low | `uploadFile(string $path)` | `POST /users/:id/files` | Upload to user files |

---

### Course (`src/Models/Course.php`)

Currently has: `publish()`, `hide()`, `conclude()`, `restore()`

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `getSettings()` | `GET /courses/:id/settings` | Course configuration |
| High | `updateSettings(array $data)` | `PUT /courses/:id/settings` | Update course settings |
| High | `getPermissions()` | `GET /courses/:id/permissions` | Check user permissions |
| High | `resetContent()` | `POST /courses/:id/reset_content` | Wipe content, keep structure |
| High | `getBulkUserProgress()` | `GET /courses/:id/bulk_user_progress` | All student progress |
| High | `getUserProgress(int $userId)` | `GET /courses/:id/users/:uid/progress` | Single student progress |
| High | `getEffectiveDueDates()` | `GET /courses/:id/effective_due_dates` | Due dates accounting for overrides |
| Medium | `copy()` | `POST /courses/:id/course_copy` | Clone course |
| Medium | `bulkUpdate(array $data)` | `PUT /accounts/:aid/courses` | Batch update multiple courses (static) |
| Medium | `getActivityStream()` | `GET /courses/:id/activity_stream` | Recent course activity |
| Medium | `uploadFile(string $path)` | `POST /courses/:id/files` | Upload to course files |
| Low | `getRecentStudents()` | `GET /courses/:id/recent_students` | Recently active students |
| Low | `previewHtml(string $html)` | `POST /courses/:id/preview_html` | Render HTML with Canvas processing |

---

### Account (`src/Models/Account.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| Critical | `getSettings()` | `GET /accounts/:id/settings` | Account configuration |
| Critical | `updateSettings(array $data)` | `PUT /accounts/:id/settings` | Update account settings |
| Critical | `getPermissions()` | `GET /accounts/:id/permissions` | Check admin permissions |
| High | `removeUser(int $userId)` | `DELETE /accounts/:id/users/:uid` | Remove user from account |
| High | `bulkUpdateUsers(array $data)` | `PUT /accounts/:id/users/bulk_update` | Batch user operations |
| High | `restoreDeletedUser(int $userId)` | `PUT /accounts/:id/users/:uid/restore` | Recover deleted user |
| Medium | `getTermsOfService()` | `GET /accounts/:id/terms_of_service` | ToS configuration |
| Medium | `getHelpLinks()` | `GET /accounts/:id/help_links` | Custom help links |

---

### Assignment (`src/Models/Assignment.php`)

Currently has: `bulkGrade()`

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `getOverrides()` | `GET /courses/:cid/assignments/:id/overrides` | List due date overrides |
| High | `createOverride(array $data)` | `POST /courses/:cid/assignments/:id/overrides` | Add differentiated due date |
| High | `updateOverride(int $id, array $data)` | `PUT /courses/:cid/assignments/:id/overrides/:oid` | Update override |
| High | `deleteOverride(int $id)` | `DELETE /courses/:cid/assignments/:id/overrides/:oid` | Remove override |
| High | `getBatchOverrides()` | `GET /courses/:cid/assignments/overrides` | All overrides for course (static) |
| Medium | `duplicate()` | `POST /courses/:cid/assignments/:id/duplicate` | Clone assignment |
| Medium | `bulkUpdate(array $data)` | `PUT /courses/:cid/assignments/bulk_update` | Batch update (static) |
| Medium | `peerReviews()` | `GET /courses/:cid/assignments/:id/peer_reviews` | List peer review assignments |

---

### Submission (`src/Models/Submission.php`)

Currently has: `grade()`, `excuse()`, `addComment()`, `gradeWithRubric()`

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `uploadFile(string $path)` | `POST /courses/:cid/assignments/:aid/submissions/:uid/files` | Attach file to submission |
| High | `getGradeableStudents()` | `GET /courses/:cid/assignments/:aid/gradeable_students` | Students who can submit (static) |
| Medium | `createSubmission(array $data)` | `POST /courses/:cid/assignments/:aid/submissions` | Submit on behalf of student |
| Medium | `getAnonymous(string $anonId)` | `GET /courses/:cid/assignments/:aid/anonymous_submissions/:anonId` | Fetch anonymous submission (static) |
| Medium | `gradeAnonymous(string $anonId, array $data)` | `PUT /courses/:cid/assignments/:aid/anonymous_submissions/:anonId` | Grade anonymous submission (static) |
| Medium | `getBulkSubmissions()` | `GET /courses/:cid/students/submissions` | All student submissions for course (static) |
| Low | `markAsRead()` | `PUT /courses/:cid/assignments/:aid/submissions/:uid/read` | Mark submission read |
| Low | `markAsUnread()` | `DELETE /courses/:cid/assignments/:aid/submissions/:uid/read` | Mark submission unread |
| Low | `getSubmissionSummary()` | `GET /courses/:cid/assignments/:aid/submission_summary` | Graded/ungraded/not-submitted counts (static) |

---

### Section (`src/Models/Section.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `crossList(int $newCourseId)` | `POST /sections/:id/crosslist/:new_course_id` | Move section to another course |
| High | `deCrossList()` | `DELETE /sections/:id/crosslist` | Return to original course |
| High | `getUsers()` | `GET /sections/:id/users` | List section users |

---

### Enrollment (`src/Models/Enrollment.php`)

Currently has: `conclude()`, `deactivate()`, `reactivate()`, `delete()`

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| Critical | `bulkEnroll(array $data)` | `POST /accounts/:aid/bulk_enrollment` | Mass enrollment (static) |
| Medium | `accept()` | `POST /courses/:cid/enrollments/:id/accept` | Accept enrollment invitation |
| Medium | `reject()` | `POST /courses/:cid/enrollments/:id/reject` | Reject enrollment invitation |

---

### Quiz (`src/Models/Quiz.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `reorder(array $order)` | `POST /courses/:cid/quizzes/:id/reorder` | Reorder questions |
| High | `validateAccessCode(string $code)` | `POST /courses/:cid/quizzes/:id/validate_access_code` | Verify access code |

---

### Module (`src/Models/Module.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `relock()` | `PUT /courses/:cid/modules/:id/relock` | Reset module progressions |
| Medium | `getAssignmentOverrides()` | `GET /courses/:cid/modules/:mid/assignment_overrides` | Module-level overrides |
| Medium | `updateAssignmentOverrides(array $data)` | `PUT /courses/:cid/modules/:mid/assignment_overrides` | Update module overrides |

---

### ModuleItem (`src/Models/ModuleItem.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `markDone()` | `PUT /courses/:cid/modules/:mid/items/:id/done` | Mark item complete |
| High | `markRead()` | `POST /courses/:cid/modules/:mid/items/:id/mark_read` | Mark item as read |
| Medium | `selectMasteryPath(int $assignmentSetId)` | `POST /courses/:cid/modules/:mid/items/:id/select_mastery_path` | Choose mastery path |

---

### Group (`src/Models/Group.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `invite(array $userIds)` | `POST /groups/:id/invite` | Invite users to group |
| High | `getUsers()` | `GET /groups/:id/users` | List group users (distinct from memberships) |
| High | `getMemberships()` | `GET /groups/:id/memberships` | List membership objects with roles/state |
| High | `addMembership(int $userId)` | `POST /groups/:id/memberships` | Add user to group |
| High | `removeMembership(int $membershipId)` | `DELETE /groups/:id/memberships/:mid` | Remove user from group |
| Medium | `updateMembership(int $mid, array $data)` | `PUT /groups/:id/memberships/:mid` | Update membership role/state |
| Medium | `bulkRemoveUsers(array $userIds)` | `DELETE /groups/:id/users` | Remove multiple users |
| Low | `uploadFile(string $path)` | `POST /groups/:id/files` | Upload to group files |
| Low | `getActivityStream()` | `GET /groups/:id/activity_stream` | Group activity feed |
| Low | `getPermissions()` | `GET /groups/:id/permissions` | Check group permissions |

---

### Page (`src/Models/Page.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `duplicate()` | `POST /courses/:cid/pages/:url/duplicate` | Clone page |
| Medium | `getRevisions()` | `GET /courses/:cid/pages/:url/revisions` | Page edit history |
| Medium | `getLatestRevision()` | `GET /courses/:cid/pages/:url/revisions/latest` | Most recent revision |
| Medium | `revertToRevision(int $revisionId)` | `POST /courses/:cid/pages/:url/revisions/:rid` | Restore previous version |

---

### SubAccount (`src/Models/SubAccount.php`)

Currently has: no action methods

| Priority | Method | Canvas API Endpoint | Purpose |
|---|---|---|---|
| High | `delete()` | `DELETE /accounts/:parent_id/sub_accounts/:id` | Remove subaccount |
| High | `update(array $data)` | `PUT /accounts/:parent_id/sub_accounts/:id` | Update subaccount settings |

---

## Recommended Implementation Order

### Phase 1 - Fix Critical Bugs (immediate)

1. Rename `Group::memberships()` to `Group::users()` (C1)
2. Fix BelongsTo for context-required models — affects `Submission::assignment()`, `ModuleItem::module()` (C2/S1)
3. Fix or remove `AssignmentGroup::assignments()` invalid endpoint (C3)
4. Document the double-context requirement for Assignment -> Submission (S2)

### Phase 2 - Add Missing Relationships to Existing Models (short term)

1. `Account::enrollments()`
2. `Group::account()` BelongsTo
3. `SubAccount::parentAccount()` BelongsTo
4. `Section::users()` HasMany

### Phase 3 - Add Missing Action Methods (prioritized)

Start with Critical + High priority methods across all models, focusing on:
1. Account/User management (`getSettings`, `getPermissions`, `mergeInto`)
2. Assignment overrides (`getOverrides`, `createOverride`, etc.)
3. Section cross-listing (`crossList`, `deCrossList`)
4. Course settings and progress (`getSettings`, `getBulkUserProgress`)
5. Module/ModuleItem completion (`markDone`, `markRead`, `relock`)
6. Group membership management (`invite`, `addMembership`, `removeMembership`)

### Phase 4 - Add Tier 1 Missing Models (medium term)

1. EnrollmentTerm
2. GroupMembership + GroupCategory
3. DiscussionTopic
4. File

### Phase 5 - Add Tier 2 Missing Models (longer term)

1. Rubric
2. QuizQuestion / QuizSubmission
3. AssignmentOverride
4. DiscussionEntry

### Phase 6 - Structural Improvements (ongoing)

1. `BelongsToWithContext` relation for context-required parent models
2. Evaluate SubmissionComment as DTO vs CanvasModel
3. Consider polymorphic context support for File/Folder
