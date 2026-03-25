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
CANVAS_USER_AGENT=MyApp/1.0
```

The full config is at `config/canvas.php`.

### User-Agent

Canvas requires a `User-Agent` header on all API requests. The package sends one automatically, defaulting to `CanvasLmsLaravel/1.0`. Override it to identify your application:

```env
CANVAS_USER_AGENT=MyApp/1.0
```

### API Logging

Enable request/response logging for all Canvas API calls:

```env
CANVAS_LOG_ENABLED=true
CANVAS_LOG_CHANNEL=stack    # any Laravel log channel, or omit for default
```

When enabled, requests are logged at `debug` level and failed responses at `warning` level. Response bodies are truncated at 2000 characters. OAuth2 token operations are also logged with secrets masked.

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

### Testing Your Credentials

Verify your auth configuration is working with a real API call:

```bash
php artisan canvas:test-auth
```

This command checks your config values, validates credentials for the active auth driver (token or OAuth2), and calls `GET /api/v1/users/self` to confirm authentication. On success it prints the authenticated user's ID, name, and email.

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

// Scoped to a specific subaccount
Canvas::subAccountCourses(12)->get();
Canvas::subAccountCourses(12)->onlyPublished()->get();

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

In Canvas, subaccounts are accounts — `subAccountCourses(id)` is a named shortcut for `accountCourses(id)` that makes the intent clear when working with subaccount hierarchies. You can also use `forSubAccount(id)` directly on any builder.

### Cross-Course User Listing

`courseUserList()` collects unique users (id + name + email) across a set of courses. It follows all pagination automatically and deduplicates users who appear in more than one course.

```php
// Explicit list of course IDs
$users = Canvas::courseUserList()->courses([23, 24, 25])->get();

// Or a range (inclusive)
$users = Canvas::courseUserList()->courseRange(23, 25)->get();

// Students only
$students = Canvas::courseUserList()->courses([23, 24, 25])->studentsOnly()->get();

// Custom enrollment type filter
$users = Canvas::courseUserList()->courses([23])->enrollmentType(['student', 'observer'])->get();

// Returns a Collection of ['id' => '...', 'name' => '...', 'email' => '...']
foreach ($users as $user) {
    echo "{$user['id']}: {$user['name']} ({$user['email']})";
}
```

Each result contains `id`, `name`, and `email`. Users enrolled in multiple courses appear once. Requires an account-level token with permission to read course rosters.

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

### User Account Status

```php
$user = User::find(42);

$user->suspend();    // Disables all logins — enrollments and data are preserved
$user->unsuspend();  // Re-enables logins
```

For bulk status changes driven by SIS IDs, use [`SisUserCsvBuilder`](#sis-import) instead.

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

### Export Import Results

After an import, export the per-row result log as CSV:

```php
$result = CsvImporter::for(Course::class)->import('/path/to/courses.csv');

// Get as string
$csv = $result->toCsv();

// Write to file
$result->exportCsv('/path/to/import-log.csv');
```

Output columns: `row`, `id`, `success`, `error`

---

## CSV Export

Export any Canvas model data to CSV.

```php
use JarredCain\CanvasLms\Export\CsvExporter;

// From a collection (loads all into memory)
$courses = Canvas::accountCourses()->all();
$csv = CsvExporter::from($courses)->toString();

// Stream all pages without loading into memory
CsvExporter::fromBuilder(Canvas::accountCourses())
    ->toFile('/path/to/courses.csv');
```

### Explicit Columns

```php
CsvExporter::from($courses)
    ->columns(['id', 'name', 'course_code', 'workflow_state', 'start_at', 'end_at'])
    ->toFile('/path/to/courses.csv');
```

If `columns()` is omitted, all attributes present on the first model are used.

### Custom Header Labels

```php
CsvExporter::from($courses)
    ->columns(['id', 'name', 'sis_course_id', 'workflow_state'])
    ->mapHeaders([
        'sis_course_id'  => 'SIS ID',
        'workflow_state' => 'Status',
    ])
    ->toString();
```

### HTTP Download Response

Stream directly to the browser from a Laravel controller:

```php
return CsvExporter::fromBuilder(Canvas::accountCourses()->onlyPublished())
    ->columns(['id', 'name', 'course_code', 'total_students'])
    ->toResponse('courses.csv');
```

### Export Options

| Method | Description |
|---|---|
| `from(iterable)` | Export from a Collection, array, or LazyCollection |
| `fromBuilder(Builder)` | Stream all pages via `lazy()` — memory-efficient |
| `columns(array)` | Explicit list of attributes to include, in order |
| `mapHeaders(array)` | Map attribute names to display column headers |
| `toString()` | Return CSV as a string |
| `toFile(string)` | Write CSV to a file path |
| `toResponse(string)` | Return a `StreamedResponse` for HTTP download |

---

## SIS Import

Canvas's SIS import endpoint (`POST /api/v1/accounts/:id/sis_imports`) accepts CSV files conforming to the [Canvas SIS CSV format](https://community.instructure.com/en/kb/articles/661611-how-do-i-format-csv-text-files-for-uploading-sis-data-into-a-canvas-account) and processes them asynchronously. This is the standard mechanism for bulk provisioning users, enrollments, and courses at the account level.

> **SIS Import vs. CSV Importer:** `SisImporter` submits files to Canvas's native SIS pipeline (async, SIS IDs). `CsvImporter` calls the REST API row-by-row (sync, Canvas numeric IDs). Use SIS import for bulk provisioning; use `CsvImporter` for targeted field updates.

### Basic Usage

```php
use JarredCain\CanvasLms\Facades\Canvas;

// From a file — Canvas determines the resource type from the filename
$import = Canvas::sisImport()->fromFile('/path/to/users.csv')->submit();

// From a CSV string
$import = Canvas::sisImport()->fromCsv($csvString, 'enrollments.csv')->submit();

// From a zip containing multiple CSV files
$import = Canvas::sisImport()->fromZip('/path/to/sis_bundle.zip')->submit();

// Explicit account (defaults to canvas.account_id from config)
$import = Canvas::sisImport(accountId: 5)->fromFile('/path/to/users.csv')->submit();
```

### Waiting for Completion

`submit()` returns a `SisImport` model immediately. Canvas processes the file asynchronously.

```php
$import = Canvas::sisImport()->fromFile('/path/to/users.csv')->submit();

// Block until Canvas finishes (polls every 2s, default timeout 120s)
$import->wait();
$import->workflow_state; // 'imported', 'failed', 'imported_with_messages'
$import->progress;       // 100

// Or poll manually
while ($import->isPending()) {
    sleep(5);
    $import->refresh();
}
```

### Import Options

```php
Canvas::sisImport()
    ->fromFile('/path/to/users.csv')
    ->batchMode(termId: 3)          // Remove records absent from this file (scoped to a term)
    ->diffing('users-nightly')      // Only process rows that changed since the last run
    ->changeThreshold(20)           // Abort if >20% of records would be deleted
    ->skipDeletes()                 // Never delete — only create and update
    ->overrideSisStickiness()       // Overwrite SIS-sticky fields
    ->submit()
    ->wait();
```

| Method | Description |
|---|---|
| `fromFile(path)` | Load from a local CSV file path |
| `fromCsv(string, filename)` | Load from a raw CSV string |
| `fromZip(path)` | Load from a zip containing multiple SIS CSV files |
| `batchMode(termId)` | Delete records in the term that are absent from this file |
| `diffing(identifier, dropStatus)` | Skip unchanged rows compared to the previous run |
| `skipDeletes()` | Ignore `deleted` status rows — only create and update |
| `changeThreshold(int)` | Abort if more than N% of records would be deleted |
| `overrideSisStickiness()` | Allow overwriting SIS-sticky fields |
| `addSisStickiness()` | Mark all touched fields as SIS-sticky after import |
| `clearSisStickiness()` | Clear the SIS-sticky flag from all touched fields |
| `submit()` | Upload to Canvas and return a `SisImport` |

---

## Suspending Users via SIS

Canvas SIS user CSVs accept a `status` column with values `active`, `suspended`, or `deleted`. `SisUserCsvBuilder` is a fluent builder that generates this CSV and submits it through `SisImporter`.

Use this approach for **bulk** or **SIS-ID-driven** workflows. For single-user suspension via the REST API, use [`User::suspend()`](#user-account-status) instead.

| | `User::suspend()` | `SisUserCsvBuilder` |
|---|---|---|
| **Identifier** | Canvas numeric ID | SIS user ID (`sis_user_id`) |
| **Scale** | Single user | Bulk |
| **Processing** | Immediate | Async (SIS job queue) |

### Building and Submitting

```php
use JarredCain\CanvasLms\Sis\SisUserCsvBuilder;
use JarredCain\CanvasLms\Facades\Canvas;

// Suspend by SIS ID
SisUserCsvBuilder::make()
    ->suspend('sis_001')
    ->suspend('sis_002')
    ->submitVia(Canvas::sisImport())
    ->wait();

// Mix statuses in one import
SisUserCsvBuilder::make()
    ->suspend('sis_001')
    ->activate('sis_002')  // re-activate a suspended user
    ->delete('sis_003')    // remove entirely
    ->submitVia(Canvas::sisImport());
```

### From User Model Instances

When you already have `User` objects, the builder reads `sis_user_id` automatically:

```php
$users = Canvas::accountCourses()->first()->enrollments()->all()
    ->map(fn($e) => $e->user);

SisUserCsvBuilder::make()
    ->suspendUsers($users)
    ->submitVia(Canvas::sisImport());

// Re-activate the same set
SisUserCsvBuilder::make()
    ->activateUsers($users)
    ->submitVia(Canvas::sisImport());
```

> Users without a `sis_user_id` will throw `InvalidArgumentException` — they were not provisioned via SIS and cannot be managed through this path.

### Inspect Before Submitting

```php
// Get the raw CSV string
$csv = SisUserCsvBuilder::make()->suspend('u1')->suspend('u2')->toCsv();

// Write to a file for review
SisUserCsvBuilder::make()->suspend('u1')->toFile('/tmp/suspend_preview.csv');
```

### SisUserCsvBuilder Reference

| Method | Description |
|---|---|
| `suspend(sisUserId)` | Add a row with `status=suspended` |
| `activate(sisUserId)` | Add a row with `status=active` |
| `delete(sisUserId)` | Add a row with `status=deleted` |
| `addRow(sisUserId, status)` | Add a row with an explicit status value |
| `suspendUsers(iterable)` | Bulk-add suspend rows from `User` model instances |
| `activateUsers(iterable)` | Bulk-add activate rows from `User` model instances |
| `toCsv()` | Return the CSV as a string |
| `toFile(path)` | Write the CSV to a file |
| `submitVia(SisImporter)` | Submit via the given importer, return `SisImport` |
| `count()` | Number of rows queued |
| `isEmpty()` | Whether any rows have been added |

---

## User Email Lookup

`userEmailLookup()` searches Canvas for users by email address and returns their Canvas ID, SIS ID, name, and optionally their account status. Input can be a plain array of emails or a CSV file.

> Canvas's user search is fuzzy — the package filters results to exact, case-insensitive email matches.

### From an Array

```php
use JarredCain\CanvasLms\Facades\Canvas;

$results = Canvas::userEmailLookup()
    ->fromEmails(['alice@example.com', 'bob@example.com'])
    ->lookup();

foreach ($results as $result) {
    if ($result->found) {
        echo "{$result->email} → ID: {$result->id}, SIS: {$result->sisUserId}";
    } else {
        echo "{$result->email} → not found";
    }
}
```

### From a CSV File

```php
// Default column header: 'email'
$results = Canvas::userEmailLookup()
    ->fromCsv('/path/to/users.csv')
    ->lookup();

// Custom column header (case-insensitive)
$results = Canvas::userEmailLookup()
    ->fromCsv('/path/to/users.csv', 'Email Address')
    ->lookup();

// From a raw CSV string
$results = Canvas::userEmailLookup()
    ->fromCsvString($csvString)
    ->lookup();
```

Duplicate emails in the input are deduplicated automatically.

### Including Account Status

Add `withStatus()` to also fetch whether each user is active or suspended. This makes one extra API call per found user.

```php
$results = Canvas::userEmailLookup()
    ->fromEmails(['alice@example.com', 'bob@example.com'])
    ->withStatus()
    ->lookup();

foreach ($results as $result) {
    echo "{$result->email}: {$result->status}"; // 'active', 'suspended', or null if not found
}
```

### CSV Output

```php
// Return CSV as a string
$csv = Canvas::userEmailLookup()
    ->fromCsv('/path/to/emails.csv')
    ->withStatus()
    ->toCsv();

// Write directly to a file
Canvas::userEmailLookup()
    ->fromEmails(['alice@example.com'])
    ->toFile('/path/to/results.csv');
```

Output columns: `email`, `id`, `sis_user_id`, `name`, `status` _(only when `withStatus()` is used)_, `found`

### Account Scope

By default, the lookup searches all accounts (uses Canvas `"self"`). To scope to a specific account, pass an account ID via the factory method or the fluent `forAccount()` method:

```php
// Search all accounts (default)
Canvas::userEmailLookup()->fromEmails([...])->lookup();

// Scope to a specific account
Canvas::userEmailLookup(accountId: 5)->fromEmails([...])->lookup();

// Or use the fluent method
Canvas::userEmailLookup()->forAccount(5)->fromEmails([...])->lookup();
```

### `UserEmailLookup` Reference

| Method | Description |
|---|---|
| `fromEmails(array)` | Provide a plain array of email addresses |
| `fromCsv(path, column)` | Read emails from a CSV file (default column: `'email'`) |
| `fromCsvString(csv, column)` | Read emails from a raw CSV string |
| `forAccount(id)` | Scope the lookup to a specific Canvas account |
| `withStatus()` | Also fetch `active`/`suspended` login status per found user |
| `lookup()` | Execute and return a `Collection<UserLookupResult>` |
| `toCsv()` | Execute and return results as a CSV string |
| `toFile(path)` | Execute and write results to a CSV file |

### `UserLookupResult` Properties

| Property | Type | Description |
|---|---|---|
| `$email` | `string` | The email address that was searched |
| `$found` | `bool` | Whether a matching user was found |
| `$id` | `string\|null` | Canvas user ID |
| `$sisUserId` | `string\|null` | SIS user ID (null if not provisioned via SIS) |
| `$name` | `string\|null` | User's full name |
| `$status` | `string\|null` | `'active'` or `'suspended'` — only set when `withStatus()` was used |

---

## Cross-System Field Adapter

`ResourceMapper` provides bidirectional field translation between Canvas and any number of external systems (Salesforce, SQL databases, custom APIs, etc.). Define a mapping once and use it to translate data in either direction, or merge data from three or more systems with **per-field** conflict resolution.

This is designed for integration scenarios such as observer-driven cyclic sync — where Canvas changes propagate to Salesforce and Salesforce changes propagate back to Canvas, with each field knowing which system is its source of truth.

### Defining a Mapper

Each row in the definition array describes one logical field across all connected systems. Reserved keys per row:
- **System names** (e.g. `'canvas'`, `'salesforce'`, `'sql'`) — map to the field name in that system
- **`'priority'`** — ordered system names; first system with a value wins on conflict in `merge()`. Omit to fall back to the global priority passed to `merge()`.
- **`'transforms'`** — direction-keyed callables: `to_{system}` (outbound) and `from_{system}` (inbound during merge)

```php
use JarredCain\CanvasLms\Adapters\ResourceMapper;

$mapper = ResourceMapper::define([
    // Canvas is the source of truth for 'name'
    ['canvas' => 'name',     'salesforce' => 'Full_Name__c',  'sql' => 'full_name',
     'priority' => ['canvas', 'salesforce', 'sql']],

    // Salesforce is the source of truth for dates
    ['canvas' => 'start_at', 'salesforce' => 'Start_Date__c', 'sql' => 'start_date',
     'priority' => ['salesforce', 'canvas', 'sql'],
     'transforms' => ['to_salesforce' => fn($v) => date('Y-m-d', strtotime($v))]],

    // No priority — falls back to whatever is passed to merge()
    ['canvas' => 'sis_user_id', 'salesforce' => 'Student_ID__c', 'sql' => 'student_id'],
]);
```

### Two-Way Translation

```php
// Canvas → Salesforce
$sfPayload = $mapper->from('canvas', $canvasUser->toArray())->to('salesforce');
// ['Full_Name__c' => 'Ada Lovelace', 'Start_Date__c' => '2026-09-01', ...]

// Salesforce → Canvas
$canvasFields = $mapper->from('salesforce', $sfRecord)->to('canvas');
// ['name' => 'Ada Lovelace', 'start_at' => '2026-09-01', ...]
```

Fields absent from the input are silently skipped — partial payloads are safe.

### Three-Way Merge

`merge()` combines data from multiple systems into a single record. Each field resolves independently using its own priority list. Call `for()` on the result to project into any system's field names.

```php
$record = $mapper->merge([
    'canvas'      => $canvasData,
    'salesforce'  => $sfData,
    'sql'         => $sqlRow,
]);

// Each system gets a view based on per-field priority
$record->for('canvas');      // ['name' => 'Ada', 'start_at' => '...', ...]
$record->for('salesforce');  // ['Full_Name__c' => 'Ada', 'Start_Date__c' => '...', ...]
$record->for('sql');         // ['full_name' => 'Ada', 'start_date' => '...', ...]
```

The global `priority` parameter to `merge()` serves as a fallback for fields that don't define their own:

```php
$record = $mapper->merge($sources, priority: ['canvas', 'salesforce', 'sql']);
```

### Loading from Config

Define named mapping templates in `config/canvas.php` under the `adapters` key and load them by name:

```php
// config/canvas.php
'adapters' => [
    'user' => [
        ['canvas' => 'name',       'salesforce' => 'Full_Name__c',  'priority' => ['canvas']],
        ['canvas' => 'email',      'salesforce' => 'Email'],
        ['canvas' => 'sis_user_id','salesforce' => 'Student_ID__c'],
    ],
    'course' => [
        ['canvas' => 'name',     'salesforce' => 'Course_Name__c',  'priority' => ['canvas']],
        ['canvas' => 'start_at', 'salesforce' => 'Start_Date__c',   'priority' => ['salesforce', 'canvas']],
        ['canvas' => 'end_at',   'salesforce' => 'End_Date__c',     'priority' => ['salesforce', 'canvas']],
    ],
],
```

```php
$mapper = ResourceMapper::fromConfig('user');
$sfPayload = $mapper->from('canvas', $user->toArray())->to('salesforce');
```

### Transforms

Transforms are callables defined per-field in the `transforms` array. They run automatically during translation and merging:

```php
['canvas' => 'start_at', 'salesforce' => 'Start_Date__c',
 'transforms' => [
     'to_salesforce'   => fn($v) => date('Y-m-d', strtotime($v)),     // outbound to SF
     'from_salesforce' => fn($v) => Carbon::parse($v)->toIso8601String(), // inbound from SF
 ]],
```

| Key | Applied when |
|---|---|
| `to_{system}` | Translating outbound (`->to()`) or projecting from a merge (`->for()`) |
| `from_{system}` | Ingesting during `merge()` from that system |

### Pushing Mutations into Canvas

`AdapterService` wraps the mapper and Canvas API — translate an external payload and apply it to Canvas in one call:

```php
use JarredCain\CanvasLms\Adapters\AdapterService;

// Translate Salesforce payload → Canvas field names → PUT /api/v1/users/42
app(AdapterService::class)->push('user', 42, 'salesforce', [
    'Full_Name__c' => 'Ada Lovelace',
    'Email'        => 'ada@university.edu',
]);

// Translate only — inspect before pushing
$canvasPayload = app(AdapterService::class)->translate('course', 'salesforce', $sfRecord);
```

Supported resource types: `user`, `course`, `group`, `enrollment`, `account`. Extend `AdapterService` and override `builderForResource()` to add more.

### Observer Pattern Example

```php
// In a Canvas course observer — Canvas owns 'name', Salesforce owns dates
public function updated(Course $course): void
{
    $mapper = ResourceMapper::fromConfig('course');

    // Only push fields where Canvas is the master
    $sfPayload = $mapper->from('canvas', [
        'name' => $course->name,
    ])->to('salesforce');

    Salesforce::updateRecord($course->sf_id, $sfPayload);
}

// In a Salesforce webhook controller — Salesforce owns start/end dates
public function handleCourseUpdate(Request $request): void
{
    app(AdapterService::class)->push(
        'course',
        $request->canvas_course_id,
        'salesforce',
        $request->json()->all()
    );
}
```

### HTTP Mutation Endpoint (optional)

Enable an HTTP endpoint for receiving external system payloads directly:

```php
// config/canvas.php
'adapters' => [
    'routes_enabled' => true,
    // ...
],
```

This registers `POST /canvas/adapter/{resource}/{id}`. The route uses `api` middleware by default.

**Publish the controller stub** to customize authentication, validation, and error handling before enabling in production:

```bash
php artisan vendor:publish --tag=canvas-adapter
```

**Request format:**

```http
POST /canvas/adapter/user/42
X-Canvas-Source-System: salesforce
Content-Type: application/json

{"Full_Name__c": "Ada Lovelace", "Email": "ada@university.edu"}
```

> The default controller has no authentication. Always publish and secure it before enabling in production.

### `ResourceMapper` Reference

| Method | Description |
|---|---|
| `ResourceMapper::define(array)` | Build a mapper from a plain array of field rows |
| `ResourceMapper::fromConfig(string)` | Load a named mapper from `config('canvas.adapters.key')` |
| `from(system, data)` | Begin a two-way translation — chain `->to(system)` to complete it |
| `merge(sources, priority)` | Merge data from multiple systems; global priority is fallback for fields without their own |

| Result method | Description |
|---|---|
| `PendingTranslation::to(system)` | Translate to the target system's field names; returns `array` |
| `MappedRecord::for(system)` | Project merged record into the system's field names; returns `array` |
| `MappedRecord::toArray()` | Raw canonical store (mapping index → value) |

### `AdapterService` Reference

| Method | Description |
|---|---|
| `translate(resource, fromSystem, data)` | Translate $data from $fromSystem to Canvas field names using the named config mapper |
| `push(resource, canvasId, fromSystem, data)` | Translate and update the Canvas resource via the API; returns the updated model |

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
