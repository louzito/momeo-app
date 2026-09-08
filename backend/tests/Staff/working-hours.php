<?php

// Standalone regression checks: php tests/Staff/working-hours.php
require_once __DIR__.'/../../src/Staff/WorkingHours.php';
require_once __DIR__.'/../../src/Entity/StaffMember.php';

use App\Staff\WorkingHours;
use App\Entity\StaffMember;

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$hours = [
    ['start' => '09:00', 'end' => '12:00', 'days' => ['monday', 'tuesday']],
    ['start' => '13:00', 'end' => '19:00', 'days' => ['monday', 'tuesday']],
];
$member = new StaffMember();
$member->setWorkingHours(WorkingHours::normalize($hours));
check(json_decode(json_encode($member->getWorkingHours()), true) === $hours, 'JSON round trip');
$tz = new DateTimeZone('Europe/Paris');
foreach ([['09:00', '12:00', true], ['13:00', '19:00', true], ['12:00', '13:00', false], ['11:30', '13:30', false], ['18:30', '19:30', false], ['08:30', '09:30', false], ['13:00', '13:00', false]] as [$start, $end, $expected]) {
    check(WorkingHours::contains($hours, new DateTimeImmutable('2026-09-07 '.$start, $tz), new DateTimeImmutable('2026-09-07 '.$end, $tz), $tz) === $expected, "{$start}–{$end}");
}
check(!WorkingHours::contains($hours, new DateTimeImmutable('2026-09-09 09:00', $tz), new DateTimeImmutable('2026-09-09 10:00', $tz), $tz), 'Day without ranges');
check(!WorkingHours::contains($hours, new DateTimeImmutable('2026-09-07 18:00', $tz), new DateTimeImmutable('2026-09-08 10:00', $tz), $tz), 'Across midnight');
check(WorkingHours::contains($hours, new DateTimeImmutable('2026-09-07 07:00Z'), new DateTimeImmutable('2026-09-07 10:00Z'), $tz), 'UTC conversion');
$legacy = ['monday' => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], 'tuesday' => ['enabled' => false, 'start' => '09:00', 'end' => '18:00']];
check(WorkingHours::normalize($legacy) === [['start' => '09:00', 'end' => '18:00', 'days' => ['monday']]], 'Legacy preservation');
check(WorkingHours::contains($legacy, new DateTimeImmutable('2026-09-07 12:00', $tz), new DateTimeImmutable('2026-09-07 13:00', $tz), $tz), 'Legacy booking');
foreach ([
    [['start' => '12:00', 'end' => '09:00', 'days' => ['monday']]],
    [['start' => '09:00', 'end' => '09:00', 'days' => ['monday']]],
    [['start' => '24:00', 'end' => '25:00', 'days' => ['monday']]],
    [['start' => '09:00', 'end' => '12:00', 'days' => []]],
    [['start' => '09:00', 'end' => '12:00', 'days' => ['invalid']]],
    [$hours[0], ['start' => '11:00', 'end' => '14:00', 'days' => ['tuesday']]],
] as $invalid) {
    try { WorkingHours::normalize($invalid); throw new RuntimeException('Invalid range accepted'); }
    catch (DomainException $exception) { check($exception->getMessage() !== '', 'Explicit error'); }
}
check(count(WorkingHours::normalize([$hours[0], ['start' => '12:00', 'end' => '14:00', 'days' => ['monday']]])) === 2, 'Adjacent ranges');
check(count(WorkingHours::normalize([$hours[0], ['start' => '09:00', 'end' => '12:00', 'days' => ['sunday']]])) === 2, 'Independent days');
$member->setWorkingHours(WorkingHours::normalize([$hours[1]]));
check(!WorkingHours::contains($member->getWorkingHours(), new DateTimeImmutable('2026-09-07 10:00', $tz), new DateTimeImmutable('2026-09-07 11:00', $tz), $tz), 'Deleted range');
$member->setWorkingHours(WorkingHours::normalize([]));
check($member->getWorkingHours() === [], 'Delete all ranges');
echo "Working hours checks passed.\n";
