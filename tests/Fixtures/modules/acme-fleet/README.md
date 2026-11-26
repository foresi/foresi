# acme/fleet

An example module of another vendor than Foresi, built only on the module API
(ADR 0044): the `Contract` namespaces of Foresi's modules and the classes
marked `@api`. CI installs it into the application (the "External module"
workflow) and runs its tests, so a change to Foresi that breaks a module of
another vendor fails there. It is not installed in the repository otherwise.

- Package `acme/fleet`, namespace `Acme\Fleet\`, module id `acme-fleet`, bundle
  `Acme\Fleet\AcmeFleetBundle`.
- The vehicles of the firm (`Domain/Vehicle`, table `acme_fleet_vehicle`, a
  migration of its own).
- A screen for the staff at `/acme-fleet/vehicles`, with a navigation item.
- The operation `acme-fleet.vehicles.add`: `POST /api/v1/acme-fleet/vehicles`
  and the MCP tool `acme_fleet_add_vehicle`, scope `acme_fleet_vehicles:write`.
- The read tool `acme_fleet_list_vehicles`, scope `acme_fleet_vehicles:read`.
- The event `acme-fleet.vehicle.added`, and a listener of Party's
  `party.contact.archived` that takes the archived contact off as a driver.
- The setting `acme_fleet.max_vehicles` (bundle configuration, default 25).
- Custom fields of a contact (`Domain/ContactExtra`, table
  `acme_fleet_contact_extra`, module API 1.4): an industry (a select), a contract
  end (a date that sorts the address book), an account code (a searched text),
  fleet services (a multi-select) and a fleet manager (a person of the firm), each
  a filter of the address book and in the contact's form and detail.

Install it into a checkout of Foresi:

```bash
composer config repositories.acme-fleet path tests/Fixtures/modules/acme-fleet
composer require acme/fleet:@dev
bin/console app:modules:check
vendor/bin/phpunit -c tests/Fixtures/modules/acme-fleet/phpunit.xml.dist
```
