<?php

namespace JarredCain\CanvasLms\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \JarredCain\CanvasLms\Canvas actingAs(string $storageKey)
 * @method static \JarredCain\CanvasLms\Auth\OAuth2\OAuth2Handler oauth()
 * @method static \JarredCain\CanvasLms\Query\Builder accounts()
 * @method static \JarredCain\CanvasLms\Query\Builder users()
 * @method static \JarredCain\CanvasLms\Query\Builder courses()
 * @method static \JarredCain\CanvasLms\Query\Builder accountCourses(int|string|null $accountId = null)
 * @method static \JarredCain\CanvasLms\Query\Builder subAccountCourses(int|string $subAccountId)
 * @method static \JarredCain\CanvasLms\Models\SubAccount|null findSubAccount(string $name, int|string $accountId = null)
 * @method static \JarredCain\CanvasLms\Query\Builder groups()
 * @method static \JarredCain\CanvasLms\Query\Builder enrollments()
 * @method static \JarredCain\CanvasLms\Models\Account account(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\User user(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Course course(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Group group(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Section section(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Assignment assignment(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Quiz quiz(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Module module(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Page page(int|string $id)
 * @method static \JarredCain\CanvasLms\Models\Submission submission(int|string $id)
 * @method static \JarredCain\CanvasLms\Utilities\CourseUserCollector courseUserList()
 * @method static \JarredCain\CanvasLms\Utilities\UnenrolledUserCollector unenrolledUsers(int|string|null $accountId = null)
 * @method static \JarredCain\CanvasLms\Sis\SisImporter sisImport(int|string|null $accountId = null)
 *
 * @see \JarredCain\CanvasLms\Canvas
 */
class Canvas extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'canvas';
    }
}
