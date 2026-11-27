# Writing a module

This guide is for anyone who writes a Foresi module of their own: a Composer package that adds screens, API operations, MCP tools, events and settings to an instance, without a change in this repository. It teaches the extension contract of [ADR 0044](adr/0044-public-extension-contract.md). The ADRs say why the contract is built this way; this guide says how to use it.

The guide has four parts:

- [Tutorial: your first module](#tutorial-your-first-module) builds the example module `acme/fleet` step by step.
- [How-to guides](#how-to-guides) solve one task each: an operation, a screen, a listener, an integration, a setting, fields on a record, tests.
- [Reference](#reference) lists the manifest, the naming rules and every extension point.
- [Explanation](#explanation) says what the contract is, how its versions work and how something leaves it.

The example module lives in [`tests/Fixtures/modules/acme-fleet`](../tests/Fixtures/modules/acme-fleet). It is the source of truth for this guide: the External module workflow installs it into the application on every pull request and runs its tests. If this guide and the module disagree, the module is right, and the guide has a bug.

## Tutorial: your first module

You will build `acme/fleet`, the vehicles of a firm. When you are done it has:

- an entity `Vehicle` with its own table and migration,
- a screen for the staff at `/acme-fleet/vehicles`, with an item in the sidebar,
- a write operation, both `POST /api/v1/acme-fleet/vehicles` and the MCP tool `acme_fleet_add_vehicle`,
- a read tool `acme_fleet_list_vehicles`,
- a scope `acme_fleet_vehicles`,
- an event of its own, `acme-fleet.vehicle.added`,
- a listener of the address book's event `contacts.contact.archived`,
- a setting, the most vehicles a firm keeps.

You need a checkout of Foresi that runs (see [development.md](development.md)). The module goes in a directory of its own. Here it is `tests/Fixtures/modules/acme-fleet`, so you can compare each step with the real file.

### 1. The manifest

A module is a Composer package of type `foresi-module`, and its `composer.json` is its manifest (ADR 0029). Start with this one:

```json
{
    "name": "acme/fleet",
    "description": "The vehicles of a firm.",
    "type": "foresi-module",
    "license": "AGPL-3.0-only",
    "require": {
        "php": ">=8.4",
        "foresi/module-api": "^2.0",
        "foresi/contacts": "*",
        "foresi/identity": "*",
        "foresi/party": "*",
        "foresi/shared": "*",
        "foresi/ui": "*"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Fleet\\": ""
        },
        "exclude-from-classmap": [
            "migrations/",
            "tests/"
        ]
    },
    "extra": {
        "foresi": {
            "module": "acme-fleet",
            "kind": "module",
            "namespace": "Acme\\Fleet\\",
            "bundle": "Acme\\Fleet\\AcmeFleetBundle",
            "optional": [],
            "scopes": [
                "acme_fleet_vehicles"
            ]
        }
    }
}
```

The real file also requires the Doctrine and Symfony packages that the code imports; list yours the same way.

What matters here:

- `"foresi/module-api": "^2.0"` says which versions of the module API the module is written for: 2.0, which gave the modules their current names, up to but not including 3.0. Foresi provides the module API as a virtual package, so Composer refuses to install the module into a Foresi that does not provide a version in the range. A module without the range does not boot.
- The namespace `Acme\Fleet\` gives everything else its name. The module id is `acme-fleet`, and the bundle is `Acme\Fleet\AcmeFleetBundle`. `extra.foresi.module` and `extra.foresi.bundle` must say exactly that, and `autoload.psr-4` must declare the same namespace. See [Names](#names).
- The modules you need are ordinary Composer requirements: the module uses Identity, Party, Shared and Ui, so it requires them. A module you can work without goes in `extra.foresi.optional` instead (see [Integrate with an optional module](#integrate-with-an-optional-module)).
- `scopes` declares the resources an API token can be given: `acme_fleet_vehicles` gives the scopes `acme_fleet_vehicles:read` and `acme_fleet_vehicles:write`.
- `migrations/` is left out of the class map, because the migrations are loaded by Doctrine Migrations, not by the autoloader.

### 2. The bundle

Every module has one bundle at the root of its namespace. It extends `ModuleBundle`, one of the few classes outside a `Contract\` namespace that are part of the contract (marked `@api`).

```php
namespace Acme\Fleet;

use Foresi\Shared\Infrastructure\Module\ModuleBundle;

final class AcmeFleetBundle extends ModuleBundle
{
}
```

That is enough for the bundle to do its work. The kernel registers the bundle of every installed module, and the bundle then:

- loads `config/services.php`,
- maps the Doctrine entities in `Domain/` (and in `Contract/`, if it holds any),
- registers `migrations/` under the namespace `Acme\Fleet\Migrations`,
- makes the templates in `templates/` available as `@AcmeFleet/…` and the translations in `translations/` available in the `messages` domain,
- maps Stimulus controllers in `assets/controllers/` under `@acme-fleet`.

The real bundle also defines a setting; you add it in step 9.

Now the services. `config/services.php` loads every class of the module, autowired and autoconfigured:

```php
return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();
    $services->load('Acme\\Fleet\\', '../')
        ->exclude(['../config/', '../migrations/', '../templates/', '../tests/', '../translations/', '../AcmeFleetBundle.php']);
};
```

Autoconfiguration is what plugs the module in. Every interface of the contract that the core collects carries its service tag (`#[AutoconfigureTag]`), so a class that implements it is found without a tag in your configuration.

Install the module into your checkout and check it:

```bash
composer config repositories.acme-fleet path tests/Fixtures/modules/acme-fleet
composer require acme/fleet:@dev
bin/console app:modules:check
```

`app:modules:check` says whether every installed module is written for the module API that this Foresi provides. `bin/console app:modules` lists the installed modules and their integrations.

### 3. The entity and its migration

The entity lives in `Domain/`. Its table starts with the module id in snake case, so it never collides with another module's:

```php
namespace Acme\Fleet\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'acme_fleet_vehicle')]
#[ORM\UniqueConstraint(name: 'acme_fleet_vehicle_plate', columns: ['plate'])]
class Vehicle
{
    // id, name, plate, driverContactId, createdAt
}
```

The migration lives in `migrations/`, in the namespace `Acme\Fleet\Migrations`, and creates exactly the tables of the module. Foresi orders the migrations of all modules by their version, so name it with the time you write it, such as `Version20261125120000`. Write it with the Doctrine schema API, not with SQL, so it runs on PostgreSQL and on the SQLite of the tests. See [`migrations/Version20261125120000.php`](../tests/Fixtures/modules/acme-fleet/migrations/Version20261125120000.php).

```bash
bin/console doctrine:migrations:migrate
bin/console doctrine:schema:validate
bin/console app:migrations:owners
```

`app:migrations:owners` checks that every table of the database is created by the migrations of exactly one installed module.

### 4. The use cases

All surfaces of a module (the screen, REST and MCP) call the same use cases, so a rule is written once. In acme/fleet they are the methods of `Application/Fleet`: `add()`, `all()` and `withoutDriver()`.

A use case refuses what it cannot do by throwing an exception that implements `Foresi\Shared\Contract\Refused`. The core turns it into the answer of each surface: a REST error with its status, a refused MCP call, a message on the screen. `RefusalKind` says which kind of refusal it is (`Invalid`, `Conflict`, `Forbidden` and so on). See [`Application/VehicleRefused.php`](../tests/Fixtures/modules/acme-fleet/Application/VehicleRefused.php).

Who may do something is decided in the use case, not in the controller. Fleet is for the staff, so it asks `Foresi\Identity\Contract\StaffCheck` whether the person signed in is an owner or member of the organisation:

```php
if (!$this->staff->isStaff()) {
    throw VehicleRefused::notStaff();
}
```

### 5. The operation: REST and MCP at once

A write is declared once, with the attribute `#[Operation]` on the input class of its use case. Foresi derives the REST endpoint and the MCP tool from it, with the same scope check, the same idempotency key and the same refusals (ADR 0032).

```php
#[Operation(
    name: 'acme-fleet.vehicles.add',
    method: 'POST',
    path: '/api/v1/acme-fleet/vehicles',
    tool: 'acme_fleet_add_vehicle',
    scope: 'acme_fleet_vehicles:write',
    title: 'Add a vehicle',
    description: 'Adds a vehicle to the firm\'s fleet, with its name, its licence plate and optionally the id of the contact who drives it. …',
    status: 201,
    staffOnly: true,
)]
final readonly class AddVehicleInput
{
    public function __construct(
        #[Field('What the vehicle is, such as "Delivery van".', maxLength: Vehicle::MAX_NAME)]
        public string $name,
        #[Field('The licence plate, such as "1AB 2345".', maxLength: Vehicle::MAX_PLATE)]
        public string $plate,
        #[Field('The id of the contact who drives it; left out for none.')]
        public ?string $driverContactId = null,
    ) {
    }
}
```

Every constructor parameter is a field, sent in snake case (`driver_contact_id`). The `description` is written for an AI agent: say what the operation changes and when to use it.

A handler runs it. It implements `OperationHandler`, names its input class, calls the use case and returns the data and a sentence for MCP:

```php
final readonly class AddVehicleHandler implements OperationHandler
{
    public function __construct(private Fleet $fleet)
    {
    }

    public static function input(): string
    {
        return AddVehicleInput::class;
    }

    public function handle(object $input): OperationOutput
    {
        \assert($input instanceof AddVehicleInput);
        $vehicle = $this->fleet->add($input);

        return new OperationOutput(Fleet::json($vehicle), \sprintf('Added the vehicle %s (%s) to the fleet.', $vehicle->name(), $vehicle->plate()));
    }
}
```

Check the route:

```bash
bin/console debug:router | grep acme-fleet
```

### 6. A read tool

Reads over MCP are tools of their own. `ListVehiclesTool` implements `ReadTool`, and `StaffOnlyTool` because a guest's token must never see it:

```php
final readonly class ListVehiclesTool implements ReadTool, StaffOnlyTool
{
    public function name(): string
    {
        return 'acme_fleet_list_vehicles';
    }

    public function scope(): string
    {
        return 'acme_fleet_vehicles:read';
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $vehicles = $this->fleet->all();
        } catch (VehicleRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(['items' => array_map(Fleet::json(...), $vehicles)], \sprintf('%d vehicles, in name order.', \count($vehicles)));
    }

    // title(), description(), staffOnly() and inputSchema()
}
```

The name of every tool starts with the module's prefix, `acme_fleet_`. The core refuses a tool whose name does not, and two tools with the same name. See [`Infrastructure/Mcp/ListVehiclesTool.php`](../tests/Fixtures/modules/acme-fleet/Infrastructure/Mcp/ListVehiclesTool.php).

### 7. The screen and the sidebar

A screen is a Symfony controller. Its route name and path start with the module id:

```php
final class VehicleController extends AbstractController
{
    public const string ROUTE = 'acme_fleet_vehicles';

    #[Route('/acme-fleet/vehicles', name: self::ROUTE, methods: ['GET'])]
    public function index(): Response
    {
        try {
            $vehicles = $this->fleet->all();
        } catch (VehicleRefused $refused) {
            throw new AccessDeniedHttpException($refused->getMessage(), $refused);
        }

        return $this->render('@AcmeFleet/vehicles/index.html.twig', ['vehicles' => array_map(Fleet::json(...), $vehicles)]);
    }
}
```

The template extends the shell of Ui, `@Ui/layout/app.html.twig`, and fills its `title` and `content` blocks. Its texts are translation keys of the module, `acme_fleet.…`, in `translations/messages.en.yaml`:

```twig
{% extends '@Ui/layout/app.html.twig' %}

{% set crumbs = [{label: 'acme_fleet.vehicles.title'|trans}] %}

{% block title %}{{ 'acme_fleet.vehicles.title'|trans }}{% endblock %}

{% block content %}
    {# the table of vehicles #}
{% endblock %}
```

The sidebar item comes from a `NavigationItemProvider`. It shows the item only to the staff, like the use case:

```php
final readonly class FleetNavigation implements NavigationItemProvider
{
    public function __construct(private StaffCheck $staff)
    {
    }

    public function items(): iterable
    {
        if ($this->staff->isStaff()) {
            yield new NavigationItem(CoreNavigationAreas::OVERVIEW, 'acme_fleet.nav.vehicles', 'puzzle', VehicleController::ROUTE, weight: 90, routePrefixes: ['acme_fleet_']);
        }
    }
}
```

The icon must be one of Ui's locked icon set ([Lucide](https://lucide.dev/) icons in `src/Ui/assets/icons/lucide/`).

### 8. An event, and a listener of someone else's

Other modules and webhooks learn what happened from contract events (ADR 0030). An event of the module is a class in `Contract/Event/`, because other modules read it:

```php
#[DomainEvent('acme-fleet.vehicle.added', version: 1)]
final readonly class VehicleAdded implements DomainEventPayload
{
    public function __construct(
        public string $vehicleId,
    ) {
    }

    public function subject(): EventSubject
    {
        return new EventSubject('vehicle', $this->vehicleId);
    }
}
```

The name starts with the module id, and the payload is thin: ids, not copies of the data. The use case records the event with `EventRecorder` in the same transaction as the change, so the event exists exactly when the change is committed:

```php
return $this->em->wrapInTransaction(function () use ($name, $plate, $input): Vehicle {
    // the checks
    $this->em->persist($vehicle);
    $this->em->flush();
    $this->events->record(new VehicleAdded($vehicle->id()));

    return $vehicle;
});
```

`record()` outside a transaction throws a `LogicException`.

A listener of another module's event implements `DomainEventListener`. Fleet takes an archived contact off every vehicle it drove:

```php
final readonly class DropArchivedDriver implements DomainEventListener
{
    public function listensTo(): string
    {
        return 'contacts.contact.archived';
    }

    public function __invoke(RecordedEvent $event): void
    {
        if ($event->payload instanceof ContactArchived) {
            $this->fleet->withoutDriver($event->payload->contactId);
        }
    }
}
```

Listeners run after the commit, from the event relay, so they must be idempotent: the relay may call one again for the same event. The relay flushes the unit of work after the listener, so the listener only changes entities. The class name is the listener's key, under which the relay records what it has delivered. Do not rename the class once the module is in use.

### 9. A setting

A module's settings are its bundle's configuration. Define them in `configure()`, and pass them to the services in `loadExtension()`:

```php
final class AcmeFleetBundle extends ModuleBundle
{
    public const int MAX_VEHICLES = 25;

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('max_vehicles')
                    ->info('The most vehicles the firm keeps; adding one more is refused.')
                    ->min(1)
                    ->defaultValue(self::MAX_VEHICLES)
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $container->parameters()->set('acme_fleet.max_vehicles', $config['max_vehicles']);
    }
}
```

Call `parent::loadExtension()`, because that is where the bundle loads `config/services.php`. A service then takes the value with `#[Autowire(param: 'acme_fleet.max_vehicles')]`. The application sets it in `config/packages/acme_fleet.yaml`:

```yaml
acme_fleet:
    max_vehicles: 40
```

### 10. Tests

The module's tests run in the application it is installed in, with the application's kernel and environment. The package has its own `phpunit.xml.dist`, and its `tests/bootstrap.php` requires the application's bootstrap and registers the module's test namespace:

```php
$application = dirname(__DIR__, 5);
require $application.'/tests/bootstrap.php';
(require $application.'/vendor/autoload.php')->addPsr4('Acme\\Fleet\\Tests\\', __DIR__);
```

The screen tests need the front-end packages of the application, so install them once:

```bash
bin/console importmap:install
vendor/bin/phpunit -c tests/Fixtures/modules/acme-fleet/phpunit.xml.dist
```

The tests of acme/fleet cover each step of this tutorial: [`tests/`](../tests/Fixtures/modules/acme-fleet/tests). They use test support classes of this repository (`Foresi\Tests\…`, such as `ApiTestCase` and `SignedInBrowser`), which are not part of the contract and are not published as a package yet. A module outside this repository writes its own helpers for now; see [Not in the contract yet](#not-in-the-contract-yet).

That is the whole module. The [External module workflow](../.github/workflows/external-module.yml) runs these steps on every pull request to Foresi: it installs the package from a path repository, runs `app:modules:check` (and `--api=3.0`, which must fail), migrates an empty PostgreSQL 17 database and runs the tests.

## How-to guides

Each guide assumes a module with a manifest and a bundle, as in steps 1 and 2 of the tutorial.

### Add an operation

1. Add a resource to `extra.foresi.scopes` if the operation needs a scope that the module does not declare yet.
2. Write the use case, and a `Refused` exception for what it refuses.
3. Write the input class with `#[Operation]` on the class and `#[Field]` on each constructor parameter:
   - `name`: `<id>.<resource>.<action>`, such as `acme-fleet.vehicles.add`.
   - `method`: `POST` to create, `PUT` for a whole replacement, `PATCH` for a partial update, `DELETE`.
   - `path`: below `/api/v1/<id>/`. A parameter in the path, such as `{vehicleId}`, comes from the path over REST and as `vehicle_id` over MCP.
   - `tool`: `<prefix>_<verb>_<thing>`, such as `acme_fleet_add_vehicle`.
   - `scope`: `<resource>:write`.
   - `destructive: true` for an operation that destroys something or cannot be undone.
   - `staffOnly: true` when a guest must never call it, even with the scope.
4. For a partial update, default each optional field to `new Omitted()`: a field left out keeps its value, and a field sent as `null` clears it (`public string|Omitted|null $phone = new Omitted()`).
5. Write the `OperationHandler`: `input()` returns the input class, and `handle()` calls the use case and returns an `OperationOutput` with the data and one sentence that says what was done.

Leave `response` out. It names a schema of the OpenAPI document, and a module cannot add schemas to the document yet; see [Not in the contract yet](#not-in-the-contract-yet).

A tool that no operation describes, such as a write that only MCP offers, implements `WriteTool` instead. `ToolSchema` has the JSON Schema of the paging arguments and readers of plain arguments.

### Add a screen with a navigation item

1. Write a controller with `#[Route]`. The route name starts with `<id_snake>_` and the path with `/<id>/`. Controllers are found by their `#[Route]` attributes, so there is nothing to register.
2. Put the template in `templates/` and render it as `@<Module>/…`, where `<Module>` is the id in StudlyCase (`@AcmeFleet`). Extend `@Ui/layout/app.html.twig`, and use the `@Ui` Twig components for what the shell already has.
3. Put the texts in `translations/messages.<locale>.yaml`, under the key `<id_snake>`.
4. Implement `NavigationItemProvider` and yield a `NavigationItem` with an area of `CoreNavigationAreas` (or of a `NavigationAreaProvider`), a translation key, an icon of the locked set and the route. Give it `routePrefixes: ['<id_snake>_']`, so that the item stays marked on the module's other pages.
5. Show the item only to those whom the use case lets in. A staff-only screen asks `StaffCheck`, in the provider and in the use case.

To add something to a screen of another module instead of a page of your own, use a slot: implement `SlotProvider` and return items for a slot name listed in `Slots` or in the `…Slots` class of that module, such as `CashFlowSlots` or `InvoicingSlots`.

### Listen to a contract event

1. Find the event: its class is in the `Contract\Event\` namespace of the module that owns it, and the MCP tool `foresi_list_event_types` lists every name and version of the installed modules.
2. Require that module in `composer.json`. If the module can work without it, put the listener in an integration instead (next guide).
3. Implement `DomainEventListener`: `listensTo()` returns the event name, and `__invoke()` takes the `RecordedEvent` and checks the class of its payload.
4. Make it idempotent. Do not flush; the relay does.
5. Do not rename the class later: its name is the listener's key.

To publish an event of your own, put a `DomainEventPayload` with `#[DomainEvent('<id>.<resource>.<action>', version: 1)]` in `Contract/Event/`, and record it with `EventRecorder::record()` inside the transaction of the change. A new field in the payload keeps the version; anything else is a new version.

### Integrate with an optional module

An integration is code that a module keeps for another module that it can work without (ADR 0033). It is loaded only when that other module is installed.

1. Add the other module's id to `extra.foresi.optional`, such as `"optional": ["calendar"]`. Do not add it to `require`.
2. Put the code in `Integration/<Other>/`, where `<Other>` is the other module's id in StudlyCase: `Integration/Calendar/`, namespace `Acme\Fleet\Integration\Calendar\`. A directory for a module that the manifest does not list as optional stops the boot.
3. Implement the other module's ports there. They are the interfaces in its `Contract\` namespace, such as `Foresi\Calendar\Contract\CalendarEntrySource`, which puts entries in a person's calendar feeds:

   ```php
   namespace Acme\Fleet\Integration\Calendar;

   use Foresi\Calendar\Contract\CalendarEntry;
   use Foresi\Calendar\Contract\CalendarEntrySource;
   use Foresi\Calendar\Contract\CalendarUids;

   final readonly class ServiceDates implements CalendarEntrySource
   {
       public function __construct(private CalendarUids $uids)
       {
       }

       public function key(): string
       {
           return 'acme-fleet.service';
       }

       public function entriesFor(string $userId, \DateTimeImmutable $from, \DateTimeImmutable $to): iterable
       {
           // yield new CalendarEntry($this->uids->of($this->key(), $id), $title, $start, null, true, $updatedAt, $url);
           return [];
       }
   }
   ```

4. Templates, translations and Stimulus controllers that only the integration needs go in `Integration/<Other>/templates/`, `translations/` and `assets/controllers/`. The bundle registers them only when the other module is installed.
5. Configuration that only the integration needs goes in the bundle's `prepend()`, behind `$this->integrationLoaded('calendar', $builder)`.

`bin/console app:modules` shows each integration and whether it is loaded. acme/fleet has no integration; the modules in `src/*/Integration/` of this repository have many.

### Add a setting

1. Define it in the bundle's `configure()`, with `info()` and a default.
2. In `loadExtension()`, call `parent::loadExtension()` first, then set a container parameter named `<id_snake>.<setting>`.
3. Inject it with `#[Autowire(param: '<id_snake>.<setting>')]`.
4. Say in the module's README how an application sets it: `config/packages/<id_snake>.yaml`, or an environment variable with `'%env(…)%'` for a secret, so that it never enters a committed file.

Foresi has no settings screen for modules. A setting that the people of a firm change themselves is data of the module, with its own screen and operation.

### Add fields to a record

A module adds custom fields to a record of another module: a contact, a deal, a task, a project or a product ([ADR 0045](adr/0045-custom-fields.md)). A field is a property of an entity of your module, in a table of your module; the record's own table never changes. Foresi shows the field in the card "Additional details" of the record's dialogs and detail, as a column of the record's list that a person adds, filters by it in the filter sheet, the quick filters, saved views and the `filter` of the record's `foresi_search_*` tool, sorts and searches by it when it says so, keeps its changes in the record's history under the field's label, and names it in the record's update event when its value changes.

1. Require the record's module and module API 2.0 in `composer.json`, such as `"foresi/contacts": "*"` and `"foresi/module-api": "^2.0"` for a contact (its extension interface, `ContactExtension`, is Party's, but the record and its screens are the address book's).
2. Write an entity in `Domain/` that implements the record's extension interface:

   | Record | Extension interface | Its id accessor | The list that shows the fields |
   | --- | --- | --- | --- |
   | Contact | `Foresi\Party\Contract\Field\ContactExtension` | `contactId()` | The address book, which also sorts by a sortable field |
   | Deal | `Foresi\Deals\Contract\Field\DealExtension` | `dealId()` | The deals |
   | Task | `Foresi\Work\Contract\Field\TaskExtension` | `taskId()` | The task list of a project |
   | Project | `Foresi\Work\Contract\Field\ProjectExtension` | `projectId()` | The projects |
   | Product | `Foresi\Catalog\Contract\Field\ProductExtension` | `productId()` | The products |

3. Give the entity the record's id as its only id, a `guid` column, and make every other mapped property a field with `#[CustomField]` from `Foresi\Shared\Contract\Field`. An extension has no associations and no other columns.
4. Map each field to a nullable column of a type its kind allows:

   | `FieldKind` | Column type | Value |
   | --- | --- | --- |
   | `Text`, `Url` | `string` | a string |
   | `LongText` | `text` | a string |
   | `Number` | `integer`, `smallint`, `bigint`, `decimal` or `float` | an int, a float, or a decimal as a string |
   | `Date` | `date_immutable` | a `DateTimeImmutable` |
   | `DateTime` | `datetime_immutable` | a `DateTimeImmutable` |
   | `Boolean` | `boolean` | a bool |
   | `Select` | `string` | the key of one of its options |
   | `MultiSelect` | `json` | a list of keys of its options |
   | `Contact`, `User` | `guid` | the id of a contact or a user |

5. Write the migration that creates the table. Name the table after the module, such as `acme_fleet_contact_extra`. On a large table, add a column only as nullable or with a constant default, and create an index concurrently.
6. Put the labels in `translations/messages.<locale>.yaml`: the field's `label` and each option's label are translation keys.

acme/fleet adds four fields to a contact ([`Domain/ContactExtra.php`](../tests/Fixtures/modules/acme-fleet/Domain/ContactExtra.php)):

```php
#[ORM\Entity]
#[ORM\Table(name: 'acme_fleet_contact_extra')]
class ContactExtra implements ContactExtension
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid')]
    private string $id;

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    #[CustomField(FieldKind::Select, 'acme_fleet.field.industry', options: ['logistics' => 'acme_fleet.industry.logistics', 'construction' => 'acme_fleet.industry.construction', 'retail' => 'acme_fleet.industry.retail'], filterable: true)]
    private ?string $industry = null;

    #[ORM\Column(name: 'contract_until', type: 'date_immutable', nullable: true)]
    #[CustomField(FieldKind::Date, 'acme_fleet.field.contract_until', filterable: true, sortable: true)]
    private ?\DateTimeImmutable $contractUntil = null;

    // account_code (a searchable text) and services (a multi-select) …

    public function contactId(): string
    {
        return $this->id;
    }
}
```

A field's key is the module's id and the property's name in snake case: `acme_fleet_industry`, `acme_fleet_contract_until`. It never changes, so renaming the property is a new field. A change of kind is a new field with a new key and a data migration; nothing changes a field's kind in place.

The flags of `#[CustomField]`, all off unless the table says otherwise:

| Flag | What it does |
| --- | --- |
| `filterable` | A field of the record's filters, saved views and quick filters, and of the `filter` of its search tool. A contact or a user field compares the id it holds, and the filter sheet picks the contact or the person by name. |
| `sortable` | A sort of the record's list where the list sorts by columns (the address book). Not for a long text or a multi-select. |
| `searchable` | The record's text search reads it. A text, a long text or a URL only. |
| `guests` | Guests see it too, and may change it where they may change the record. Without it, a guest gets no column, input or value of it, and a write of it by a guest is refused. |
| `required` | A new record needs a value: its dialogs mark it and refuse to create the record without one, and the short dialogs (a new contact from a picker, a new task or subtask) show only the required fields. A later change may clear it. |
| `api` (on) | Part of the REST and MCP payloads (since 1.7). Off, the field is left out of what an API token reads, and a write of it over the API is refused as an unknown field; a required field that is off is not required over the API. |
| `template` (on), `import` (on) | Part of the document templates and of the import and export. |

Foresi checks the declarations when it first reads the fields and stops with a `LogicException` that names the class and the property: a column that is not nullable, a type the kind does not allow, options on a kind that is no select (or none on a select), an option key that is not lowercase letters, digits and underscores, a sortable or searchable kind that cannot be, a column without `#[CustomField]`, an association, an id that is not one `guid`, or two fields of one key.

To read and write the fields, inject `Foresi\Shared\Contract\Field\Fields`, never your entity's repository: `value($record, $id, $key)` and `values($record, $ids)` read them, and `write($record, $id, ['acme_fleet_industry' => 'logistics'])` sets them, null clearing a value, and refuses a value its kind does not take with an `InvalidArgumentException`. The caller's transaction flushes the write. The entity's row exists only while the record has a value: Foresi creates it with the first value and removes it with the last.

Where a person sees and changes the fields, under "Additional details": the whole form and the detail of a contact, the form and the detail of a product, the dialogs and the side column of a deal, the sidebar of a task (each field saved on its own), and the new project dialog and the project's settings (the project's page shows none). Each input is the one of its kind: a text field, a number, a date, a date and time, a web address, a long text, a select, choice chips of a multi-select, a checkbox, and a picker of a contact or a person, which the filter sheet uses too. The record's update event (`contacts.contact.updated`, `deals.deal.updated`, `catalog.product.updated`, `work.task.updated`, `work.project.updated`) names the keys of the fields whose value changed in `customFields`, never the values.

Over the REST API and MCP (since 1.7), every contact, deal, task, project and product carries `custom_fields`, an object with each field the token's person sees by key, and null for one without a value: a select gives its option key, a multi-select a list of them, a date `YYYY-MM-DD`, a date and time ISO 8601 with an offset, a number a JSON number, a contact or a user its id. The create and update operations of the five records (`contacts.create`, `foresi_update_deal` and the others) take `custom_fields` with the keys to set; null clears one, and a key left out keeps its value. A create without a value for a required field is refused. A refusal is an invalid `custom_fields.<key>` with the reason `unknown_field`, `invalid_value` or `field_required`. `GET /api/v1/fields` and the MCP tool `foresi_list_fields` list the definitions, one record kind with `record`, each with its key, kind, label, options and flags; they only read, and nothing creates a field over the API. The OpenAPI document and the schemas of the MCP tools describe `custom_fields` from the definitions (`ContactCustomFields` and the like), and a filterable field is a field of the `filter` of the record's search tool and of its REST list.

### Run a job on a schedule

Implement `Foresi\Instance\Contract\ScheduledJob`. `name()` is a stable name of at most 64 characters, starting with the module id. `period()` returns the period that now falls in, and the scheduler runs `run()` once per period. A daily job computes its period in the instance's zone, which `period()` receives.

### Test a module

1. Add `phpunit.xml.dist` and `tests/bootstrap.php` to the package, as in [step 10](#10-tests).
2. Install the module into a checkout of Foresi with a path repository, then run `bin/console importmap:install`.
3. Run `bin/console app:modules:check`, the migrations, `doctrine:schema:validate`, `app:migrations:owners` and the tests, as the [External module workflow](../.github/workflows/external-module.yml) does. Copy that workflow into the module's own CI.
4. Before an upgrade of Foresi, run `bin/console app:modules:check --api=<version>` against the module API version that the upgrade brings.

## Reference

Every extension point below is part of the module API since version 1.0, the first version, unless it names a later one ("since 1.1").

### The manifest

The manifest is the module's `composer.json`.

| Key | Meaning |
| --- | --- |
| `name` | Any `vendor/name`. A package of the `foresi` vendor is named `foresi/<id>`. |
| `type` | `foresi-module`. |
| `require` | The modules the module cannot work without, and the range of the module API, such as `"foresi/module-api": "^2.0"`. Required; a module without it does not boot. |
| `autoload.psr-4` | The module's namespace, pointing to the package root. It must be the namespace in `extra.foresi.namespace`. |
| `autoload.exclude-from-classmap` | `migrations/`, and `tests/` if the package has tests. |
| `extra.foresi.module` | The module id. It must be the id that the namespace gives. |
| `extra.foresi.kind` | `module`. (`core` and `ui` are Foresi's own.) |
| `extra.foresi.namespace` | The namespace, such as `Acme\\Fleet\\`. |
| `extra.foresi.bundle` | The bundle class, `<namespace><Module>Bundle`, such as `Acme\\Fleet\\AcmeFleetBundle`. |
| `extra.foresi.optional` | The ids of the modules it integrates with when they are installed. Each has an `Integration/<Other>/` directory. A module cannot be both required and optional. |
| `extra.foresi.scopes` | The resources of its API scopes, each of lowercase letters, digits and underscores, at most 40 characters, such as `"acme_fleet_vehicles"`, or `{"resource": "…", "guest": "read"}` (or `"write"`) for one that a guest's token may hold. |

The core refuses an invalid manifest when the container is built, with a message that names the package and the key.

### Names

The namespace gives a module its names. For `Acme\Fleet\` they are:

| Name | Rule | acme/fleet | Checked |
| --- | --- | --- | --- |
| Namespace | `Vendor\Module\`, two segments or more, StudlyCase; the namespace `Foresi\` is only for `foresi/*` packages | `Acme\Fleet\` | yes |
| Module id | the namespace in kebab case, without `foresi-` for Foresi's own | `acme-fleet` | yes |
| Bundle | `<namespace><Module>Bundle` | `Acme\Fleet\AcmeFleetBundle` | yes |
| Extension alias, the configuration key | the id in snake case | `acme_fleet` | yes |
| Template namespace | the id in StudlyCase | `@AcmeFleet` | yes |
| Doctrine mapping | the id in StudlyCase | `AcmeFleet` | yes |
| Migrations namespace | `<namespace>Migrations` | `Acme\Fleet\Migrations` | yes |
| Asset namespace | `@` and the id | `@acme-fleet` | yes |
| MCP tool prefix | the id in snake case and `_`; `foresi_` for Foresi's own | `acme_fleet_add_vehicle` | yes, and unique across all tools |
| Event names | `<id>.<resource>.<action>`, lowercase | `acme-fleet.vehicle.added` | yes, by the event catalogue |
| Scope resources | start with the id in snake case | `acme_fleet_vehicles` | no |
| Operation names | start with the id | `acme-fleet.vehicles.add` | no |
| Route names | `<id_snake>_…` | `acme_fleet_vehicles` | no |
| REST paths | `/api/v1/<id>/…` | `/api/v1/acme-fleet/vehicles` | no |
| Screen paths | `/<id>/…` | `/acme-fleet/vehicles` | no |
| Tables and indexes | `<id_snake>_…` | `acme_fleet_vehicle` | no; `app:migrations:owners` checks that one module creates each table |
| Translation keys | `<id_snake>.…` | `acme_fleet.nav.vehicles` | no |
| Container parameters | `<id_snake>.…` | `acme_fleet.max_vehicles` | no |
| Scheduled job names, listener keys | start with the id, or are the class name | `Acme\Fleet\Infrastructure\Listener\DropArchivedDriver` | no |

A rule marked "no" is not enforced yet, but the names belong to the module that the prefix names. A module that breaks one may collide with another module, and a later version of the module API may check it.

Give a module of your own an id that starts with your vendor, such as `acme-fleet` rather than `fleet`, so that two vendors' modules never collide.

### The module API version

| What | Where |
| --- | --- |
| The version that Foresi provides | `Foresi\Shared\Contract\ModuleApi::VERSION` (2.0.0), and `"provide": {"foresi/module-api": "2.0.0"}` of `foresi/shared` |
| The range a module works with | `"require": {"foresi/module-api": "^2.0"}` of the module |
| What blocks an upgrade | `composer why-not foresi/module-api 3.0` |

What each version brought:

| Version | Added |
| --- | --- |
| 1.1 | `Foresi\ExchangeRate\Contract\RateRules` (`foresi.exchange_rate.rules`), the legal rate of a country; `PublishedRates::$base`, the currency a source's quotes are priced in (leaving it out is deprecated and means CZK). A module that uses them requires `^1.1`. |
| 1.2 | Banking: the `StatementIntake` service, the one way bank movements enter, and the `StatementFormat` port for statement files, with their values (`BankStatement`, `StatementSequence`, `StatementFile`, `MovementProvenance`, `MovementChannel`, `MovementTrust`, `IntakeResult`) and the events `banking.statement.gap_detected` and `banking.statement.gap_closed`. A module that uses them requires `^1.2`. |
| 1.3 | Custom fields (`Foresi\Shared\Contract\Field`): a module adds fields to a contact, a deal, a task, a project or a product through the records' extension interfaces (`ContactExtension`, `DealExtension`, `TaskExtension`, `ProjectExtension`, `ProductExtension`), see [Add fields to a record](#add-fields-to-a-record). A module that uses them requires `^1.3`. |
| 1.4 | Custom fields on the records' dialogs and details, with `required` and `guests` enforced; `customFields`, the keys of the custom fields that changed, in `contacts.contact.updated`, `deals.deal.updated`, `catalog.product.updated` and `work.task.updated`; the event `work.project.updated` (`projectId`, `customFields`), recorded on every save of a project's name, client, description or custom fields. A module that reads them requires `^1.4`. |
| 1.5 | `Foresi\Shared\Contract\Operation\OperationObserver` (`foresi.shared.operation_observer`), told before and after every operation the runner runs; and Inbox's actions (`Foresi\Inbox\Contract\InboxAction`, `InboxActionInput`, `InboxActionCondition` with `foresi.inbox.action_condition`, `InboxPreFill` with `foresi.inbox.prefill`, and `PreFill`), see [Adding an action](inbox.md#adding-an-action). A module that uses them requires `^1.4`. |
| 1.6 | Work for other modules (`Foresi\Work\Contract`): the `TaskIntake` service, which makes a task in the caller's transaction with Work's own checks, event and audit; the `TaskDirectory` service with `TaskSummary`, the tasks the person sees by id in one query, those in the trash flagged; and `TaskSlots::HEADER_FACTS` (`work.task.header_facts`), the facts under a task's header. A module that uses them requires `^1.6`. |
| 1.7 | Custom fields over the REST API and MCP: `custom_fields` in the payloads of contacts, deals, tasks, projects and products and in their create and update operations, with the `api` flag honoured; `GET /api/v1/fields` and the MCP tool `foresi_list_fields`; the OpenAPI schemas of the fields, generated from the definitions. A module whose fields must reach the API requires `^1.7`. |
| 1.8 | Won deals in the cash flow: the certainty `FlowCertainty::Deal` (`deal`), `UndatedFlowSource` and `UndatedFlow` of Cash flow, amounts without a day; `BillingDocumentSource` (`foresi.deals.billing_document_source`), `BillingDocument` and `BillingDocumentType` of Sales, the invoices a planned invoice of a won deal is linked to; `PartySummary::$dueDays`; the events `deals.billing_entry.*`, `invoicing.recurring_invoice.ended` and `invoicing.recurring_invoice.deleted`. A module that uses them requires `^1.8`. |
| 2.0 | The modules got the names they keep (a major version: every name below moved, and a module written for 1.x must change). Contacts: the address book is the module `contacts`; `ContactTabs`, `ContactListActions`, `ContactCards`, `ContactColumns`, `ContactImportColumns`, `ContactCreation`, `NewContact`, `ContactsByEmail`, `ContactPersonWrites` and `ContactPersonRefused` moved from `Foresi\Party\Contract` to `Foresi\Contacts\Contract`, the events `contacts.contact.*` (were `party.contact.*`) to `Foresi\Contacts\Contract\Event`, and `ContactKind` of the summaries to `Foresi\Party\Contract\ContactKind`; the scope `contacts` is the contacts module's, and the firm has its own scope `firm`. Deals, received documents and document templates: `Foresi\Sales\` is `Foresi\Deals\`, `Foresi\Purchasing\` is `Foresi\ReceivedDocuments\` and `Foresi\Documents\` is `Foresi\DocumentTemplates\`, with their tags (`foresi.deals.*`, `foresi.received_documents.*`, `foresi.document_templates.*`), slots (`deals.*`, `received_documents.*`), list keys and events (`deals.*`, `received-documents.*`, `document-templates.*`). Timesheet: time, the project budget and the time settings of a project are the module `timesheet`, apart from Work. Invoicing publishes what other modules bill and pay through (`BilledWork`, `BilledWorkOrigin`, `BilledWorkRelease`, `InvoiceNumbers`, `PayableInvoices`, `BankPayments`, `BankPaymentRelease`, `PartyInvoicingDefaults`, `DocumentLanguage`, `PaymentMethod`, `InvoicingDefaults`), and Webhooks is a module of its own, outside the core. Work's new project dialog has the slot `ProjectSlots::NEW_FORM` (`work.project.new_form`). Work's `InvoiceNumbers` (`foresi.work.invoice_numbers`, before that `app.invoice_numbers`) left the contract: the billing of time is Timesheet's own. A module written for 1.x moves to the new names, requires `^2.0` and, for a module it used through the old names, that module's package, such as `foresi/contacts`. |

When the container is built, the kernel checks the range of every installed module. A module whose range the provided version does not satisfy, or that has no range, stops the boot with one message:

```
This Foresi provides the module API 2.0.0, which these modules are not written for: acme-fleet (acme/fleet) requires foresi/module-api ^3.0. Update them, or remove them with composer remove.
```

`bin/console app:modules:check` runs the same check and exits non-zero when it fails. With `--api=<version>` it checks against another version, such as the one an upgrade brings:

```
$ bin/console app:modules:check --api=3.0
 [ERROR] These modules are not written for the module API 3.0:
 * acme-fleet (acme/fleet) requires foresi/module-api ^2.0.
```

The deployment (`ops/deploy.sh`) runs `app:modules:check` after `composer install` and before the migrations, so an incompatible module never touches the database.

### Extension points

What the contract contains:

- every class, interface, trait, enum and attribute in a `Contract\` namespace of an installed module;
- the classes marked `@api`: `Foresi\Shared\Infrastructure\Module\ModuleBundle` with its protected hooks, and the `Foresi\Shared\Infrastructure\Kernel\ForesiKernel` trait;
- the names: the manifest, the `foresi.*` tags of the interfaces in `Contract\` namespaces, the names and payloads of contract events, the Ui slots, and the `@Ui` layout and Twig components that screens use.

Everything else is internal, even when PHP makes it `public`. See [What is public](#what-is-public).

#### Module bundle

| Extension point | What the core gives |
| --- | --- |
| `ModuleBundle` (`@api`) | Registered for every installed module. Loads `config/services.php`, maps the entities of `Domain/` and `Contract/`, registers `migrations/`, `templates/`, `translations/`, `assets/controllers/` and the loaded integrations. |
| `ModuleBundle::prepend()` | Configuration the module adds to other bundles, such as a Doctrine type or a Messenger middleware. |
| `ModuleBundle::messageRouting()` | The Messenger transport of each message class of the module, such as `'async'`. |
| `ModuleBundle::integrationLoaded()` | Whether an optional module is installed, for configuration that only an integration needs. |
| `AbstractBundle::configure()` and `loadExtension()` (Symfony) | The module's settings, as its bundle's configuration. Call `parent::loadExtension()`. |

#### Operations, tools and refusals (`Foresi\Shared\Contract`)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `Operation\Operation`, `Operation\Field` (attributes) | — | A REST endpoint below `/api/v1` and an MCP write tool from one declaration, with the scope check, idempotency and the OpenAPI entry. |
| `Operation\OperationHandler` | `foresi.shared.operation_handler` | Called with the validated input of its operation, over REST and MCP. |
| `Operation\OperationOutput` | — | The data of the answer and the sentence an MCP client shows. |
| `Operation\OperationObserver` (since 1.5) | `foresi.shared.operation_observer` | Told before and after every operation the runner runs, over REST and MCP; `before()` refuses with a `Refused` only, and `after()` may answer a longer summary. |
| `Operation\Omitted` | — | A field that a partial update did not send. |
| `Mcp\ReadTool` | `foresi.shared.mcp_read_tool` | A read-only MCP tool, listed to the tokens that hold its scope. |
| `Mcp\WriteTool` | `foresi.shared.mcp_write_tool` | An MCP tool that changes data, for a write that no operation describes. |
| `Mcp\StaffOnlyTool` | — | Hides a tool from a guest's token and refuses it to a guest. |
| `Mcp\ToolOutput`, `Mcp\ToolRefusal` | — | The answer of a tool, and its refusal, such as `ToolRefusal::of($refused)`. |
| `Mcp\ToolSchema` | — | The JSON Schema of the paging arguments, and readers of plain arguments. |
| `Refused`, `RefusalKind` | — | One refusal for every surface: the REST status and error, the MCP error, the message on a screen. |
| `ModuleApi` | — | The provided module API version. |

#### Custom fields (`Foresi\Shared\Contract\Field`, since 1.3)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `CustomField` (attribute), `FieldKind` | — | A property of a record extension as a custom field of the record: an input of its dialogs, a value of its detail (since 1.4), a column of its list, a filter, a sort, a search, its history, and a member of `custom_fields` over REST and MCP (since 1.7). |
| `RecordExtension` and the records' extension interfaces: `Foresi\Party\Contract\Field\ContactExtension`, `Foresi\Deals\Contract\Field\DealExtension`, `Foresi\Work\Contract\Field\TaskExtension`, `Foresi\Work\Contract\Field\ProjectExtension`, `Foresi\Catalog\Contract\Field\ProductExtension` | — | The entity that holds a module's fields of a record, with the record's id and the record kind in `RECORD`. Its changes are audited under the record. |
| `Fields` (service), `FieldDefinition`, `FieldExpression` | — | The fields of a record kind, their values read once a request and written in the caller's transaction, and the DQL that reads them. |
| `FieldSource` | `foresi.shared.field_source` | A source of fields of its own, such as fields defined at runtime. The core's source reads the record extensions. |

#### Events (`Foresi\Shared\Contract\Event`)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `DomainEvent` (attribute), `DomainEventPayload`, `EventSubject` | — | The event in the catalogue, the event log, the API (`/api/v1/events`) and the webhooks. |
| `EventRecorder` (service) | — | Records an event in the active transaction. |
| `DomainEventListener` | `foresi.shared.domain_event_listener` | Called after the commit, from the event relay, with the `RecordedEvent`; the relay flushes and records the delivery. |
| `RecordedEvent` | — | The stored event: id, name, version, time, correlation id and payload. |

#### People (`Foresi\Identity\Contract`)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `StaffCheck` (service) | — | Whether the person signed in is an owner or member of the organisation, not a guest. |
| `GuestHome` | `foresi.identity.guest_home` | The start page of a guest, for a module that shares things with guests. |

#### Screens (`Foresi\Ui\Contract`)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `Navigation\NavigationItemProvider`, `NavigationItem` | `foresi.ui.navigation_item` | An item in the sidebar, marked on its routes, with an optional count (`NavigationCountProvider`). |
| `Navigation\NavigationAreaProvider`, `NavigationArea`, `NavigationSection` | `foresi.ui.navigation_area` | An area of its own in the rail, with sections. `CoreNavigationAreas` names the core's. |
| `Slot\SlotProvider`, `SlotItem` | `foresi.ui.slot_provider` | Items in a slot of another module's screen. `Slots` lists the shell's slots. |
| `Dashboard\DashboardSectionProvider`, `DashboardSection` | `foresi.ui.dashboard_section` | A section of the start page, for a signed-in person with an active membership. |
| `Notice\ShellNoticeProvider`, `ShellNotice` | `foresi.ui.shell_notice` | A notice above every page of the shell. |
| `DataSource` | `foresi.ui.data_source` | The data source of a list: the attributes a person can show, the default ones and how they sort. A module may add attributes to another module's list. |
| `RelationSource`, `Relation`, `RelationTarget` | `foresi.ui.relation_source` | Relations of a list's rows, its own or another module's, such as the vehicles of a contact. |
| `@Ui/layout/app.html.twig` and the `@Ui` components | — | The shell: sidebar, header, breadcrumbs (`crumbs`) and the blocks `title` and `content`. |

#### Scheduled work (`Foresi\Instance\Contract`)

| Extension point | Tag | What the core gives |
| --- | --- | --- |
| `ScheduledJob` | `foresi.instance.scheduled_job` | Run once per period by the scheduler; a failed run is tried again on the next tick. |

#### Ports of business modules

Foresi's business modules have ports of their own in their `Contract\` namespaces. A module implements them in an integration, or directly when it requires the module. The core collects them by these tags:

| Module | Ports and their tags |
| --- | --- |
| Activities | `ActivityTarget` (`foresi.activities.target`) |
| Banking | `BankMessageParser` (`foresi.banking.message_parser`); `StatementFormat` (`foresi.banking.statement_format`, since 1.2) and the `StatementIntake` service (since 1.2), see [bank pairing](bank-pairing.md#statements-and-other-channels); `BankingCountry` (`foresi.banking.country`), how a country writes an account and its time zone |
| Calendar | `CalendarEntrySource` (`foresi.calendar.entry_source`) |
| Cash flow | `CashFlowSource` (`foresi.cash_flow.source`) and its extension `UndatedFlowSource` (since 1.8), `BalanceSource` (`foresi.cash_flow.balance_source`), `PairableDocumentSource` (`foresi.cash_flow.pairable_documents`) |
| Contacts | The address book, split off Party: `ContactColumns` (`foresi.contacts.contact_columns`, columns of the contact list that sort and filter, from a row the module keeps for each contact); the slots `ContactTabs::SLOT` (`party.contact_tabs`, tabs of a contact's page) and `ContactCards::SLOT` (`party.contact_cards`, cards of the contact's detail), `ContactListActions` (actions of the contact list), `ContactImportColumns` (`foresi.party.contact_import_columns`, columns of the contact import and export, with `ContactImportColumn`); the services `ContactPersonWrites`, `ContactCreation` (with `NewContact`) and `ContactsByEmail`; the events `contacts.contact.*` |
| Deals | `BillingDocumentSource` (`foresi.deals.billing_document_source`, since 1.8) |
| Document templates | `DocumentLinkTarget` (`foresi.document_templates.link_target`), `DocumentVariableSource` (`foresi.document_templates.variable_source`) |
| Exchange rates | `RateSource` (`foresi.exchange_rate.source`), `RateRules` (`foresi.exchange_rate.rules`) |
| Import | `ImportTarget` (`foresi.import.target`), `SourcePreset` (`foresi.import.preset`), `TargetPages` (`foresi.import.target_pages`) |
| Inbox | `RecordSuggester` (`foresi.inbox.record_suggester`, since 1.2); the actions on an item (since 1.5): `InboxAction` (attribute) on an `InboxActionInput`, `InboxActionCondition` (`foresi.inbox.action_condition`), `InboxPreFill` (`foresi.inbox.prefill`) and `PreFill`, see [Adding an action](inbox.md#adding-an-action) |
| Invoicing | `InvoicingCountry` (`foresi.invoicing.country`); for the module that owns bank movements, the services `BankPayments` (record and cancel a payment from a movement, with `BankPayment`, `BankPaymentPart` and `BankPaymentRefused`) and `PayableInvoices` (the invoices a movement may pay or refund, with `PayableInvoice`), and `BankPaymentRelease` (`foresi.invoicing.bank_payment_release`), told when a bank payment is cancelled; the service `PartyInvoicingDefaults`, the invoicing defaults of a contact (due period, currency, document language, payment method, reverse charge, reminders, hourly rate), with `InvoicingDefaults`, `DocumentLanguage` and `PaymentMethod` (moved from Party's domain) |
| Mail | `InboundPurpose` (`foresi.mail.inbound_purpose`) |
| Margin | `MarginCostSource` (`foresi.margin.cost_source`), `MarginRevenueSource` (`foresi.margin.revenue_source`) |
| Party | `BankAccountUsage` (`foresi.party.bank_account_usage`); what another module keeps for each contact: `ContactDueDays` (`foresi.party.contact_due_days`, the due period of `PartySummary`); `ContactPages`, the page of a contact in the address book, null without it (inject it nullable; Twig `contact_url()`, `contact_edit_url()`, `contact_new_url()`) |
| Received documents | `Reading\DocumentReader` (`foresi.received_documents.document_reader`) |
| Taxes and contributions | `ActualAmountSource`, `ForecastAmountSource`, `LevyPaymentSource`, `PlannedLevySource`, `Regime` (`foresi.taxes_and_contributions.*`) |
| Timesheet | `ProjectRateSource` (`foresi.timesheet.project_rate_source`); the service `TimeTotals`, the minutes logged on a project |
| VAT | `VatCountryRules`, `VatDocumentSource`, `VatPaymentSource`, `VatPlanSource`, `PlannedVatSource`, `VatScheduleSource` (`foresi.vat.*`) |
| Work | `ProjectTrashSources` (`foresi.work.project_trash_source`), `TaskPurge` (`foresi.work.task_purge`) and `TaskTimes`; the services `ProjectDirectory`, `TaskIntake` and `TaskDirectory` (since 1.6), and the slots of `ProjectSlots` and `TaskSlots` (since 1.6; `HEADER_ACTIONS`, `SETTINGS` and `TaskSlots::SECTIONS` added with the move of time to Timesheet) |

Each interface carries its tag as the constant `TAG` with `#[AutoconfigureTag]`, so an implementation never writes the tag. A tag of an interface outside a `Contract\` namespace, such as `foresi.idoklad.import_stage`, is internal.

### Commands

| Command | What it does |
| --- | --- |
| `bin/console app:modules` | Lists the installed modules, their dependencies and their integrations, loaded or not. |
| `bin/console app:modules:check [--api=<version>]` | Checks the installed modules against the provided module API, or against `<version>`. Exits non-zero on a mismatch. |
| `bin/console app:migrations:owners [--list]` | Checks that each table is created by the migrations of exactly one installed module. |

### Not in the contract yet

These are known gaps. They will change, so do not build on them as if they were promises:

- **`Money` and `Currency`** (`Foresi\Shared\Domain`) appear in some contracts of business modules, but are not part of the contract themselves. How Foresi models currencies is being settled, and these classes will change. Read them, but do not extend them or rely on their constructors.
- **Response schemas in the OpenAPI document.** An operation's `response` names a schema of the document, and a module cannot add schemas to it yet. Leave `response` out: the endpoint works, and the document shows its answer without a schema of its data. A later module API version will let modules add schemas.
- **Events wait for the relay.** An event that a module records is delivered to listeners and webhooks when the relay next runs, at most five minutes later, unless another change commits first. Foresi's own modules can hand events to the relay at once; that service is not in the contract yet.
- **Custom fields in import, export and templates.** Since module API 1.7 a record's custom fields are in its dialogs, detail, lists, filters, saved views, text search, history, update event, REST and MCP. The import and export and the document templates do not show them yet; a later 2.x version adds them, without a change to an extension.
- **No test kit.** The test support of acme/fleet (`ApiTestCase`, `SignedInBrowser`, `IdentityFixtures`) is part of this repository's tests, not a published package.
- **Required modules' internals.** A module that requires a business module and uses its `Domain\` or `Application\` classes is not protected by the contract. Use only the other module's `Contract\` namespace.

## Explanation

### What is public

The contract is what a module may rely on: the `Contract\` namespaces, the classes marked `@api` and the names listed in the [reference](#extension-points). Whether something is a promise is in its namespace or its docblock, so you can tell from the code.

Everything else is internal, even when PHP makes it `public`: the `Domain\`, `Application\` and `Infrastructure\` namespaces of every module, service ids, container parameters, database tables, and another module's routes and translation keys. An internal class may change in any release. Deptrac guards the boundary inside this repository, and the External module workflow proves it for a module outside it.

Symfony does it the other way round: all code is public unless it is marked `@internal`. Foresi's modules use each other's internals in thousands of places, and that rule would have turned all of them into promises at once. ADR 0044 has the comparison.

### Versions

The contract has a version of its own, the module API version, apart from the release version of Foresi:

- A new interface, a new method of a value, a new tag or a new slot raises its minor version (1.0 to 1.1).
- Removing any of them, or changing one incompatibly, raises its major version (1.x to 2.0, as the module renames did).

The release version follows CONTRIBUTING: before Foresi 1.0, a breaking change to anything, the REST API or an internal included, raises the release's minor version. A module that followed the release version would need a release of its own after each of those, although nothing it uses changed. With the module API version a module breaks only when the contract does, so `^2.0` keeps working across many releases of Foresi.

A module requires the range it was written for, as `"foresi/module-api": "^2.0"`. Composer refuses to install it into a Foresi that provides another major version, the kernel refuses to boot with it, and `app:modules:check --api=<version>` says so before an upgrade.

### Deprecation

Something leaves the contract in two steps:

1. It is deprecated in a minor version of the module API. Its replacement exists from that version on, and its docblock names the version that removes it: `@deprecated since module API 1.1, removed in 2.0, use X instead`. Code that runs it calls `trigger_deprecation()`, so the tests of a module show where it uses it. The Release notes of the pull request say what was deprecated and what to use instead.
2. It is removed no earlier than the next major version, and only after at least one release has shipped the deprecation.

Something new is never deprecated from its start.

Module API 1.0 still carries the old names from before the contract had namespaces. The interfaces that moved into `Foresi\Shared\Contract\` and `Foresi\Instance\Contract\` keep their old names, such as `Foresi\Shared\Infrastructure\Operation\OperationHandler` or `Foresi\Shared\Domain\Event\DomainEvent`, as deprecated aliases. A service that still carries an old `app.*` tag gets its new `foresi.*` tag added, with a deprecation that names the service. Module API 2.0 still carries both, so that a module moving from 1.x meets one change at a time; they go in module API 3.0. A new module uses only the new names.
