<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Attachment\Application\AttachmentUpload;
use Foresi\Attachment\Application\UploadAttachment;
use Foresi\Identity\Domain\Membership;
use Foresi\Identity\Domain\Role;
use Foresi\Identity\Domain\User;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use Foresi\Party\Domain\LegalEntity;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Domain\VatStatus;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;
use Foresi\Timesheet\Application\ManageTime;
use Foresi\Timesheet\Application\ProjectTimeSettings;
use Foresi\Work\Application\ManageComments;
use Foresi\Work\Application\ManageLabels;
use Foresi\Work\Application\ManageProjects;
use Foresi\Work\Application\ManageSections;
use Foresi\Work\Application\ManageSharing;
use Foresi\Work\Application\ManageTasks;
use Foresi\Work\Domain\LabelColour;
use Foresi\Work\Domain\Priority;
use Foresi\Work\Domain\ProjectStatus;
use Foresi\Work\Domain\ProjectVisibility;
use Foresi\Work\Domain\StatusType;
use Foresi\Work\Domain\WorkflowTemplate;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// Projects: the owner of "Example Projects" signs in for the application services, the way the
// browser would, and keeps a project for a client with tasks in every state of the workflow.
// Its today is the today of the instance clock, the day the due dates below count from.
$today = $instanceClock->today();
$manageProjects = $container->get('e2e.manage_projects');
$manageSections = $container->get('e2e.manage_sections');
$manageTasks = $container->get('e2e.manage_tasks');
$manageLabels = $container->get('e2e.manage_labels');
$manageComments = $container->get('e2e.manage_comments');
$manageSharing = $container->get('e2e.manage_sharing');
$manageTime = $container->get('e2e.timesheet.manage_time');
$projectTimeSettings = $container->get('e2e.timesheet.project_time_settings');
$uploadAttachment = $container->get('e2e.upload_attachment');
\assert($manageComments instanceof ManageComments);
\assert($manageSharing instanceof ManageSharing);
\assert($manageTime instanceof ManageTime);
\assert($projectTimeSettings instanceof ProjectTimeSettings);
\assert($uploadAttachment instanceof UploadAttachment);
\assert($manageProjects instanceof ManageProjects);
\assert($manageSections instanceof ManageSections);
\assert($manageTasks instanceof ManageTasks);
\assert($manageLabels instanceof ManageLabels);

$teamUser = new User('member@example.com', $hash, 'Jana Example', 'cs');
$entityManager->persist($teamUser);
$teamMember = new Membership($teamUser, Role::Member);
$entityManager->persist($teamMember);
$entityManager->flush();
$projectClient = $createParty->createFrom($party(PartyKind::Company, 'Example Client s.r.o.', [PartyRole::Customer], 'client@example.com', 8, '12345695', 'CZ12345695'));
// A guest for the client: sees only what is shared with them.
$guestUser = new User('guest@example.com', $hash, 'Guest Example', 'cs');
$entityManager->persist($guestUser);
$guestMembership = new Membership($guestUser, Role::Guest, $projectClient);
$entityManager->persist($guestMembership);
$entityManager->flush();
$projectOwner = $entityManager->getRepository(User::class)->findOneBy(['email' => 'projects@example.com']) ?? throw new LogicException('The owner of the projects world is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($projectOwner->id(), $projectOwner->email(), 'hash'), 'main', ['ROLE_USER']));

$design = $manageLabels->create('Design', LabelColour::Green);
$bug = $manageLabels->create('Bug', LabelColour::Red);
// The client's time is billable by default, Timesheet's custom field of the project.
$website = $manageProjects->create('Website', $projectClient->id(), WorkflowTemplate::Development, 'The new website of the client.', ['timesheet_billable_by_default' => true]);
$backlog = $manageSections->add($website->id(), 'Backlog');
$shared = $manageSections->add($website->id(), 'Shared with client');
// Open, In progress, Review, Testing, Blocked, Done, Closed.
$statuses = $entityManager->getRepository(ProjectStatus::class)->findBy(['project' => $website], ['position' => 'ASC']);
$ownerMembership = $entityManager->getRepository(Membership::class)->findOneBy(['user' => $projectOwner]) ?? throw new LogicException('The owner membership is missing.');
$day = static fn (string $modifier): DateTimeImmutable => $today->modify($modifier);

$homepage = $manageTasks->create($website->id(), 'Design the homepage', $shared->id(), null, $statuses[1]->id(), $ownerMembership->id(), $day('-2 days'), Priority::High);
$manageTasks->setLabels($homepage->id(), [$design->id()]);
// Estimated before the comments: a history step in the same second as a comment comes first, so the
// discussion reads the same in every run.
$manageTasks->setEstimate($homepage->id(), 300);
$manageComments->add($homepage->id(), sprintf("First sketch is ready, **please review**.\n\n- hero with the new logo\n- a short pitch\n\n@[Jana Example](membership:%s) can you check the copy?", $teamMember->id()), true);
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($teamUser->id(), $teamUser->email(), 'hash'), 'main', ['ROLE_USER']));
$manageComments->add($homepage->id(), 'Looks good, the *pitch* reads well.', false);
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($projectOwner->id(), $projectOwner->email(), 'hash'), 'main', ['ROLE_USER']));
// A small wireframe PNG and a one-page PDF, both written for the occasion and handed over like an upload.
$png = tempnam(sys_get_temp_dir(), 'seed');
$sketch = imagecreatetruecolor(320, 200);
\assert(false !== $sketch);
$paper = (int) imagecolorallocate($sketch, 0xF4, 0xF2, 0xEC);
$ink = (int) imagecolorallocate($sketch, 0x2F, 0x4A, 0x3C);
$soft = (int) imagecolorallocate($sketch, 0xC9, 0xD6, 0xCD);
imagefill($sketch, 0, 0, $paper);
imagefilledrectangle($sketch, 0, 0, 319, 28, $ink);
imagefilledrectangle($sketch, 24, 48, 296, 120, $soft);
imagefilledrectangle($sketch, 24, 136, 144, 184, $soft);
imagefilledrectangle($sketch, 176, 136, 296, 184, $soft);
imagepng($sketch, $png);
$uploadAttachment->upload('task', $homepage->id(), new AttachmentUpload($png, 'homepage-sketch.png', (int) filesize($png)));
$pdf = tempnam(sys_get_temp_dir(), 'seed');
file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\ntrailer<</Root 1 0 R/Size 4>>\n%%EOF\n");
$uploadAttachment->upload('task', $homepage->id(), new AttachmentUpload($pdf, 'brief.pdf', (int) filesize($pdf)));
$palette = $manageTasks->create($website->id(), 'Pick the colour palette', $shared->id(), $homepage->id(), $statuses[0]->id(), $teamMember->id());
$contact = $manageTasks->create($website->id(), 'Fix the contact form', $backlog->id(), null, $statuses[0]->id(), $ownerMembership->id(), $day('+0 days'), Priority::Urgent);
$manageTasks->setLabels($contact->id(), [$bug->id()]);
$copy = $manageTasks->create($website->id(), 'Write the copy for the about page', $backlog->id(), null, $statuses[0]->id(), $teamMember->id(), $day('+7 days'), Priority::Medium);
$manageTasks->create($website->id(), 'Review the cookie banner', $shared->id(), null, $statuses[2]->id(), $teamMember->id(), null, Priority::Low);
$domain = $manageTasks->create($website->id(), 'Set up the domain', $backlog->id(), null, $statuses[5]->id(), $ownerMembership->id());
// What the guest of the client sees: the section of the website meant for them, and one task of a
// project of the office that only chosen people see; nothing else of either.
$manageSharing->shareSection($shared->id(), $guestMembership->id());
$office = $manageProjects->create('Office move', null, WorkflowTemplate::Simple, '');
$manageSharing->changeVisibility($office->id(), ProjectVisibility::Chosen);
$lease = $manageTasks->create($office->id(), 'Sign the lease');
$manageSharing->shareTask($lease->id(), $guestMembership->id());
$manageTasks->create($office->id(), 'Order the moving boxes');
// The inbox of the owner, from Jana: a mention, and a task given to them.
$launch = $manageProjects->create('Launch', null, WorkflowTemplate::Simple, '');
$launchDone = $entityManager->getRepository(ProjectStatus::class)->findOneBy(['project' => $launch, 'type' => StatusType::Done]) ?? throw new LogicException('The done status of Launch is missing.');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($teamUser->id(), $teamUser->email(), 'hash'), 'main', ['ROLE_USER']));
$manageComments->add($domain->id(), sprintf('@[Projects Example](membership:%s) the DNS records are in, can you check them?', $ownerMembership->id()), true);
$manageTasks->create($launch->id(), 'Book the launch venue', null, null, $launchDone->id(), $ownerMembership->id());
// Time for the time spec, this week and the week before, Monday to Sunday: always on or before
// today, so the previous week reads the same whatever day the tests run. Jana logs on her tasks;
// the owner on tasks, once on the project without a task and once not billable, though the
// project of a client bills its time by default. "Design the homepage" is estimated at 5 h above.
$thisMonday = $today->modify('monday this week');
$lastMonday = $thisMonday->modify('-7 days');
$manageTime->log($website->id(), $palette->id(), $lastMonday->modify('+1 day'), 150, note: 'Three palettes for the client');
$manageTime->log($website->id(), $copy->id(), $thisMonday, 60, note: 'First draft of the about page');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($projectOwner->id(), $projectOwner->email(), 'hash'), 'main', ['ROLE_USER']));
$manageTime->log($website->id(), $homepage->id(), $lastMonday, 120, note: 'Wireframes for the hero');
$manageTime->log($website->id(), $contact->id(), $lastMonday->modify('+2 days'), 45, note: 'Reproduced the error in Safari');
$manageTime->log($website->id(), $homepage->id(), $lastMonday->modify('+3 days'), 60, note: 'Internal review of the sketches', billable: false);
$manageTime->log($website->id(), null, $lastMonday->modify('+4 days'), 30, note: 'Call with the client');
$manageTime->log($website->id(), $homepage->id(), $thisMonday, 90, note: 'Second round of the hero');
// For the time invoicing spec: the firm that bills the client, the hourly rate of "Website" and
// billable time of last month not invoiced yet, the period the dialog opens with. Early in last
// month, so it never falls in the two weeks the time spec reads, and on tasks whose time no other
// spec counts: the owner's on "Set up the domain" and on the project, Jana's on the palette.
$agency = $firmAccount(new LegalEntity('Example Agency s.r.o.', new RegistrationNumber('CZ', '12345687'), VatId::fromString('CZ12345687'), $address(2), VatStatus::Registered, 'Example register, entry C 4321'));
$entityManager->persist($agency);
$agencySeries = new Series(Series::STANDARD, SeriesFormat::fromString(Series::STANDARD_FORMAT), DocumentKind::Invoice);
$agencySeries->markDefault();
$entityManager->persist($agencySeries);
$entityManager->flush();
$projectTimeSettings->setHourlyRate($website->id(), Money::of('1200.00', Currency::of('CZK')));
$lastMonth = $today->modify('first day of last month');
$manageTime->log($website->id(), $domain->id(), $lastMonth->modify('+1 day'), 90, note: 'DNS records and the certificate');
$manageTime->log($website->id(), null, $lastMonth->modify('+2 days'), 30, note: 'Kick-off call with the client');
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($teamUser->id(), $teamUser->email(), 'hash'), 'main', ['ROLE_USER']));
$manageTime->log($website->id(), $palette->id(), $lastMonth->modify('+3 days'), 60, note: 'Mood board for the palette');
$tokenStorage->setToken(null);
