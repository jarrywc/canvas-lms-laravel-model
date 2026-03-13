<?php

namespace JarredCain\CanvasLms;

use JarredCain\CanvasLms\Auth\AuthManager;
use JarredCain\CanvasLms\Auth\OAuth2\OAuth2Handler;
use JarredCain\CanvasLms\Http\CanvasClient;
use JarredCain\CanvasLms\Models\Account;
use JarredCain\CanvasLms\Models\Assignment;
use JarredCain\CanvasLms\Models\SubAccount;
use JarredCain\CanvasLms\Models\Course;
use JarredCain\CanvasLms\Models\Enrollment;
use JarredCain\CanvasLms\Models\Group;
use JarredCain\CanvasLms\Models\Module;
use JarredCain\CanvasLms\Models\Page;
use JarredCain\CanvasLms\Models\Quiz;
use JarredCain\CanvasLms\Models\Section;
use JarredCain\CanvasLms\Models\Submission;
use JarredCain\CanvasLms\Models\User;
use JarredCain\CanvasLms\Query\Builder;

class Canvas
{
    public function __construct(
        private readonly CanvasClient $client,
        private readonly AuthManager $auth,
        private readonly ?OAuth2Handler $oauth2Handler = null
    ) {
    }

    /**
     * Return a new Canvas instance scoped to a specific OAuth2 storage key.
     * Returns a new instance — does NOT mutate the singleton (safe for Octane).
     */
    public function actingAs(string $storageKey): static
    {
        $this->auth->setOAuthStorageKey($storageKey);
        $token = $this->auth->getToken();

        return new static(
            $this->client->withToken($token),
            $this->auth,
            $this->oauth2Handler
        );
    }

    public function oauth(): OAuth2Handler
    {
        if (!$this->oauth2Handler) {
            throw new \RuntimeException(
                'OAuth2 is not configured. Set CANVAS_AUTH_DRIVER=oauth2 and configure oauth2 credentials.'
            );
        }

        return $this->oauth2Handler;
    }

    // -------------------------------------------------------------------------
    // Top-level resource query builders
    // -------------------------------------------------------------------------

    public function accounts(): Builder
    {
        return $this->builderFor(Account::class);
    }

    public function users(): Builder
    {
        return $this->builderFor(User::class);
    }

    public function courses(): Builder
    {
        return $this->builderFor(Course::class);
    }

    /**
     * List ALL courses for an account/organization.
     * Hits GET /api/v1/accounts/:id/courses — returns org-wide results, not scoped to current user.
     *
     * Requires admin permissions on the account.
     * Defaults to the account_id set in config/canvas.php.
     *
     * @param int|string|null $accountId  Override the configured account ID
     */
    public function accountCourses(int|string $accountId = null): Builder
    {
        $id = $accountId ?? config('canvas.account_id', 1);
        return $this->builderFor(Course::class)->forAccount($id);
    }

    /**
     * List courses scoped to a specific subaccount.
     * Hits GET /api/v1/accounts/:subaccount_id/courses.
     *
     * In Canvas, subaccounts are accounts — this is a named shortcut that
     * makes the intent explicit when working with subaccount hierarchies.
     *
     * @param int|string $subAccountId  The Canvas subaccount ID
     */
    public function subAccountCourses(int|string $subAccountId): Builder
    {
        return $this->builderFor(Course::class)->forSubAccount($subAccountId);
    }

    /**
     * Find a subaccount by name under the given account (defaults to root account).
     * Makes one API call — searches GET /api/v1/accounts/:id/sub_accounts?search_term=name.
     * Returns the first matching SubAccount, or null if not found.
     *
     * Usage:
     *   $sub = Canvas::findSubAccount('Math Department');
     *   Canvas::subAccountCourses($sub->id)->get();
     *
     * @param string          $name       Subaccount name to search for
     * @param int|string|null $accountId  Root account to search under (defaults to canvas.account_id)
     */
    public function findSubAccount(string $name, int|string $accountId = null): ?SubAccount
    {
        $id = $accountId ?? config('canvas.account_id', 1);

        /** @var SubAccount|null $result */
        $result = $this->builderFor(SubAccount::class)
            ->forAccount($id)
            ->search($name)
            ->first();

        return $result;
    }

    public function groups(): Builder
    {
        return $this->builderFor(Group::class);
    }

    public function enrollments(): Builder
    {
        return $this->builderFor(Enrollment::class);
    }

    // -------------------------------------------------------------------------
    // Lazy model factories for relationship traversal without API calls
    // Canvas::course(42)->enrollments()->get()
    // -------------------------------------------------------------------------

    public function account(int|string $id): Account
    {
        return Account::newWithId($id);
    }

    public function user(int|string $id): User
    {
        return User::newWithId($id);
    }

    public function course(int|string $id): Course
    {
        return Course::newWithId($id);
    }

    public function group(int|string $id): Group
    {
        return Group::newWithId($id);
    }

    public function section(int|string $id): Section
    {
        return Section::newWithId($id);
    }

    public function assignment(int|string $id): Assignment
    {
        return Assignment::newWithId($id);
    }

    public function quiz(int|string $id): Quiz
    {
        return Quiz::newWithId($id);
    }

    public function module(int|string $id): Module
    {
        return Module::newWithId($id);
    }

    public function page(int|string $id): Page
    {
        return Page::newWithId($id);
    }

    public function submission(int|string $id): Submission
    {
        return Submission::newWithId($id);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function builderFor(string $modelClass): Builder
    {
        return (new Builder($modelClass))->setClient($this->client);
    }
}
