<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Controller;

use App\Banking\Application\AddressRefusal;
use App\Banking\Application\AddressRefused;
use App\Banking\Application\BankFeeds;
use App\Banking\Application\ChangePairing;
use App\Banking\Application\FirmPairingRules;
use App\Banking\Application\GenerateAddress;
use App\Banking\Application\InboundAddresses;
use App\Banking\Application\TrustCheck;
use App\Banking\Application\TrustChecks;
use App\Banking\Domain\BankFeed;
use App\Banking\Domain\MessageTrust;
use App\Identity\Application\OwnerCheck;
use App\Invoicing\Application\InvoicingCountries;
use App\Invoicing\Application\MailSecrets;
use App\Invoicing\Application\UnsupportedCountry;
use App\Party\Application\BankAccountRefused;
use App\Party\Application\BankAccountUsage;
use App\Party\Application\FirmBankAccounts;
use App\Party\Application\LegalEntities;
use App\Party\Application\ManageFirmBankAccounts;
use App\Party\Application\UnknownFirmBankAccount;
use App\Party\Application\UnknownLegalEntity;
use App\Party\Domain\FirmBankAccount;
use App\Party\Domain\LegalEntity;
use App\Shared\Domain\Currency;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The bank settings of a firm, for the owner, as one more tab of the firm: its
 * bank accounts, and how payments pair with invoices, with the inbound address
 * of each account. Adding and editing an account, and the setup steps of an
 * address, are dialogs over the tab; the same URLs are pages of their own. A
 * firm or an account of another tenant is never found, so it answers 404. The
 * address is shown only to the owner and never logged.
 */
final class FirmBankController extends AbstractController
{
    public const string ACCOUNT_TOKEN = 'firm_bank_account';
    public const string ACTION_TOKEN = 'firm_bank_action';
    public const string PAIRING_TOKEN = 'firm_bank_pairing';
    public const string ADDRESS_TOKEN = 'firm_bank_address';
    /** The setup guide; it lives with the code, so a self-hosted server links to the same text. */
    public const string GUIDE = 'https://github.com/foresi/foresi/blob/main/docs/bank-pairing.md';

    private const string ACCOUNTS = 'accounts';
    private const string PAIRING = 'pairing';
    private const array FIELD_OF_REFUSAL = [
        'invalid_iban' => 'number',
        'duplicate' => 'number',
        'unknown_currency' => 'currency',
        'currency_locked' => 'currency',
        'archived_primary' => 'primary',
    ];

    public function __construct(
        private readonly LegalEntities $firms,
        private readonly FirmBankAccounts $accounts,
        private readonly ManageFirmBankAccounts $manage,
        private readonly BankAccountUsage $usage,
        private readonly BankFeeds $feeds,
        private readonly GenerateAddress $generate,
        private readonly InboundAddresses $addresses,
        private readonly MailSecrets $secrets,
        private readonly FirmPairingRules $pairing,
        private readonly ChangePairing $changePairing,
        private readonly InvoicingCountries $countries,
        private readonly OwnerCheck $owner,
        private readonly TranslatorInterface $translator,
        private readonly TrustChecks $checks,
    ) {
    }

    #[Route('/settings/firms/{id}/bank', name: 'app_firm_bank', requirements: ['id' => Requirement::UUID_V7], methods: ['GET'])]
    public function settings(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $tab = self::PAIRING === $request->query->getString('tab') ? self::PAIRING : self::ACCOUNTS;
        $all = $this->accounts->of($firm, withArchived: true);
        $feeds = $this->feeds->ofAccounts(array_map(static fn (FirmBankAccount $account): string => $account->id(), $all));
        $rules = $this->pairing->for($firm);
        $url = $this->generateUrl('app_firm_bank', ['id' => $firm->id()]);

        return $this->render('banking/firm/bank.html.twig', parameters: [
            'firm' => $firm,
            'tab' => $tab,
            'tabs' => [
                ['label' => $this->translator->trans('bank.account.tab.accounts'), 'href' => $url, 'active' => self::ACCOUNTS === $tab],
                ['label' => $this->translator->trans('bank.account.tab.pairing'), 'href' => $url.'?tab='.self::PAIRING, 'active' => self::PAIRING === $tab],
            ],
            'columns' => $this->columns(),
            'rows' => array_map(fn (FirmBankAccount $account): array => $this->row($firm, $account, $feeds[$account->id()] ?? null), $all),
            'feeds' => array_map(fn (FirmBankAccount $account): array => $this->feedView($firm, $account, $feeds[$account->id()] ?? null), array_values(array_filter($all, static fn (FirmBankAccount $account): bool => !$account->isArchived()))),
            'autoPairing' => $rules->autoPairing,
            'smallDifference' => $rules->smallDifference,
            'unavailable' => $this->unavailable(),
            'newUrl' => $this->generateUrl('app_firm_bank_account_new', ['id' => $firm->id()]),
            'pairingAction' => $this->generateUrl('app_firm_bank_pairing', ['id' => $firm->id()]),
            'cancel' => $this->generateUrl('app_firm_edit', ['id' => $firm->id()]),
        ], response: self::PAIRING === $tab ? $this->unstored() : null);
    }

    #[Route('/settings/firms/{id}/bank/accounts/new', name: 'app_firm_bank_account_new', requirements: ['id' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function new(string $id, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $first = [] === $this->accounts->of($firm);
        $values = ['number' => '', 'currency' => Currency::CZK->value, 'label' => '', 'primary' => $first ? '1' : ''];
        if (!$request->isMethod('POST')) {
            return $this->accountForm($firm, null, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::ACCOUNT_TOKEN);
        $values = [
            'number' => trim($request->request->getString('number')),
            'currency' => $request->request->getString('currency'),
            'label' => trim($request->request->getString('label')),
            'primary' => '1' === $request->request->getString('primary') ? '1' : '',
        ];
        $errors = $this->labelErrors($values['label']);
        if ('' === $values['number']) {
            $errors['number'] = 'bank.account.error.number_required';
        }
        if ([] !== $errors) {
            return $this->accountForm($firm, null, $values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $account = $this->manage->open($firm->id(), $values['number'], $values['currency'], $values['label'], '1' === $values['primary']);
        } catch (BankAccountRefused $refused) {
            return $this->accountForm($firm, null, $values, $this->refusal($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (UniqueConstraintViolationException) {
            // Another request stored the same account, or a primary one, meanwhile.
            return $this->accountForm($firm, null, $values, ['form' => 'bank.account.error.save_failed'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('bank.account.flash.created', ['name' => $this->name($firm, $account)]));

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id()]);
    }

    #[Route('/settings/firms/{id}/bank/accounts/{account}/edit', name: 'app_firm_bank_account_edit', requirements: ['id' => Requirement::UUID_V7, 'account' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function edit(string $id, string $account, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->account($firm, $account);
        $values = ['number' => $stored->iban(), 'currency' => $stored->currency()->value, 'label' => $stored->label(), 'primary' => $stored->isPrimary() ? '1' : ''];
        if (!$request->isMethod('POST')) {
            return $this->accountForm($firm, $stored, $values, [], Response::HTTP_OK);
        }

        $this->checkToken($request, self::ACCOUNT_TOKEN);
        // The IBAN is the account itself: whatever is posted for it is not read.
        $locked = $this->usage->hasMovements($stored->id());
        $values['currency'] = $locked ? $stored->currency()->value : $request->request->getString('currency');
        $values['label'] = trim($request->request->getString('label'));
        $values['primary'] = $stored->isPrimary() || '1' === $request->request->getString('primary') ? '1' : '';
        $errors = $this->labelErrors($values['label']);
        if ([] !== $errors) {
            return $this->accountForm($firm, $stored, $values, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $changed = $this->manage->change($stored->id(), $values['label'], $values['currency'], '1' === $values['primary']);
        } catch (BankAccountRefused $refused) {
            return $this->accountForm($firm, $stored, $values, $this->refusal($refused), Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $this->translator->trans('bank.account.flash.updated', ['name' => $this->name($firm, $changed)]));

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id()]);
    }

    #[Route('/settings/firms/{id}/bank/accounts/{account}/primary', name: 'app_firm_bank_account_primary', requirements: ['id' => Requirement::UUID_V7, 'account' => Requirement::UUID_V7], methods: ['POST'])]
    public function primary(string $id, string $account, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->account($firm, $account);
        $this->checkToken($request, self::ACTION_TOKEN);
        try {
            $this->manage->change($stored->id(), $stored->label(), $stored->currency()->value, true);
            $this->addFlash('success', $this->translator->trans('bank.account.flash.primary', ['name' => $this->name($firm, $stored)]));
        } catch (BankAccountRefused $refused) {
            $this->addFlash('error', $this->translator->trans($refused->getMessage()));
        }

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id()]);
    }

    #[Route('/settings/firms/{id}/bank/accounts/{account}/archive', name: 'app_firm_bank_account_archive', requirements: ['id' => Requirement::UUID_V7, 'account' => Requirement::UUID_V7], methods: ['POST'])]
    public function archive(string $id, string $account, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->account($firm, $account);
        $this->checkToken($request, self::ACTION_TOKEN);
        $this->manage->archive($stored->id());
        $this->addFlash('success', $this->translator->trans('bank.account.flash.archived', ['name' => $this->name($firm, $stored)]));

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id()]);
    }

    #[Route('/settings/firms/{id}/bank/accounts/{account}/restore', name: 'app_firm_bank_account_restore', requirements: ['id' => Requirement::UUID_V7, 'account' => Requirement::UUID_V7], methods: ['POST'])]
    public function restore(string $id, string $account, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->account($firm, $account);
        $this->checkToken($request, self::ACTION_TOKEN);
        $this->manage->restore($stored->id());
        $this->addFlash('success', $this->translator->trans('bank.account.flash.restored', ['name' => $this->name($firm, $stored)]));

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id()]);
    }

    /**
     * GET: the address of the account with the steps that set the bank up. POST:
     * a new address, which replaces the old one at once; it is shown on the tab.
     */
    #[Route('/settings/firms/{id}/bank/accounts/{account}/address', name: 'app_firm_bank_address', requirements: ['id' => Requirement::UUID_V7, 'account' => Requirement::UUID_V7], methods: ['GET', 'POST'])]
    public function address(string $id, string $account, Request $request): Response
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $stored = $this->account($firm, $account);
        if (!$request->isMethod('POST')) {
            return $this->render('banking/firm/address.html.twig', [
                'firm' => $firm,
                'account' => $this->feedView($firm, $stored, $this->feeds->ofAccount($stored->id())),
                'unavailable' => $this->unavailable(),
                'guide' => self::GUIDE,
                'cancel' => $this->generateUrl('app_firm_bank', ['id' => $firm->id(), 'tab' => self::PAIRING]),
            ], $this->unstored());
        }

        $this->checkToken($request, self::ADDRESS_TOKEN);
        $back = $this->redirectToRoute('app_firm_bank', ['id' => $firm->id(), 'tab' => self::PAIRING]);
        try {
            $this->generate->generate($stored->id());
        } catch (AddressRefused $refused) {
            $this->addFlash('error', $this->translator->trans($refused->getMessage()));

            return $back;
        } catch (UniqueConstraintViolationException) {
            // Another request gave the account its address meanwhile; that one stands.
            $this->addFlash('error', $this->translator->trans('bank.account.address.refused.conflict'));

            return $back;
        }
        $this->addFlash('success', $this->translator->trans('bank.account.address.flash.generated', ['name' => $this->name($firm, $stored)]));

        return $back;
    }

    #[Route('/settings/firms/{id}/bank/pairing', name: 'app_firm_bank_pairing', requirements: ['id' => Requirement::UUID_V7], methods: ['POST'])]
    public function pairing(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessOwner();
        $firm = $this->firm($id);
        $this->checkToken($request, self::PAIRING_TOKEN);
        try {
            $this->changePairing->change($firm->id(), '1' === $request->request->getString('autoPairing'), '1' === $request->request->getString('smallDifference'));
        } catch (UniqueConstraintViolationException) {
            // Another request stored the firm's first switches meanwhile.
            $this->addFlash('error', $this->translator->trans('bank.account.pairing.save_failed'));

            return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id(), 'tab' => self::PAIRING]);
        }
        $this->addFlash('success', $this->translator->trans('bank.account.pairing.saved'));

        return $this->redirectToRoute('app_firm_bank', ['id' => $firm->id(), 'tab' => self::PAIRING]);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors field => translation key
     */
    private function accountForm(LegalEntity $firm, ?FirmBankAccount $account, array $values, array $errors, int $status): Response
    {
        $locked = $account instanceof FirmBankAccount && $this->usage->hasMovements($account->id());

        return $this->render('banking/firm/account.html.twig', [
            'firm' => $firm,
            'account' => $account,
            'values' => $values,
            'errors' => array_map(fn (string $key): string => $this->translator->trans($key), $errors),
            'currencies' => array_combine(array_map(static fn (Currency $currency): string => $currency->value, Currency::cases()), array_map(static fn (Currency $currency): string => $currency->value, Currency::cases())),
            'currencyLocked' => $locked,
            'domestic' => $account instanceof FirmBankAccount ? $this->domestic($firm, $account->iban()) : null,
            'iban' => $account instanceof FirmBankAccount ? $this->grouped($account->iban()) : null,
            'action' => $account instanceof FirmBankAccount
                ? $this->generateUrl('app_firm_bank_account_edit', ['id' => $firm->id(), 'account' => $account->id()])
                : $this->generateUrl('app_firm_bank_account_new', ['id' => $firm->id()]),
            'cancel' => $this->generateUrl('app_firm_bank', ['id' => $firm->id()]),
        ], new Response(status: $status));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function columns(): array
    {
        return [
            ['key' => 'name', 'label' => $this->translator->trans('bank.account.column.name')],
            ['key' => 'iban', 'label' => $this->translator->trans('bank.account.column.iban'), 'phone' => false],
            ['key' => 'currency', 'label' => $this->translator->trans('bank.account.column.currency'), 'phone' => false],
            ['key' => 'state', 'label' => $this->translator->trans('bank.account.column.state')],
            ['key' => 'address', 'label' => $this->translator->trans('bank.account.column.address'), 'phone' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(LegalEntity $firm, FirmBankAccount $account, ?BankFeed $feed): array
    {
        $ids = ['id' => $firm->id(), 'account' => $account->id()];
        $name = $this->name($firm, $account);
        $actions = [];
        if (!$account->isArchived()) {
            $actions[] = ['label' => $this->translator->trans('bank.account.action.edit'), 'href' => $this->generateUrl('app_firm_bank_account_edit', $ids), 'method' => 'GET', 'icon' => 'pencil', 'dialog' => true];
            if (!$account->isPrimary()) {
                $actions[] = ['label' => $this->translator->trans('bank.account.action.primary'), 'href' => $this->generateUrl('app_firm_bank_account_primary', $ids), 'method' => 'POST'];
            }
            $actions[] = ['label' => $this->translator->trans('bank.account.action.address'), 'href' => $this->generateUrl('app_firm_bank_address', $ids), 'method' => 'GET', 'dialog' => true];
            $actions[] = ['label' => $this->translator->trans('bank.account.action.archive'), 'href' => $this->generateUrl('app_firm_bank_account_archive', $ids), 'method' => 'POST', 'confirm' => [
                'title' => $this->translator->trans('bank.account.confirm.archive', ['name' => $name]),
                'text' => $this->translator->trans('bank.account.confirm.archive_text'),
                'accept' => $this->translator->trans('bank.account.confirm.archive_accept'),
                'cancel' => $this->translator->trans('bank.account.confirm.archive_cancel'),
                'tone' => 'danger',
            ]];
        } else {
            $actions[] = ['label' => $this->translator->trans('bank.account.action.restore'), 'href' => $this->generateUrl('app_firm_bank_account_restore', $ids), 'method' => 'POST', 'icon' => 'archive-restore'];
        }

        return [
            'id' => $account->id(),
            'label' => $name,
            'cells' => [
                'name' => $name,
                'iban' => $this->grouped($account->iban()),
                'currency' => $account->currency()->value,
                'state' => match (true) {
                    $account->isArchived() => ['text' => $this->translator->trans('bank.account.state.archived'), 'tone' => 'gray'],
                    $account->isPrimary() => ['text' => $this->translator->trans('bank.account.state.primary'), 'tone' => 'green'],
                    default => ['text' => $this->translator->trans('bank.account.state.active'), 'tone' => 'gray'],
                },
                'address' => $feed instanceof BankFeed
                    ? ['text' => $this->translator->trans('bank.account.address.state.set'), 'tone' => 'green']
                    : ['text' => $this->translator->trans('bank.account.address.state.none'), 'tone' => 'gray'],
            ],
            // On a phone the IBAN and the currency go under the name.
            'secondary' => $this->grouped($account->iban()).' · '.$account->currency()->value,
            'actions' => $actions,
        ];
    }

    /**
     * What the pairing tab and the address dialog show of one account. The
     * address is opened from its sealed copy only here, for the owner.
     *
     * @return array{id: string, name: string, hasFeed: bool, address: ?string, lastReceivedAt: ?\DateTimeImmutable, check: array{channel: string, authserv: ?string, server: string, verdict: string}|null, url: string, archived: bool}
     */
    private function feedView(LegalEntity $firm, FirmBankAccount $account, ?BankFeed $feed): array
    {
        $address = null;
        if ($feed instanceof BankFeed) {
            try {
                $address = $this->feeds->revealAddress($feed);
            } catch (AddressRefused) {
                // The server lost its template or key: the address cannot be shown, only replaced.
            }
        }

        return [
            'id' => $account->id(),
            'name' => $this->name($firm, $account),
            'hasFeed' => $feed instanceof BankFeed,
            'address' => $address,
            'lastReceivedAt' => $feed?->lastReceivedAt(),
            'check' => $feed instanceof BankFeed ? $this->checkView($this->checks->lastOf($feed->id())) : null,
            'url' => $this->generateUrl('app_firm_bank_address', ['id' => $firm->id(), 'account' => $account->id()]),
            'archived' => $account->isArchived(),
        ];
    }

    /**
     * Whose results the last message carried on top, and what Foresi made of it,
     * for the owner setting up trust: the authserv-id, never anything else of it.
     *
     * @return array{channel: string, authserv: ?string, server: string, verdict: string}|null
     */
    private function checkView(?TrustCheck $check): ?array
    {
        if (!$check instanceof TrustCheck) {
            return null;
        }

        return [
            'channel' => $this->translator->trans('bank.account.address.check.channel.'.$check->channel->value),
            'authserv' => $check->authservId,
            'server' => $this->translator->trans(null === $check->authservId ? 'bank.account.address.check.no_header' : ($check->trusted ? 'bank.account.address.check.trusted' : 'bank.account.address.check.untrusted')),
            'verdict' => $check->trust instanceof MessageTrust
                ? $this->translator->trans('bank.message.trust.'.$check->trust->value).(null === $check->basis ? '' : ' ('.$check->basis.')')
                : $this->translator->trans('bank.account.address.check.pending'),
        ];
    }

    /**
     * Why no address can be generated on this server, as a translated line; null when it can.
     */
    private function unavailable(): ?string
    {
        if (!$this->addresses->isConfigured()) {
            return $this->translator->trans('bank.account.address.refused.'.AddressRefusal::NotConfigured->value);
        }
        if (!$this->secrets->isConfigured()) {
            return $this->translator->trans('bank.account.address.refused.'.AddressRefusal::NoKey->value);
        }

        return null;
    }

    /**
     * The label, or the account number when there is none.
     */
    private function name(LegalEntity $firm, FirmBankAccount $account): string
    {
        return '' !== $account->label() ? $account->label() : ($this->domestic($firm, $account->iban()) ?? $this->grouped($account->iban()));
    }

    private function domestic(LegalEntity $firm, string $iban): ?string
    {
        try {
            return $this->countries->for($firm->address()->countryCode)->domesticAccount($iban);
        } catch (UnsupportedCountry) {
            return null;
        }
    }

    private function grouped(string $iban): string
    {
        return trim(chunk_split($iban, 4, ' '));
    }

    /**
     * @return array<string, string>
     */
    private function labelErrors(string $label): array
    {
        return mb_strlen($label) > FirmBankAccount::MAX_LABEL_LENGTH ? ['label' => 'bank.account.error.label'] : [];
    }

    /**
     * @return array<string, string>
     */
    private function refusal(BankAccountRefused $refused): array
    {
        return [self::FIELD_OF_REFUSAL[$refused->reason->value] => $refused->getMessage()];
    }

    private function firm(string $id): LegalEntity
    {
        try {
            return $this->firms->get($id);
        } catch (UnknownLegalEntity) {
            throw new NotFoundHttpException();
        }
    }

    /**
     * An account of this firm; one of another firm or tenant is not found.
     */
    private function account(LegalEntity $firm, string $id): FirmBankAccount
    {
        try {
            $account = $this->accounts->get($id);
        } catch (UnknownFirmBankAccount) {
            throw new NotFoundHttpException();
        }
        if ($account->firm()->id() !== $firm->id()) {
            throw new NotFoundHttpException();
        }

        return $account;
    }

    /**
     * For a page that shows an inbound address: who knows it can send payment mail
     * in, so neither the browser nor anything between keeps a copy.
     */
    private function unstored(): Response
    {
        $response = new Response();
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function denyUnlessOwner(): void
    {
        if (!$this->owner->isOwner()) {
            throw new AccessDeniedHttpException('Only the owner manages firms.');
        }
    }

    private function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
