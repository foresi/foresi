<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Infrastructure\Doctrine\DoctrineItemStore;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNotes;
use Foresi\Tests\Inbox\Support\InboxMail;
use Foresi\Tests\Inbox\Support\NeedsTheAddressBook;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

/**
 * The actions modules declare on an inbox item, on its page: buttons in the
 * header for the ones that apply, a dialog built from the operation's fields and
 * filled from the mail, and what it made added to the item. The module of the
 * tests (tests/Inbox/Fixtures/Action) stands for a customer's module: Inbox
 * names none of its actions.
 */
final class InboxActionScreensTest extends InboxScreenTestCase
{
    use NeedsTheAddressBook;

    private const string NOTE = 'inbox.actions.fixture_note';
    private const string CONTACT_NOTE = 'inbox.actions.fixture_contact_note';

    public function testACustomerModuleActionShowsAButtonWithoutInboxChanges(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id);

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('[data-inbox-action="'.self::NOTE.'"]');
        self::assertCount(1, $button);
        self::assertSame('/inbox/'.$id.'/actions/'.self::NOTE, $button->attr('href'));
        self::assertNotNull($button->attr('data-dialog'));
        self::assertCount(1, $crawler->filter('[data-inbox-action-menu="'.self::NOTE.'"]'), 'The phone menu has it too.');
    }

    public function testTheButtonsFollowTheConditionsAndTheWeightsAndComeBeforeLink(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $offered = $this->offered($id);
        self::assertContains(self::NOTE, $offered);
        self::assertNotContains(self::CONTACT_NOTE, $offered, 'Its condition wants a matched contact.');
        $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::CONTACT_NOTE);
        self::assertResponseStatusCodeSame(404);

        $this->createContact($id);
        $offered = $this->offered($id);
        self::assertContains(self::CONTACT_NOTE, $offered);
        self::assertLessThan(array_search(self::CONTACT_NOTE, $offered, true), array_search(self::NOTE, $offered, true), 'By weight: 50 before 60.');

        $buttons = $this->client->getCrawler()->filter('[data-inbox-action], [data-inbox-link]')->each(static fn (Crawler $node): string => $node->attr('data-inbox-action') ?? 'link');
        self::assertSame('link', end($buttons), 'The actions come before Link.');
    }

    public function testTheActionOpensInADialog(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE, server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('#inbox-action-form'));
        self::assertCount(1, $crawler->filter('button[type=submit][form="inbox-action-form"]'));
    }

    public function testTheDialogIsBuiltFromTheFieldsAndFilledFromTheMail(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: 'Call me about the offer'));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);

        self::assertResponseIsSuccessful();
        self::assertSame('Call me about the offer', $crawler->filter('#inbox-action-text')->attr('value'));
        self::assertSame('200', $crawler->filter('#inbox-action-text')->attr('maxlength'));
        self::assertSame(['mail' => 'Mail', 'call' => 'Call'], $this->options($crawler, '#inbox-action-note_kind'));
        self::assertSame('mail', $crawler->filter('#inbox-action-note_kind option[selected]')->attr('value'));
        self::assertSame('checkbox', $crawler->filter('#inbox-action-fail')->attr('type'));
        $markDone = $crawler->filter('#inbox-action-mark_done');
        self::assertSame(['checkbox', true], [$markDone->attr('type'), null !== $markDone->attr('checked')]);
        $names = $crawler->filter('#inbox-action-form [name]')->each(static fn (Crawler $node): string => (string) $node->attr('name'));
        self::assertSame('markDone', end($names), 'Mark the item done comes last.');
        self::assertNotContains('inboxItemId', $names, 'The item is the address.');
    }

    public function testSavingMakesTheRecordAddsItToTheItemAndMarksItDone(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['text'] = 'Call back on Monday';
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $notes = self::getContainer()->get(FixtureNotes::class); // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only, and PHPStan reads the dev container)
        self::assertInstanceOf(FixtureNotes::class, $notes);
        self::assertSame(['Call back on Monday'], array_values($notes->made));
        $item = $this->item($id);
        self::assertSame(ItemState::Done, $item->state());
        self::assertSame([[FixtureNotes::KIND, (string) array_key_first($notes->made)]], $this->outputs($id));
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Saved and added to what came of this mail.', $page->filter('main')->text());
        self::assertSame('Fixture note', $page->filter('[data-inbox-output-kind]')->text(), 'A kind no module offers to activities shows as its kind, without a link.');
        self::assertCount(0, $page->filter('[data-inbox-output-unavailable]'));
    }

    public function testUntickingMarkDoneKeepsTheItemNew(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);
        $form = $crawler->filter('#inbox-action-form')->form();
        $this->checkbox($form, 'markDone')->untick();
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        self::assertSame(ItemState::New, $this->item($id)->state());
        self::assertCount(1, $this->outputs($id));
    }

    public function testARefusalShowsOnItsFieldAndMakesNothing(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);
        $form = $crawler->filter('#inbox-action-form')->form();
        $this->checkbox($form, 'fail')->tick();
        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('The note failed, as the test asked.', $crawler->filter('#inbox-action-fail-error')->text());
        self::assertSame('true', $crawler->filter('#inbox-action-fail')->attr('aria-invalid'));
        self::assertSame([], $this->outputs($id));
        self::assertSame(ItemState::New, $this->item($id)->state());
    }

    public function testAnInvalidValueShowsOnItsFieldAndARequiredOneTooAsTheApiChecksThem(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));
        $token = $this->tokenFrom('/inbox/'.$id.'/actions/'.self::NOTE);

        $crawler = $this->client->request('POST', '/inbox/'.$id.'/actions/'.self::NOTE, ['_token' => $token, 'text' => str_repeat('x', 201), 'noteKind' => 'mail']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('at most 200 characters', $crawler->filter('#inbox-action-text-error')->text());

        $crawler = $this->client->request('POST', '/inbox/'.$id.'/actions/'.self::NOTE, ['_token' => $token, 'text' => '  ', 'noteKind' => 'mail']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Fill this in.', $crawler->filter('#inbox-action-text-error')->text());
        self::assertSame([], $this->outputs($id));
    }

    public function testARecordThatCannotBeAddedToTheItemWarnsNotToSaveAgainWhenItsKindCannotBeLinked(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);
        $form = $crawler->filter('#inbox-action-form')->form();
        $this->checkbox($form, 'failRecording')->tick();
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $page = $this->client->followRedirect();
        // No module offers a note to activities, so Link… could not take it.
        self::assertStringContainsString('The record was made, but it could not be added to what came of this mail. It is saved in its own section; do not save it again.', $page->filter('main')->text());
        self::assertSame([], $this->outputs($id));
        self::assertSame(ItemState::New, $this->item($id)->state());
    }

    public function testHtmlInTheMailIsShownEscapedInTheDialog(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, subject: '<b onmouseover="alert(1)">Offer</b>'));

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);

        self::assertResponseIsSuccessful();
        self::assertSame('<b onmouseover="alert(1)">Offer</b>', $crawler->filter('#inbox-action-text')->attr('value'));
        self::assertCount(0, $crawler->filter('#inbox-action-form b'));
        self::assertStringNotContainsString('<b onmouseover', (string) $this->client->getResponse()->getContent());
    }

    public function testAnUnknownActionAndAnotherPersonsItemAre404(): void
    {
        $id = $this->receive(InboxMail::plain($this->address));

        $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.actions.nothing_like_it');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/inbox/'.$id.'/actions/inbox.items.link');
        self::assertResponseStatusCodeSame(404, 'An operation that is no inbox action.');

        $this->signInAs($this->colleague());
        $this->client->request('GET', '/inbox/'.$id.'/actions/'.self::NOTE);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<string> the operations of the action buttons of the item's header, in order
     */
    private function offered(string $id): array
    {
        $crawler = $this->client->request('GET', '/inbox/'.$id);
        self::assertResponseIsSuccessful();

        return $crawler->filter('[data-inbox-action]')->each(static fn (Crawler $node): string => (string) $node->attr('data-inbox-action'));
    }

    /**
     * @return array<string, string>
     */
    private function options(Crawler $crawler, string $select): array
    {
        $options = [];
        $crawler->filter($select.' option')->each(static function (Crawler $node) use (&$options): void {
            $options[(string) $node->attr('value')] = $node->text();
        });

        return $options;
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        self::assertInstanceOf(ChoiceFormField::class, $field);

        return $field;
    }

    private function createContact(string $id): void
    {
        self::needsTheAddressBook();
        $this->client->request('POST', '/inbox/'.$id.'/contact/new', ['_token' => $this->tokenFrom('/inbox/'.$id), 'kind' => 'person', 'displayName' => 'Client A', 'street' => 'Example Street 1', 'city' => 'Example City', 'postalCode' => '110 00', 'countryCode' => 'CZ', 'email' => 'client.a@client-a.example.com']);
        self::assertResponseRedirects('/inbox/'.$id);
    }

    private function tokenFrom(string $page): string
    {
        $crawler = $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('form[action^="/inbox/"] input[name="_token"]')->first()->attr('value');
    }

    private function item(string $id): InboxItem
    {
        $this->em->clear();
        $item = new DoctrineItemStore($this->em)->item($id);
        self::assertInstanceOf(InboxItem::class, $item);

        return $item;
    }

    /**
     * @return list<array{string, string}>
     */
    private function outputs(string $id): array
    {
        /** @var list<array{kind: string, record_id: string}> $rows */
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT kind, record_id FROM inbox_item_output WHERE item_id = ? ORDER BY added_at', [$id]);

        return array_map(static fn (array $row): array => [$row['kind'], $row['record_id']], $rows);
    }
}
