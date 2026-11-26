<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Inbox\Infrastructure;

use Foresi\Inbox\Application\InboxPurpose;
use Foresi\Inbox\Application\ProposeSuggestions;
use Foresi\Inbox\Application\ProposingClient;
use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Inbox\Domain\StepState;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Inbox\Domain\SuggestionStep;
use Foresi\Inbox\Infrastructure\Controller\SuggestionCards;
use Foresi\Mail\Application\ReceiveMail;
use Foresi\Mail\Application\ReceiveOutcome;
use Foresi\Mail\Contract\AddressIssuer;
use Foresi\Mail\Domain\InboundChannel;
use Foresi\Shared\Domain\Id;
use Foresi\Tests\Inbox\Fixtures\Action\FixtureNotes;
use Foresi\Tests\Inbox\Support\InboxMail;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * The suggestions of an item on its page: a card per open one with its steps,
 * their values, the source with the quote marked and the agent's name; Accept,
 * Accept selected, Edit ("Save and accept"), Reject with a reason, Retry of a
 * failed step; Done asks what to do with open ones; the list's quick filter
 * "With suggestions". The steps are the fixture module's notes
 * (tests/Inbox/Fixtures/Action). Synthetic mail, example.com data.
 */
final class InboxSuggestionScreensTest extends InboxScreenTestCase
{
    private const string NOTE = 'inbox.actions.fixture_note';
    private const string BODY = "Hello, please find our offer below.\r\nThe plan is at https://client-a.example.com/plan for you.\r\nCall me on Monday.\r\n";

    private ProposingClient $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = new ProposingClient(Id::generate(), 'Mail agent');
    }

    public function testTheCardShowsTheStepsTheSourceWithTheQuoteMarkedAndTheAgent(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('Answer the offer', 'call')], 'please find our offer');

        $crawler = $this->client->request('GET', '/inbox/'.$id);

        self::assertResponseIsSuccessful();
        $card = $crawler->filter('[data-inbox-suggestion="'.$suggestion.'"]');
        self::assertCount(1, $card);
        self::assertSame('The client asks for it.', $card->filter('[data-inbox-suggestion-rationale]')->text());
        // The fixture module ships no translation of its label, so its key shows; the values are "field: value".
        self::assertSame('inbox_fixture.action.note', $card->filter('[data-inbox-suggestion-step="1"] label')->text());
        self::assertSame(['The text of the note.: Answer the offer', 'What the note is about.: Call'], $card->filter('[data-inbox-suggestion-step="1"] dl > div')->each(static fn (Crawler $value): string => $value->text()));
        self::assertSame('Source: e-mail from client.a@client-a.example.com, 2026-11-25 10:00', $card->filter('[data-inbox-suggestion-source] p')->first()->text());
        self::assertSame('please find our offer', $card->filter('mark[data-inbox-suggestion-quote]')->text());
        self::assertSame('Hello, please find our offer below. The plan is at https://client-a.example.com/plan [client-a.example.com]…', $card->filter('[data-inbox-suggestion-excerpt]')->text());
        self::assertStringStartsWith('Proposed by Mail agent, ', $card->filter('[data-inbox-suggestion-proposer]')->text());
        self::assertCount(1, $card->filter('[data-inbox-suggestion-accept]'));
        self::assertSame('/inbox/'.$id.'/suggestions/'.$suggestion.'/reject', $card->filter('[data-inbox-suggestion-reject]')->attr('href'));
        self::assertSame('/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/1/edit', $card->filter('[data-inbox-suggestion-edit]')->attr('href'));
        self::assertCount(0, $crawler->filter('[data-inbox-accept-selected]'), 'Accept selected needs two open suggestions.');
    }

    public function testAQuoteAfterALinkIsFoundInTheShownText(): void
    {
        $stored = "Hello.\r\nSee https://client-a.example.com/plan for you.\r\n";
        $shown = "Hello.\nSee https://client-a.example.com/plan [client-a.example.com] for you.\n";

        self::assertSame(['before' => "Hello.\nSee https://client-a.example.com/plan [client-a.example.com] ", 'quote' => 'for you.', 'after' => "\n", 'found' => true], SuggestionCards::excerpt($shown, $stored, 'for you.', 42));
        self::assertSame(['before' => '', 'quote' => 'plan for', 'after' => '', 'found' => false], SuggestionCards::excerpt($shown, $stored, 'plan for', 37), 'A quote across a link is not where the page shows it: shown alone, unmarked.');
    }

    public function testARepeatedQuoteMarksTheOccurrenceItsOffsetNames(): void
    {
        $stored = "Monday: the visit.\r\nSee https://client-a.example.com/Monday then call me on Monday.\r\n";
        $shown = "Monday: the visit.\nSee https://client-a.example.com/Monday [client-a.example.com] then call me on Monday.\n";
        $third = mb_strrpos(str_replace("\r\n", "\n", $stored), 'Monday');
        self::assertIsInt($third);

        $excerpt = SuggestionCards::excerpt($shown, $stored, 'Monday', $third);

        self::assertTrue($excerpt['found']);
        self::assertSame(' then call me on ', mb_substr($excerpt['before'], -17), 'The third Monday, not the first.');
        self::assertSame(".\n", $excerpt['after']);
        self::assertSame('', SuggestionCards::excerpt($shown, $stored, 'Monday', 0)['before'], 'Offset 0 is the first.');
    }

    public function testTheCardMarksTheQuotedOccurrenceOfARepeatedQuote(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: "Call me on Monday about the plan.\r\nThe visit is on Monday at noon.\r\n"));
        $item = $this->em->find(InboxItem::class, $id);
        self::assertInstanceOf(InboxItem::class, $item);
        // An agent's span: the second "Monday", by its offset in the stored text with CRLF as LF.
        $offset = mb_strrpos("Call me on Monday about the plan.\nThe visit is on Monday at noon.\n", 'Monday');
        self::assertIsInt($offset);
        $suggestion = new Suggestion(Id::generate(), $item, [$this->note('Visit')], 'The visit is on Monday.', 'Monday', $offset, $this->owner->id(), Id::generate(), 'Mail agent', new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->persist($suggestion);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/inbox/'.$id);

        $html = $crawler->filter('[data-inbox-suggestion="'.$suggestion->id().'"] [data-inbox-suggestion-excerpt]')->html();
        self::assertSame("Call me on Monday about the plan.\nThe visit is on ", mb_substr($html, 0, (int) mb_strpos($html, '<mark')), 'The second Monday is marked.');
    }

    public function testAgentTextIsPrintedEscaped(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: "Hello <b>there</b>, please find our offer.\r\n"));
        $this->propose($id, [$this->note('<script>alert("note")</script>')], '<b>there</b>', '<script>alert("rationale")</script>', new ProposingClient(Id::generate(), '<script>alert("client")</script>'));
        $rejected = $this->propose($id, [$this->note('Second')], 'please find our offer');
        $this->stored($rejected)->reject($this->owner->id(), '<script>alert("reason")</script>', new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->flush();

        $this->client->request('GET', '/inbox/'.$id);

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(', $html);
        self::assertStringNotContainsString('<b>there</b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;rationale&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;note&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;client&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('&lt;script&gt;alert(&quot;reason&quot;)&lt;/script&gt;', $html);
        self::assertStringContainsString('<mark class="rounded-sm bg-badge-amber px-0.5 text-ink" data-inbox-suggestion-quote>&lt;b&gt;there&lt;/b&gt;</mark>', $html);
    }

    public function testAnUntickedStepIsSkippedWhenTheCardIsAccepted(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First'), $this->note('Second')]);

        $crawler = $this->client->request('GET', '/inbox/'.$id);
        $ticks = $crawler->filter('[data-inbox-suggestion="'.$suggestion.'"] input[data-inbox-suggestion-tick]');
        self::assertSame(['steps['.$suggestion.'][]', 'steps['.$suggestion.'][]'], $ticks->each(static fn (Crawler $tick): string => (string) $tick->attr('name')));
        self::assertSame(['1', '2'], $ticks->each(static fn (Crawler $tick): string => (string) $tick->attr('value')));
        $accept = $crawler->filter('[data-inbox-suggestion="'.$suggestion.'"] [data-inbox-suggestion-accept]');
        self::assertSame(['accept', $suggestion], [$accept->attr('name'), $accept->attr('value')]);

        // Step 2 unticked: only step 1 is sent.
        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $this->token($crawler), 'accept' => $suggestion, 'steps' => [$suggestion => ['1']]]);

        self::assertResponseRedirects('/inbox/'.$id);
        $stored = $this->stored($suggestion);
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame([StepState::Done, StepState::Skipped], $this->states($stored));
        self::assertSame(['First'], array_values($this->notes()->made));
        self::assertSame(ItemState::Done, $this->item($id), 'The last decision marks the item done.');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Suggestion accepted.', $page->filter('main')->text());
        $decided = $page->filter('[data-inbox-suggestion="'.$suggestion.'"]');
        self::assertSame('accepted', $decided->attr('data-state'));
        self::assertStringContainsString('Accepted by you', $decided->text());
        self::assertStringContainsString('Skipped', $decided->text());
        self::assertStringContainsString('Fixture note', $page->filter('[data-inbox-outputs]')->text(), 'What step 1 made is among what came of the mail.');
    }

    public function testUntickingEveryStepIsRefusedAndRunsNothing(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First')]);
        $crawler = $this->client->request('GET', '/inbox/'.$id);

        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $this->token($crawler), 'accept' => $suggestion]);

        self::assertResponseRedirects('/inbox/'.$id);
        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
        self::assertSame([], $this->notes()->made);
        self::assertStringContainsString('Leave at least one step ticked, or reject the suggestion.', $this->client->followRedirect()->filter('main')->text());
    }

    public function testEditingAStepSavesAndAcceptsWithTheFinalInput(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('Answer the offer'), ['operation' => self::NOTE, 'input' => ['text' => 'About the answer', 'about_id' => ['$step' => 1, 'field' => 'id']]]]);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/2/edit', server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame('About the answer', $crawler->filter('#inbox-action-text')->attr('value'));
        $reference = $crawler->filter('#inbox-action-about_id');
        self::assertSame('The record of step 1', $reference->attr('value'));
        self::assertNotNull($reference->attr('disabled'), 'Shown, not edited.');
        self::assertCount(0, $crawler->filter('#inbox-action-mark_done'), 'The last decision marks the item done, not a step.');
        self::assertSame('Save and accept', trim($crawler->filter('button[type=submit][form="inbox-action-form"]')->text()));
        self::assertSame('/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/2/edit', $crawler->filter('#inbox-action-form')->attr('action'));

        $form = $crawler->filter('#inbox-action-form')->form();
        $form['text'] = 'About the edited answer';
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $stored = $this->stored($suggestion);
        self::assertSame(SuggestionState::Accepted, $stored->state());
        $step = $stored->step(2);
        self::assertSame('About the answer', $step->proposedInput()['text']);
        self::assertSame('About the edited answer', $step->finalInput()['text'] ?? null);
        self::assertSame(['$step' => 1, 'field' => 'id'], $step->finalInput()['about_id'] ?? null, 'The reference is kept as it was.');
        $notes = $this->notes();
        self::assertSame(['Answer the offer', 'About the edited answer'], array_values($notes->made));
        self::assertSame([$stored->step(1)->recordId()], array_values($notes->about));
    }

    public function testTheEditDialogListsEveryStepAndRunsOnlyTheTickedOnes(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First'), $this->note('Second'), $this->note('Third')]);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/1/edit');

        $ticks = $crawler->filter('input[data-inbox-suggestion-run-step]');
        self::assertSame([['suggestionSteps[]', '1', true, true], ['suggestionSteps[]', '2', true, false], ['suggestionSteps[]', '3', true, false]], $ticks->each(static fn (Crawler $tick): array => [(string) $tick->attr('name'), (string) $tick->attr('value'), null !== $tick->attr('checked'), null !== $tick->attr('disabled')]), 'Every step, ticked; the edited one always runs.');

        // Step 2 unticked in the dialog: "Save and accept" skips it, though the card had it ticked.
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['text'] = 'First, edited';
        $values = $form->getPhpValues();
        $values['suggestionSteps'] = ['3'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects('/inbox/'.$id);
        $stored = $this->stored($suggestion);
        self::assertSame(SuggestionState::Accepted, $stored->state());
        self::assertSame([StepState::Done, StepState::Skipped, StepState::Done], $this->states($stored));
        self::assertSame(['First, edited', 'Third'], array_values($this->notes()->made));
    }

    public function testAStepUntickedBeforeStaysUntickedInTheEditDialog(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First', fail: true), $this->note('Second')]);
        $crawler = $this->client->request('GET', '/inbox/'.$id);
        // Accepted with step 2 unticked; step 1 fails, so the suggestion stays open with step 2 skipped.
        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $this->token($crawler), 'accept' => $suggestion, 'steps' => [$suggestion => ['1']]]);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/1/edit');

        $second = $crawler->filter('input[data-inbox-suggestion-run-step][value="2"]');
        self::assertSame([false, true], [null !== $second->attr('checked'), null !== $second->attr('disabled')]);
        self::assertStringContainsString('Skipped', $crawler->filter('[data-inbox-suggestion-run-steps]')->text());
    }

    public function testAnInvalidEditShowsOnItsFieldAndRunsNothing(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('Answer the offer')]);
        $crawler = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/1/edit');
        $form = $crawler->filter('#inbox-action-form')->form();
        $form['text'] = '';

        $crawler = $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Fill this in.', trim($crawler->filter('#inbox-action-text-error')->text()));
        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
        self::assertSame([], $this->notes()->made);
    }

    public function testRejectingAsksForAnOptionalReason(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('Answer the offer')]);

        $crawler = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/reject', server: ['HTTP_X_FORESI_DIALOG' => '1']);

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#inbox-suggestion-reject-form')->form();
        $form['reason'] = 'We answered by phone.';
        $this->client->submit($form);

        self::assertResponseRedirects('/inbox/'.$id);
        $stored = $this->stored($suggestion);
        self::assertSame([SuggestionState::Rejected, 'We answered by phone.'], [$stored->state(), $stored->rejectReason()]);
        self::assertSame([], $this->notes()->made);
        self::assertSame(ItemState::New, $this->item($id), 'Nothing was accepted, so the item stays new.');
        $page = $this->client->followRedirect();
        self::assertStringContainsString('Suggestion rejected.', $page->filter('main')->text());
        $decided = $page->filter('[data-inbox-suggestion="'.$suggestion.'"]');
        self::assertSame('rejected', $decided->attr('data-state'));
        self::assertSame('Reason: We answered by phone.', $decided->filter('[data-inbox-suggestion-reason]')->text());
        self::assertCount(0, $page->filter('[data-inbox-suggestion-accept]'));
    }

    public function testAcceptSelectedRunsTheTickedCardsEachOnItsOwn(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $first = $this->propose($id, [$this->note('First')]);
        $second = $this->propose($id, [$this->note('Second')]);
        $third = $this->propose($id, [$this->note('Third')]);

        $crawler = $this->client->request('GET', '/inbox/'.$id);
        $header = $crawler->filter('[data-inbox-accept-selected]');
        self::assertCount(1, $header);
        self::assertSame(['submit', 'inbox-suggestions'], [$header->attr('type'), $header->attr('form')]);
        self::assertSame([$first, $second, $third], $crawler->filter('input[data-inbox-suggestion-select]')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertSame(['selected[]'], array_values(array_unique($crawler->filter('input[data-inbox-suggestion-select]')->each(static fn (Crawler $box): string => (string) $box->attr('name')))));

        // The third card unticked: Accept selected sends the first two.
        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $this->token($crawler), 'selected' => [$first, $second], 'steps' => [$first => ['1'], $second => ['1'], $third => ['1']]]);

        self::assertResponseRedirects('/inbox/'.$id);
        self::assertSame([SuggestionState::Accepted, SuggestionState::Accepted, SuggestionState::Proposed], [$this->stored($first)->state(), $this->stored($second)->state(), $this->stored($third)->state()]);
        self::assertSame(['First', 'Second'], array_values($this->notes()->made));
        self::assertSame(ItemState::New, $this->item($id), 'One suggestion is still open.');
        self::assertStringContainsString('2 suggestions accepted.', $this->client->followRedirect()->filter('main')->text());
    }

    public function testAFailedStepShowsItsRefusalAndRetry(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First'), $this->note('Second', fail: true)]);
        $crawler = $this->client->request('GET', '/inbox/'.$id);

        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $this->token($crawler), 'accept' => $suggestion, 'steps' => [$suggestion => ['1', '2']]]);

        self::assertResponseRedirects('/inbox/'.$id);
        $page = $this->client->followRedirect();
        self::assertStringContainsString('A step could not run.', $page->filter('main')->text());
        $card = $page->filter('[data-inbox-suggestion="'.$suggestion.'"]');
        self::assertSame(['done', 'failed'], $card->filter('[data-inbox-suggestion-step]')->each(static fn (Crawler $step): string => (string) $step->attr('data-state')));
        self::assertSame('fixture_failed', $card->filter('[data-inbox-suggestion-step="2"] [data-inbox-step-refusal]')->text(), 'A reason Inbox has no words for shows as its code.');
        self::assertSame('Retry', trim($card->filter('[data-inbox-suggestion-accept]')->text()));
        self::assertCount(0, $card->filter('[data-inbox-suggestion-step="1"] input[data-inbox-suggestion-tick]'), 'A done step is not ticked again.');
        self::assertCount(1, $card->filter('[data-inbox-suggestion-step="2"] [data-inbox-suggestion-edit]'), 'A failed step can be edited.');

        // Retry with the step edited so it passes: the done step does not run again.
        $edit = $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/2/edit');
        $form = $edit->filter('#inbox-action-form')->form();
        $fail = $form['fail'];
        self::assertInstanceOf(ChoiceFormField::class, $fail);
        $fail->untick();
        $this->client->submit($form);

        self::assertSame(SuggestionState::Accepted, $this->stored($suggestion)->state());
        self::assertSame(['First', 'Second'], array_values($this->notes()->made));
    }

    public function testATranslatedRefusalIsShown(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First')]);
        $this->stored($suggestion)->step(1)->fail('reference_unresolved', 'input.aboutId', new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->flush();

        $page = $this->client->request('GET', '/inbox/'.$id);

        self::assertSame('The step it needs, an earlier one, did not make a record.', $page->filter('[data-inbox-step-refusal]')->text());
    }

    public function testDoneWithOpenSuggestionsAsksWhetherToRejectOrKeepThem(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First')]);

        $crawler = $this->client->request('GET', '/inbox/'.$id);
        self::assertCount(0, $crawler->filter('form[data-inbox-done]'));
        $done = $crawler->filter('[data-inbox-done-ask]');
        self::assertSame(['/inbox/'.$id.'/done', 'follow'], [$done->attr('href'), $done->attr('data-dialog-then')]);
        self::assertNotNull($done->attr('data-dialog'));

        $dialog = $this->client->request('GET', '/inbox/'.$id.'/done', server: ['HTTP_X_FORESI_DIALOG' => '1']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('A suggestion waits on this mail.', $dialog->text());
        self::assertSame(['keep', 'reject'], $dialog->filter('button[name="suggestions"]')->each(static fn (Crawler $button): string => (string) $button->attr('value')));
        $this->client->submit($dialog->filter('[data-inbox-done-keep]')->form());

        self::assertResponseRedirects('/inbox');
        self::assertSame(ItemState::Done, $this->item($id));
        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state(), 'Kept on the done item.');
        $page = $this->client->request('GET', '/inbox/'.$id);
        self::assertCount(1, $page->filter('[data-inbox-suggestion="'.$suggestion.'"] [data-inbox-suggestion-accept]'), 'A kept suggestion can still be accepted.');
        self::assertCount(1, $page->filter('form[data-inbox-reopen]'));
    }

    public function testTheQuickFilterWithSuggestionsListsOnlyItemsWithOpenOnes(): void
    {
        $with = $this->receive(InboxMail::plain($this->address, subject: 'Offer with a suggestion', messageId: 'with@client-a.example.com', body: self::BODY));
        $this->receive(InboxMail::plain($this->address, subject: 'Offer without one', messageId: 'without@client-a.example.com', body: self::BODY));
        $decided = $this->receive(InboxMail::plain($this->address, subject: 'Offer decided', messageId: 'decided@client-a.example.com', body: self::BODY));
        $this->propose($with, [$this->note('First')]);
        $rejected = $this->propose($decided, [$this->note('First')]);
        $suggestion = $this->stored($rejected);
        $suggestion->reject($this->owner->id(), null, new \DateTimeImmutable('2026-11-25 10:00:00'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/inbox');
        self::assertCount(3, $crawler->filter('[data-inbox-list] tbody tr'));
        $link = $crawler->selectLink('With suggestions');
        self::assertCount(1, $link, 'The quick filter Suggestions offers "With suggestions".');

        $crawler = $this->client->click($link->link());

        self::assertResponseIsSuccessful();
        $rows = $crawler->filter('[data-inbox-list] tbody tr');
        self::assertCount(1, $rows);
        self::assertStringContainsString('Offer with a suggestion', $rows->text());
    }

    /**
     * Every route of the suggestions, as another person would ask it: 404, nothing decided.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'accept' => ['POST', '/inbox/{item}/suggestions/accept'];
        yield 'reject dialog' => ['GET', '/inbox/{item}/suggestions/{suggestion}/reject'];
        yield 'reject' => ['POST', '/inbox/{item}/suggestions/{suggestion}/reject'];
        yield 'edit dialog' => ['GET', '/inbox/{item}/suggestions/{suggestion}/steps/1/edit'];
        yield 'edit' => ['POST', '/inbox/{item}/suggestions/{suggestion}/steps/1/edit'];
        yield 'done dialog' => ['GET', '/inbox/{item}/done'];
    }

    #[DataProvider('routes')]
    public function testAnotherPersonsItemIsNotFound(string $method, string $route): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First')]);
        $colleague = $this->colleague();
        $address = self::getContainer()->get(AddressIssuer::class)->issue(InboxPurpose::KEY, $colleague->id(), 'Their inbox')->address;
        // A valid token of the colleague's session, from their own item's page.
        self::assertSame(ReceiveOutcome::Stored, self::getContainer()->get(ReceiveMail::class)->receive(InboxMail::plain($address, messageId: 'theirs@client-a.example.com'), InboundChannel::Webhook, $address));
        $theirs = $this->em->getConnection()->fetchOne('SELECT id FROM inbox_item WHERE owner_user_id = ?', [$colleague->id()]);
        self::assertIsString($theirs);
        $this->signInAs($colleague);
        $token = $this->token($this->client->request('GET', '/inbox/'.$theirs), 'form[data-inbox-match-again]');
        self::assertNotSame('', $token);

        $this->client->request($method, strtr($route, ['{item}' => $id, '{suggestion}' => $suggestion]), 'POST' === $method ? ['_token' => $token, 'accept' => $suggestion, 'steps' => [$suggestion => ['1']], 'text' => 'Mine now', 'reason' => 'No'] : []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
        self::assertSame([], $this->notes()->made);
    }

    public function testASuggestionOfAnotherItemIsNotFoundOrIgnored(): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $other = $this->receive(InboxMail::plain($this->address, subject: 'Another offer', messageId: 'other@client-a.example.com', body: self::BODY));
        $suggestion = $this->propose($other, [$this->note('First')]);
        $crawler = $this->client->request('GET', '/inbox/'.$id);
        $token = $this->token($crawler, 'form[data-inbox-match-again]');

        $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/reject');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/inbox/'.$id.'/suggestions/'.$suggestion.'/reject', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/inbox/'.$id.'/suggestions/'.$suggestion.'/steps/1/edit');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/inbox/'.$id.'/suggestions/accept', ['_token' => $token, 'accept' => $suggestion, 'steps' => [$suggestion => ['1']]]);
        self::assertResponseRedirects('/inbox/'.$id, null, 'An id that is no open suggestion of this item is dropped.');

        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
        self::assertSame([], $this->notes()->made);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function writes(): iterable
    {
        yield 'accept' => ['/inbox/{item}/suggestions/accept'];
        yield 'reject' => ['/inbox/{item}/suggestions/{suggestion}/reject'];
        yield 'edit' => ['/inbox/{item}/suggestions/{suggestion}/steps/1/edit'];
    }

    #[DataProvider('writes')]
    public function testAWriteWithoutAValidTokenIsRefused(string $route): void
    {
        $id = $this->receive(InboxMail::plain($this->address, body: self::BODY));
        $suggestion = $this->propose($id, [$this->note('First')]);
        $this->client->request('GET', '/inbox/'.$id);
        $url = strtr($route, ['{item}' => $id, '{suggestion}' => $suggestion]);
        $sent = ['accept' => $suggestion, 'steps' => [$suggestion => ['1']], 'suggestionSteps' => ['1'], 'text' => 'Edited', 'reason' => 'No'];

        $this->client->request('POST', $url, $sent);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $url, ['_token' => 'not-the-token'] + $sent);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(SuggestionState::Proposed, $this->stored($suggestion)->state());
        self::assertSame([], $this->notes()->made);
    }

    /**
     * @param list<array<string, mixed>> $steps
     */
    private function propose(string $itemId, array $steps, string $quote = 'please find our offer', string $rationale = 'The client asks for it.', ?ProposingClient $agent = null): string
    {
        [$suggestion] = self::getContainer()->get(ProposeSuggestions::class)->propose($itemId, [['steps' => $steps, 'rationale' => $rationale, 'quote' => $quote]], $agent ?? $this->agent);

        return $suggestion->id();
    }

    /**
     * @return array{operation: string, input: array<string, mixed>}
     */
    private function note(string $text, string $kind = 'mail', bool $fail = false): array
    {
        return ['operation' => self::NOTE, 'input' => ['text' => $text, 'note_kind' => $kind] + ($fail ? ['fail' => true] : [])];
    }

    private function token(Crawler $page, string $form = '#inbox-suggestions'): string
    {
        return (string) $page->filter($form.' input[name="_token"]')->attr('value');
    }

    private function stored(string $id): Suggestion
    {
        $this->em->clear();
        $suggestion = self::getContainer()->get(SuggestionStore::class)->find($id);
        self::assertInstanceOf(Suggestion::class, $suggestion);

        return $suggestion;
    }

    /**
     * @return list<StepState>
     */
    private function states(Suggestion $suggestion): array
    {
        return array_map(static fn (SuggestionStep $step): StepState => $step->state(), $suggestion->steps());
    }

    private function item(string $id): ItemState
    {
        $state = $this->em->getConnection()->fetchOne('SELECT state FROM inbox_item WHERE id = ?', [$id]);
        self::assertIsString($state);

        return ItemState::from($state);
    }

    private function notes(): FixtureNotes
    {
        $notes = self::getContainer()->get(FixtureNotes::class); // @phpstan-ignore symfonyContainer.serviceNotFound (registered in the test environment only, and PHPStan reads the dev container)
        self::assertInstanceOf(FixtureNotes::class, $notes);

        return $notes;
    }
}
