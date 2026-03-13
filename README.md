# Canvas LMS for Laravel

A Laravel package for integrating with the [Canvas LMS REST API](https://canvas.instructure.com/doc/api/). Provides Eloquent-like model abstractions, a fluent query builder, relationship modeling, and both API token and OAuth2 authentication.

**Compatible with Laravel 10+ and PHP 8.1+**

---

## Installation

```bash
composer require jarrywc/canvas-lms-laravel-model
```

Publish the config file:

```bash
php artisan vendor:publish --tag=canvas-config
```

---

## Configuration

Add the following to your `.env`:

```env
CANVAS_URL=https://your-institution.instructure.com
CANVAS_AUTH_DRIVER=token
CANVAS_API_TOKEN=your-api-token
CANVAS_ACCOUNT_ID=1
```

The full config is at `config/canvas.php`.

---

## Authentication

### API Token (default)

Generate a token in Canvas under **Account → Settings → Approved Integrations**.

```env
CANVAS_AUTH_DRIVER=token
CANVAS_API_TOKEN=your-token-here
```

### OAuth2

Register a Developer Key in Canvas under **Admin → Developer Keys**, then set:

```env
CANVAS_AUTH_DRIVER=oauth2
CANVAS_CLIENT_ID=your-client-id
CANVAS_CLIENT_SECRET=your-client-secret
CANVAS_REDIRECT_URI=https://your-app.com/canvas/oauth/callback
```

The package registers two routes automatically:
- `GET /canvas/oauth/redirect` — redirects the user to Canvas for authorization
- `GET /canvas/oauth/callback` — handles the Canvas callback and stores the token

**Token storage** defaults to Laravel's cache. For a database-backed store, publish and run the migration:

```bash
php artisan vendor:publish --tag=canvas-migrations
php artisan migrate
```

```env
CANVAS_TOKEN_STORAGE=database
```

For multi-user OAuth2, scope requests to the authenticated user with `actingAs()`:

```php
Canvas::actingAs("user:{$user->id}")->courses()->get();
```

`actingAs()` returns a new scoped instance — it does not mutate the singleton, making it safe for use with Laravel Octane.

---

## Basic Usage

### Querying Resources

```php
use JarredCain\CanvasLms\Facades\Canvas;
use JarredCain\CanvasLms\Models\Course;

// List all courses
$courses = Course::query()->get();

// With filters
$courses = Course::query()
    ->whereIn('enrollment_state', ['active'])
    ->include(['teachers', 'term'])
    ->search('intro to biology')
    ->perPage(50)
    ->get();

// Find a single record
$course = Course::find(42);
echo $course->name;
echo $course->course_code;
echo $course->start_at->format('Y-m-d'); // Carbon instance

// Via facade
$courses = Canvas::courses()->where('workflow_state', 'available')->get();
```

### Relationships

```php
$course = Course::find(42);

// Has many
$enrollments = $course->enrollments()->get();
$assignments = $course->assignments()->where('published', true)->get();
$sections    = $course->sections()->get();
$quizzes     = $course->quizzes()->get();

// Chaining filters on relationships
$students = $course->enrollments()
    ->whereIn('type', ['StudentEnrollment'])
    ->where('enrollment_state', 'active')
    ->include(['grades'])
    ->get();

// Belongs to
$account = $course->account()->get();

// Nested relationships
$assignment  = $course->assignments()->find(99);
$submissions = $assignment->submissions()->include(['rubric_assessment'])->get();
```

### Facade Sugar

For relationship traversal without an extra API fetch, use the facade model factories:

```php
// Returns a lazy Course instance (no API call) — then traverses the relationship
Canvas::course(42)->enrollments()->get();
Canvas::course(42)->assignments()->where('published', true)->get();
Canvas::user(5)->enrollments()->get();
Canvas::assignment(99)->submissions()->get();
```

### Org-Wide Course Listing

`Course::query()->get()` and `Canvas::courses()->get()` only return the **current user's enrolled courses** — this is a Canvas API scope restriction. To list all courses across the organization (admin required):

```php
// All three are equivalent — hit GET /api/v1/accounts/:id/courses
Canvas::accountCourses()->get();          // uses canvas.account_id from config
Canvas::accountCourses(5)->get();         // explicit account
Canvas::account(5)->courses()->get();     // via account chain

// Combine with filters
Canvas::accountCourses()
    ->onlyPublished()
    ->forTerm(3)
    ->withEnrollments()
    ->byTeachers([101, 202])
    ->include(['teachers', 'total_students'])
    ->perPage(50)
    ->get();
```

### CRUD Operations

```php
// Create
$assignment = Canvas::course(42)->assignments()->create([
    'name'             => 'Final Essay',
    'points_possible'  => 100,
    'submission_types' => ['online_text_entry'],
    'published'        => true,
]);

// Update
$updated = Course::query()->update(42, ['name' => 'Intro to Biology — Updated']);

// Delete
Course::query()->delete(42);
```

---

## Pagination

Canvas paginates responses via the `Link` response header. The package handles this automatically.

```php
$page = Course::query()->perPage(10)->get();

$page->hasNextPage(); // bool
$page->count();       // items on this page

// Fetch the next page (uses the opaque Link header URL)
$nextPage = $page->next();

// Iterate items on a page
foreach ($page as $course) {
    echo $course->name;
}
```

### Fetching All Pages

```php
// Load everything into memory (suitable for small/known datasets)
$all = Course::query()->all(); // Collection

// Lazy streaming — memory-efficient for large datasets
Course::query()->lazy()->each(function (Course $course) {
    // processed one at a time, pages fetched on demand
});
```

---

## Models

All models provide typed property access via `@property` docblocks, and cast common fields automatically (IDs to `string`, dates to `Carbon`, booleans, floats, etc.).

| Model | Endpoint | Requires Parent Context |
|---|---|---|
| `Account` | `accounts` | No |
| `User` | `users` | No |
| `Course` | `courses` | No |
| `Group` | `groups` | No |
| `Progress` | `progress` | No |
| `Enrollment` | `enrollments` | Yes — `forCourse()`, `forUser()`, or `forSection()` |
| `Section` | `sections` | Yes — `forCourse()` |
| `Assignment` | `assignments` | Yes — `forCourse()` |
| `AssignmentGroup` | `assignment_groups` | Yes — `forCourse()` |
| `Submission` | `submissions` | Yes — `forCourse()` + `forAssignment()` |
| `Quiz` | `quizzes` | Yes — `forCourse()` |
| `Module` | `modules` | Yes — `forCourse()` |
| `ModuleItem` | `items` | Yes — `forCourse()` + `forModule()` |
| `Page` | `pages` | Yes — `forCourse()` |
| `GradingPeriod` | `grading_periods` | Yes — `forCourse()` |
| `SubmissionComment` | _(value object)_ | Returned nested inside `Submission` |

### Nested Endpoints

Resources that require a parent context must be queried via a context method or a relationship. Querying without context throws `MissingContextException`.

```php
// Correct
Enrollment::query()->forCourse(42)->get();
Enrollment::query()->forUser(5)->get();

// Also correct — via relationship
Course::newWithId(42)->enrollments()->get();

// Throws MissingContextException
Enrollment::query()->get(); // Canvas has no flat /enrollments endpoint
```

---

## Query Builder Reference

### Core Methods

| Method | Description |
|---|---|
| `where(field, value)` | Add a scalar query parameter |
| `whereIn(field, array)` | Add an array parameter (`field[]=val`) |
| `include(string\|array)` | Add `include[]=` sideloads |
| `search(string)` | Set `search_term` |
| `perPage(int)` | Set `per_page` |
| `page(int)` | Set `page` |
| `orderBy(field, direction)` | Set `sort` and `order` |
| `forCourse(id)` | Push course context onto URL |
| `forUser(id)` | Push user context onto URL |
| `forAccount(id)` | Push account context onto URL |
| `forSection(id)` | Push section context onto URL |
| `forAssignment(id)` | Push assignment context onto URL |
| `forModule(id)` | Push module context onto URL |
| `withRetry(times, sleepMs)` | Auto-retry on rate limit responses |
| `get()` | Execute and return `PaginatedResponse` |
| `first()` | Return first result or `null` |
| `find(id)` | Fetch a single resource by ID |
| `all()` | Follow all pages, return `Collection` |
| `lazy()` | Follow all pages lazily, return `LazyCollection` |
| `create(array)` | POST — create a resource |
| `update(id, array)` | PUT — update a resource |
| `delete(id)` | DELETE — delete a resource |

### Course & Enrollment Filters

| Method | Canvas Param | Notes |
|---|---|---|
| `onlyPublished()` | `published=true` | Account-scoped courses |
| `onlyUnpublished()` | `published=false` | Account-scoped courses |
| `withEnrollments(bool)` | `with_enrollments=` | Filter by enrollment presence |
| `excludeBlueprints()` | `exclude_blueprint_courses=true` | |
| `onlyBlueprints()` | `blueprint=true` | Account-scoped |
| `forTerm(id)` | `enrollment_term_id=` | Both scopes |
| `onlyHomeroom()` | `homeroom=true` | User-scoped only |
| `startsBefore(date)` | `starts_before=ISO8601` | Account-scoped |
| `endsAfter(date)` | `ends_after=ISO8601` | Account-scoped |
| `byTeachers(array)` | `by_teachers[]=` | Account-scoped |
| `byStudents(array)` | `by_students[]=` | Account-scoped |
| `ofEnrollmentType(string\|array)` | `enrollment_type[]=` | User-scoped courses |
| `ofState(string\|array)` | `state[]=` | Course workflow states |
| `ofEnrollmentState(string\|array)` | `enrollment_state[]=` | User-scoped courses |

---

## Lifecycle Action Methods

Canvas uses non-standard REST patterns for state changes — not separate endpoints but event/task parameters. The package models these as plain method calls on model instances.

### Course Lifecycle

```php
$course = Course::find(42);

$course->publish();    // Makes course visible to students
$course->hide();       // Hides course from students (unpublish)
$course->conclude();   // Locks course as read-only
$course->restore();    // Restores a deleted course
```

### Enrollment Lifecycle

```php
$enrollment = $course->enrollments()->first();

$enrollment->conclude();    // Mark as concluded
$enrollment->deactivate();  // Deactivate (still visible, no access)
$enrollment->reactivate();  // Re-activate a deactivated enrollment
$enrollment->delete();      // Permanently delete — returns bool
```

### Submission Grading

```php
$submission = Canvas::course(42)->assignment(99)->submissions()->find($userId);

$submission->grade(85);                        // numeric score
$submission->grade('88%');                     // percentage
$submission->grade('A', 'Great work!');        // with inline comment
$submission->excuse();                         // mark excused
$submission->addComment('Please resubmit.');   // comment only, no grade change
$submission->gradeWithRubric([
    '_criterion_id' => 3,
    '_other_id'     => 5,
]);
```

### Bulk Grading (Async)

For grading many students at once, Canvas processes the request asynchronously and returns a `Progress` object.

```php
$progress = Canvas::course(42)->assignment(99)->bulkGrade([
    101 => 85,
    102 => 92,
    103 => ['score' => 78, 'comment' => 'Late submission'],
]);

// Block until complete (polls every second, max 2 minutes)
$progress->wait(120);

// Or poll manually
while ($progress->isPending()) {
    sleep(2);
    $progress->refresh();
}

$progress->isComplete(); // true
$progress->completion;   // 100
```

---

## CSV Import

The `CsvImporter` updates Canvas objects from a CSV file. Only the columns present in the CSV are sent to Canvas — rows don't need to contain every field.

### Basic Usage

```php
use JarredCain\CanvasLms\Import\CsvImporter;
use JarredCain\CanvasLms\Models\Course;

$result = CsvImporter::for(Course::class)->import('/path/to/courses.csv');

echo $result->total;     // total rows processed
echo $result->succeeded; // successful updates
echo $result->failed;    // failed rows

foreach ($result->failed() as $row) {
    echo "Row {$row->row} (ID: {$row->id}): {$row->error}";
}
```

**CSV format** — the `id` column identifies which record to update. Any other columns are treated as fields to update:

```csv
id,name,course_code,time_zone
42,Introduction to Biology,BIO101,America/New_York
43,Advanced Chemistry,CHEM301,
```

Empty cells are skipped — row 43 above sends only `name` and `course_code` to Canvas (not `time_zone`).

### Nested Resources

For resources that require a parent context, provide a pre-configured builder:

```php
$result = CsvImporter::for(Assignment::class)
    ->using(Assignment::query()->forCourse(42))
    ->import('/path/to/assignments.csv');
```

### Column Mapping

When CSV headers don't match Canvas field names:

```php
$result = CsvImporter::for(User::class)
    ->mapColumns([
        'Full Name'     => 'name',
        'Email Address' => 'email',
        'SIS ID'        => 'sis_user_id',
    ])
    ->import('/path/to/users.csv');
```

### Options

| Method | Description |
|---|---|
| `idColumn(string)` | CSV column used as the record ID (default: `'id'`) |
| `mapColumns(array)` | Map CSV headers to Canvas field names |
| `wrap(string)` | Override the Canvas namespace key (e.g. `'course'`) |
| `noWrap()` | Send fields flat — no resource namespace wrapper |
| `skipEmpty(bool)` | Skip empty cells (default: `true`) |
| `dryRun()` | Validate rows without making API calls |
| `using(Builder)` | Provide a pre-configured builder for nested resources |

### Dry Run

```php
$result = CsvImporter::for(Course::class)
    ->dryRun()
    ->import('/path/to/courses.csv');

// Check which rows would fail (missing ID, no fields, etc.)
foreach ($result->failed() as $row) {
    echo "Row {$row->row}: {$row->error}";
}
```

### Import from a String

```php
$csv = "id,name\n42,Biology 101\n43,Chemistry 201";

$result = CsvImporter::for(Course::class)->importString($csv);
```

---

## Error Handling

```php
use JarredCain\CanvasLms\Exceptions\AuthException;
use JarredCain\CanvasLms\Exceptions\CanvasException;
use JarredCain\CanvasLms\Exceptions\MissingContextException;
use JarredCain\CanvasLms\Exceptions\RateLimitException;

try {
    $courses = Course::query()->get();
} catch (RateLimitException $e) {
    $retryAfter = $e->getRetryAfter(); // seconds until retry is safe
} catch (AuthException $e) {
    // Token invalid, expired, or OAuth2 flow incomplete
} catch (MissingContextException $e) {
    // Queried a nested resource without a parent context
} catch (CanvasException $e) {
    // Any other Canvas API error
}

// Or use built-in retry
Course::query()->withRetry(3, 500)->get();
```

---

## Testing

The package uses `Http::fake()` for testing — no real Canvas instance required.

```php
use Illuminate\Support\Facades\Http;
use JarredCain\CanvasLms\Models\Course;

Http::fake([
    'canvas.example.com/api/v1/courses*' => Http::response([
        ['id' => '1', 'name' => 'Biology 101', 'course_code' => 'BIO101'],
    ], 200),
]);

$courses = Course::query()->get();

$this->assertCount(1, $courses);
$this->assertSame('Biology 101', $courses->first()->name);
```

Run the package's own test suite:

```bash
composer install
./vendor/bin/phpunit
```

---

## Development Skill

This package includes a `/canvas-lms` Claude Code skill to assist with ongoing development:

```
/canvas-lms add-model Rubric
/canvas-lms check-endpoint courses/assignments
/canvas-lms debug-query
```

The skill provides Canvas API field references, code scaffolding patterns, and troubleshooting guides.

---

## License

MIT
