<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Deals\Domain\Deal;
use Foresi\Deals\Domain\Stage;
use Foresi\Identity\Application\ManageApiTokens;
use Foresi\Identity\Domain\ApiScope;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Party\Domain\PartyKind;
use Foresi\Party\Domain\PartyRole;
use Foresi\Work\Application\ManageProjects;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

// The world of the inbox spec: a client the spec matches a mail to by hand (its address is not the
// sender's), a deal of that client the spec links the mail to, and the one project of that client,
// which Create task picks for a mail matched to it. The inbox addresses and the mail are made by the
// spec itself. An agent's token of the owner, which may read the inbox and propose suggestions only, is
// in var/e2e-inbox-agent.json for the spec (a throwaway of this world's database).
\assert(isset($owner));
$inboxClient = $createParty->createFrom($party(PartyKind::Company, 'Client A s.r.o.', [PartyRole::Customer], 'billing@example.com', 8, '12345679', 'CZ12345679'));
$stages = $entityManager->getRepository(Stage::class)->findBy([], ['position' => 'ASC']);
$firstStage = $stages[0] ?? throw new LogicException('The migration seeds the stages of a deal.');
$entityManager->persist(new Deal('Roof audit', '', (string) $inboxClient->id(), (string) $owner->id(), $firstStage->id(), new DateTimeImmutable()));
$entityManager->flush();
// The project is made as the owner makes it, signed in for the application services.
$tokenStorage->setToken(new UsernamePasswordToken(new SecurityUser($owner->id(), $owner->email(), 'hash'), 'main', ['ROLE_USER']));
$manageProjects = $container->get('e2e.manage_projects');
\assert($manageProjects instanceof ManageProjects);
$manageProjects->create('Warehouse roof', (string) $inboxClient->id());
$entityManager->flush();
$tokenStorage->setToken(null);
$manageApiTokens = $container->get('e2e.manage_api_tokens');
\assert($manageApiTokens instanceof ManageApiTokens);
$agent = $manageApiTokens->issueAsOperator($owner->id(), 'Mail agent', [ApiScope::of('inbox', false), ApiScope::of('inbox_suggestions', true)]);
file_put_contents(dirname(__DIR__, 3).'/var/e2e-inbox-agent.json', json_encode(['token' => $agent->secret], \JSON_THROW_ON_ERROR));
