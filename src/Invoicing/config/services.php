<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

use Foresi\Invoicing\Application\BilledWork;
use Foresi\Invoicing\Application\BilledWorkRelease;
use Foresi\Invoicing\Application\CancelPayment;
use Foresi\Invoicing\Application\ConflictRetry;
use Foresi\Invoicing\Application\CustomerCopies;
use Foresi\Invoicing\Application\CustomerPicker;
use Foresi\Invoicing\Application\DocumentLinks;
use Foresi\Invoicing\Application\DocumentTitles;
use Foresi\Invoicing\Application\EditInvoice;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Application\ImportedDocuments;
use Foresi\Invoicing\Application\ImportIssuedDocument;
use Foresi\Invoicing\Application\InvoiceBalances;
use Foresi\Invoicing\Application\InvoicePayments;
use Foresi\Invoicing\Application\InvoicePdf;
use Foresi\Invoicing\Application\InvoiceSendQueue;
use Foresi\Invoicing\Application\InvoiceSends;
use Foresi\Invoicing\Application\InvoiceStore;
use Foresi\Invoicing\Application\InvoicingCountries;
use Foresi\Invoicing\Application\InvoicingCountry;
use Foresi\Invoicing\Application\IssuedDocumentFactory;
use Foresi\Invoicing\Application\IssueInvoice;
use Foresi\Invoicing\Application\IssueRecurringInvoices;
use Foresi\Invoicing\Application\IssueRecurringOccurrence;
use Foresi\Invoicing\Application\IssueRules;
use Foresi\Invoicing\Application\LinePricing;
use Foresi\Invoicing\Application\LinkTokens;
use Foresi\Invoicing\Application\MailGateway;
use Foresi\Invoicing\Application\MailHostPolicy;
use Foresi\Invoicing\Application\MailSecrets;
use Foresi\Invoicing\Application\MailServers;
use Foresi\Invoicing\Application\MailTemplates;
use Foresi\Invoicing\Application\MailVariables;
use Foresi\Invoicing\Application\MarkDelivered;
use Foresi\Invoicing\Application\NewInvoiceDefaults;
use Foresi\Invoicing\Application\NumberAllocator;
use Foresi\Invoicing\Application\NumberCounter;
use Foresi\Invoicing\Application\OriginalDocuments;
use Foresi\Invoicing\Application\PaymentSourceRelease;
use Foresi\Invoicing\Application\PublicDocuments;
use Foresi\Invoicing\Application\RecordPayment;
use Foresi\Invoicing\Application\RecurringAlertTexts;
use Foresi\Invoicing\Application\RecurringInvoices;
use Foresi\Invoicing\Application\RecurringIssueQueue;
use Foresi\Invoicing\Application\RecurringLifecycle;
use Foresi\Invoicing\Application\SaveRecurringInvoice;
use Foresi\Invoicing\Application\SendInvoice;
use Foresi\Invoicing\Application\SendLimits;
use Foresi\Invoicing\Application\SentFiles;
use Foresi\Invoicing\Application\SeriesCatalog;
use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Application\UndoImportedDocuments;
use Foresi\Invoicing\Infrastructure\CollectedBilledWork;
use Foresi\Invoicing\Infrastructure\CollectedBilledWorkRelease;
use Foresi\Invoicing\Infrastructure\CollectedPaymentSourceRelease;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineConflictRetry;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineFirmInvoicingSettings;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineImportedDocuments;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineInvoicePayments;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineInvoiceSends;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineInvoiceStore;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineMailServers;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineMailTemplates;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineNumberCounter;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrinePublicDocuments;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineRecurringInvoices;
use Foresi\Invoicing\Infrastructure\Doctrine\DoctrineSeriesCatalog;
use Foresi\Invoicing\Infrastructure\Doctrine\OwingInvoiceCondition;
use Foresi\Invoicing\Infrastructure\Doctrine\PaymentBalances;
use Foresi\Invoicing\Infrastructure\Mail\DiskSentFiles;
use Foresi\Invoicing\Infrastructure\Mail\DnsMailHostPolicy;
use Foresi\Invoicing\Infrastructure\Mail\RateLimitedSends;
use Foresi\Invoicing\Infrastructure\Mail\RouterDocumentLinks;
use Foresi\Invoicing\Infrastructure\Mail\SodiumMailSecrets;
use Foresi\Invoicing\Infrastructure\Mail\SymfonyMailGateway;
use Foresi\Invoicing\Infrastructure\Mail\TranslatedRecurringAlertTexts;
use Foresi\Invoicing\Infrastructure\Messenger\MessengerInvoiceSendQueue;
use Foresi\Invoicing\Infrastructure\Messenger\MessengerRecurringIssueQueue;
use Foresi\Invoicing\Infrastructure\Pdf\DiskOriginalDocuments;
use Foresi\Invoicing\Infrastructure\Pdf\OriginalFirstInvoicePdf;
use Foresi\Invoicing\Infrastructure\Pdf\PdfEngine;
use Foresi\Invoicing\Infrastructure\Pdf\TcpdfInvoicePdf;
use Foresi\Invoicing\Infrastructure\Pdf\TranslatedDocumentTitles;
use Foresi\Tests\Support\Mail\ExampleHostResolver;
use Foresi\Tests\Support\Mail\RecordingMailGateway;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\closure;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * The services of the Invoicing module, loaded by Foresi\Invoicing\InvoicingBundle.
 */
return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    // Integration code is registered by the kernel, only when its module is installed (ADR 0033).
    $services->load('Foresi\\Invoicing\\', '../')
        ->exclude(['../config/', '../Integration/', '../InvoicingBundle.php']);

    $parameters = $container->parameters();
    // A firm's mail server may not be in a private, loopback or link-local network,
    // as people type the host. An installation whose server is inside sets this.
    $parameters->set('app.mail.allow_private_hosts', false);
    // Links in sent mail are built on DEFAULT_URI; a loopback base would make them dead,
    // so sending refuses one. Development and the tests allow it.
    $parameters->set('app.mail.allow_local_links', \in_array($container->env(), ['dev', 'test', 'e2e'], true));
    // The PDFs exactly as they were sent; never below public/. Back it up with the database.
    $parameters->set('app.sent_documents_dir', '%kernel.share_dir%/sent-documents');
    // The PDFs imported documents keep as their originals; never below public/. Back it up with the database.
    $parameters->set('app.original_documents_dir', '%kernel.share_dir%/original-documents');

    // A country module plugs into invoicing with its rules.
    $builder->registerForAutoconfiguration(InvoicingCountry::class)->addTag('app.invoicing_country');
    // Ports served by other modules; each collects its providers (ADR 0034, rule 3).
    $builder->registerForAutoconfiguration(BilledWork::class)->addTag('app.billed_work');
    $builder->registerForAutoconfiguration(BilledWorkRelease::class)->addTag('app.billed_work_release');
    $builder->registerForAutoconfiguration(PaymentSourceRelease::class)->addTag('app.payment_source_release');

    $services->set(InvoicingCountries::class)
        ->arg('$countries', tagged_iterator('app.invoicing_country'));
    $services->set(PdfEngine::class)
        ->arg('$logoDirectories', [param('app.firm_image_dir')]);

    // Mail goes out through the firm's own server. Named here so the test and e2e
    // environments can swap the gateway, and PHPStan, which reads the dev container, knows the ids.
    $services->alias(MailGateway::class, SymfonyMailGateway::class);
    $services->alias(MailServers::class, DoctrineMailServers::class);
    $services->alias(MailSecrets::class, SodiumMailSecrets::class);
    $services->alias(MailHostPolicy::class, DnsMailHostPolicy::class);
    $services->alias(MailTemplates::class, DoctrineMailTemplates::class);
    $services->alias(DocumentTitles::class, TranslatedDocumentTitles::class);
    $services->alias(RecurringAlertTexts::class, TranslatedRecurringAlertTexts::class);
    $services->alias(InvoiceSends::class, DoctrineInvoiceSends::class);
    $services->alias(SentFiles::class, DiskSentFiles::class);
    // What is still owed on invoices, from the recorded payments; the SQL
    // condition comes from the same implementation, which passes
    // InvoiceBalancesContract and is authoritative, so reminders may be switched on.
    $services->alias(InvoiceBalances::class, PaymentBalances::class);
    $services->alias(OwingInvoiceCondition::class, PaymentBalances::class);
    $services->alias(DocumentLinks::class, RouterDocumentLinks::class);
    $services->alias(SendLimits::class, RateLimitedSends::class);
    $services->alias(InvoiceSendQueue::class, MessengerInvoiceSendQueue::class);
    $services->alias(RecurringIssueQueue::class, MessengerRecurringIssueQueue::class);
    $services->alias(PublicDocuments::class, DoctrinePublicDocuments::class);
    $services->alias(InvoicePayments::class, DoctrineInvoicePayments::class);
    // A cancelled bank payment returns its movement to the unmatched ones.
    $services->alias(PaymentSourceRelease::class, CollectedPaymentSourceRelease::class);
    // Time is the work an invoice may bill; issuing it locks the time.
    $services->alias(BilledWork::class, CollectedBilledWork::class);
    // An invoice in the trash frees its time, and restoring it bills the time again.
    $services->alias(BilledWorkRelease::class, CollectedBilledWorkRelease::class);

    // Links to sent documents are derived from the application secret, never stored.
    $services->set(LinkTokens::class)
        ->arg('$secret', '%kernel.secret%');

    $services->set(DiskSentFiles::class)
        ->arg('$root', param('app.sent_documents_dir'));
    $services->set(DiskOriginalDocuments::class)
        ->arg('$root', param('app.original_documents_dir'));
    // Drawn documents, except an imported one that kept the PDF of its source.
    $services->alias(InvoicePdf::class, TcpdfInvoicePdf::class);
    $services->set(OriginalFirstInvoicePdf::class)
        ->decorate(InvoicePdf::class)
        ->arg('$drawn', service('.inner'));

    // Declared here (private) so PHPStan, which reads the dev container, knows the
    // id used by the tests; it is made public in the test env below.
    $services->alias('test.invoice_balances', InvoiceBalances::class);

    if (\in_array($container->env(), ['test', 'e2e'], true)) {
        // Nothing is ever sent from the tests: the gateway records instead.
        $services->alias(MailGateway::class, RecordingMailGateway::class)->public();
        $services->set(RecordingMailGateway::class);
        // ...and no test asks a real DNS server for a mail host.
        $services->set(DnsMailHostPolicy::class)
            ->arg('$resolver', closure(service(ExampleHostResolver::class)));
        $services->set(ExampleHostResolver::class);
    }

    if ('test' === $container->env()) {
        // Inside the test cache, so a test never writes to the real share directory.
        $parameters->set('app.sent_documents_dir', '%kernel.cache_dir%/sent-documents');
        $parameters->set('app.original_documents_dir', '%kernel.cache_dir%/original-documents');

        // Public for the tests and the worker of the parallel counter test, which use the ports directly.
        $services->alias(NumberCounter::class, DoctrineNumberCounter::class)->public();
        $services->alias(SeriesCatalog::class, DoctrineSeriesCatalog::class)->public();
        $services->alias(FirmInvoicingSettings::class, DoctrineFirmInvoicingSettings::class)->public();
        $services->alias(InvoiceStore::class, DoctrineInvoiceStore::class)->public();
        $services->alias(InvoiceBalances::class, PaymentBalances::class)->public();
        $services->alias('test.invoice_balances', InvoiceBalances::class)->public();
        $services->alias(ConflictRetry::class, DoctrineConflictRetry::class)->public();
        foreach ([
            IssueInvoice::class, EditInvoice::class, NewInvoiceDefaults::class, CustomerPicker::class, TrashInvoice::class,
            MarkDelivered::class, IssuedDocumentFactory::class, IssueRules::class, CustomerCopies::class, LinePricing::class,
            NumberAllocator::class,
            // The recurring templates and their daily run, for the tests that drive them directly.
            SaveRecurringInvoice::class, RecurringLifecycle::class, IssueRecurringOccurrence::class,
        ] as $service) {
            $services->set($service)->public();
        }
        $services->set(IssueRecurringInvoices::class)->public()->tag('app.scheduled_job');
        $services->alias(RecurringInvoices::class, DoctrineRecurringInvoices::class)->public();

        // Sending, for the tests that drive it directly.
        $services->alias(MailServers::class, DoctrineMailServers::class)->public();
        $services->alias(MailSecrets::class, SodiumMailSecrets::class)->public();
        $services->alias(MailHostPolicy::class, DnsMailHostPolicy::class)->public();
        $services->alias(MailTemplates::class, DoctrineMailTemplates::class)->public();
        $services->set(MailVariables::class)->public();
        $services->set(SendInvoice::class)->public();
        $services->set(LinkTokens::class)->public()
            ->arg('$secret', '%kernel.secret%');
        $services->alias(InvoiceSends::class, DoctrineInvoiceSends::class)->public();
        $services->alias(DocumentLinks::class, RouterDocumentLinks::class)->public();
        $services->alias(SentFiles::class, DiskSentFiles::class)->public();
        $services->set(ImportIssuedDocument::class)->public();
        $services->set(UndoImportedDocuments::class)->public();
        $services->alias(OriginalDocuments::class, DiskOriginalDocuments::class)->public();
        $services->alias(ImportedDocuments::class, DoctrineImportedDocuments::class)->public();
        $services->alias(InvoicePayments::class, DoctrineInvoicePayments::class)->public();
        $services->set(RecordPayment::class)->public();
        $services->set(CancelPayment::class)->public();
    }

    if ('e2e' === $container->env()) {
        // Public handles for tests/e2e/seed.php.
        $services->alias('e2e.mail_secrets', MailSecrets::class)->public();
        $services->alias('e2e.issue_invoice', IssueInvoice::class)->public();
        $services->alias('e2e.send_invoice', SendInvoice::class)->public();
        $services->alias('e2e.link_tokens', LinkTokens::class)->public();
        $services->alias('e2e.record_payment', RecordPayment::class)->public();
        $services->alias('e2e.save_recurring', SaveRecurringInvoice::class)->public();
        $services->alias('e2e.recurring_lifecycle', RecurringLifecycle::class)->public();
        $services->alias('e2e.issue_recurring', IssueRecurringOccurrence::class)->public();
    }
};
