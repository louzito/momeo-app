<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\AdminPlanningApiController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

#[CoversClass(AdminPlanningApiController::class)]
final class AdminPlanningApiContractTest extends TestCase
{
    public function testControllerExposesARealCrudRoute(): void
    {
        $class = new \ReflectionClass(AdminPlanningApiController::class);
        $route = $class->getAttributes(Route::class)[0]->newInstance();
        self::assertSame('/api/v2/admin/plannings', $route->getPath());

        $methods = array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $class->getMethods());
        foreach (['index', 'show', 'create', 'update', 'delete'] as $action) self::assertContains($action, $methods);
    }
}
