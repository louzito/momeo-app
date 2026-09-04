<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\TenantDoctorCommand;
use App\Tenant\TenantDoctorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class TenantDoctorCommandTest extends TestCase
{
    public function testItPrintsStructuredStatusesAndSucceedsWithoutBlockingError(): void
    {
        $doctor = $this->createMock(TenantDoctorInterface::class);
        $doctor->expects(self::once())->method('inspect')->with('centre-test')->willReturn([
            ['status' => 'OK', 'check' => 'Registre', 'detail' => 'Tenant présent dans le registre.'],
            ['status' => 'WARN', 'check' => 'Registre', 'detail' => 'Tenant désactivé.'],
        ]);

        $tester = new CommandTester(new TenantDoctorCommand($doctor));

        self::assertSame(Command::SUCCESS, $tester->execute(['slug' => ' Centre-Test ']));
        self::assertStringContainsString('OK', $tester->getDisplay());
        self::assertStringContainsString('WARN', $tester->getDisplay());
        self::assertStringContainsString('Tenant désactivé', $tester->getDisplay());
    }

    public function testItFailsWhenABlockingErrorIsReportedWithoutLeakingASecret(): void
    {
        $secret = 'mysql://user:very-secret-password@database/tenant';
        $doctor = $this->createMock(TenantDoctorInterface::class);
        $doctor->method('inspect')->willReturn([
            ['status' => 'ERROR', 'check' => 'Connexion DB', 'detail' => 'Connexion impossible.'],
        ]);

        $tester = new CommandTester(new TenantDoctorCommand($doctor));

        self::assertSame(Command::FAILURE, $tester->execute(['slug' => 'centre-test']));
        self::assertStringContainsString('ERROR', $tester->getDisplay());
        self::assertStringNotContainsString($secret, $tester->getDisplay());
        self::assertStringNotContainsString('very-secret-password', $tester->getDisplay());
    }
}
