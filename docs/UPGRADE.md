# Upgrading from v1 (CtiDigital) to v2 (Magebit)

v2 is a modernised, self-owned line of the configurator. The **source-file
contract is deliberately frozen** — your existing `master.yaml` and component
sources keep working — but the package, module name, PHP/Magento support and the
internal component contract changed, and reconciliation behaviour is now uniform
across all components.

Read the two behaviour sections below carefully; the rest is mechanical.

## At a glance

| | v1 (CtiDigital) | v2 (Magebit) |
|---|---|---|
| Composer package | `ctidigital/magento2-configurator` | `magebit/module-magento2-configurator` |
| Module | `CtiDigital_Configurator` | `Magebit_Configurator` |
| PHP / Magento | PHP 7.x / older 2.x | **PHP 8.1–8.5 / Magento 2.4.7–2.4.9** |
| Component contract | `execute($data, $mode): void` | `execute(ComponentContext): ComponentResult` |
| Modes | a few components honored create/maintain | **all** components honor create/maintain |
| Exit code | always `0` | **non-zero on error** (CI-friendly) |
| `--dry-run` | — | yes |

## 1. Swap the package and module

```bash
# Remove the old package and disable the old module
composer remove ctidigital/magento2-configurator
bin/magento module:disable CtiDigital_Configurator

# Install v2 (from your configured repository, e.g. Packeton / composer.magebit.com)
composer require magebit/module-magento2-configurator:^2.0
bin/magento module:enable Magebit_Configurator
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
```

There is no data migration: the configurator stores almost nothing of its own
(only a small `version_*` bookkeeping row set under the new keys).

## 2. Your config files keep working

`app/etc/master.yaml` and every source file under `app/etc/configurator/` use the
**same format** as v1. Source paths are still resolved relative to the Magento
base dir. No changes are required to run.

New, **optional** additions you may adopt later:
- A component-level `version:` in `master.yaml` (run a whole component once per
  version bump — handy for `sql`, `media`, bulk imports).
- New components: `inventory_sources` (MSI), `hyva_pages` / `hyva_blocks`
  (optional, need `Hyva_CmsMagento`), and new fields (`widgets.page_groups`,
  `products.msi_sources`). See [`docs/schema/`](schema/README.md).

## 3. ⚠ Behaviour change: create mode now protects existing entities

In v1 many components ignored mode and **overwrote existing data on every run**.
In v2 **every** component honors mode uniformly:

| state | `create` (default) | `maintain` |
|---|---|---|
| not exists | create | create |
| exists, unchanged | skip | skip |
| exists, changed, `version` bumped | update | update |
| exists, changed, no bump | **skip (protected)** | update |

**Action:** review the per-environment `mode` in your `master.yaml`. If you relied
on a component overwriting existing entities on every run (e.g. widgets,
attributes, websites, rewrites, product links, catalog price rules, admin roles,
review ratings), set `mode: maintain` for it in the environments where you want
that. Bulk importers (`products`, `customers`, `taxrates`) skip rows whose key
already exists in create mode and do not diff individual attributes (so
`maintain` re-imports every row). Versioning forces an update even in create mode.

## 4. CLI / CI changes

- **Exit codes are now meaningful** — `configurator:run` returns non-zero if any
  component records an error, and prints `created N, updated N, skipped N, errors N`.
  Update CI/deploy scripts that assumed exit `0`.
- **`--dry-run`** previews changes without writing (raw SQL, imports and saves are
  all guarded).
- `--component=<alias>` is repeatable; `enabled: 0` in `master.yaml` skips a
  component in full runs.
- A single failing component no longer aborts the whole run.

## 5. Custom components

If you wrote your own components, three things changed: the namespace, the
`execute()` signature, and registration.

**The contract** (`Magebit\Configurator\Api\ComponentInterface`):

```php
// v1
public function execute($data = null, string $mode = Processor::MODE_MAINTAIN): void;

// v2
public function execute(ComponentContext $context): ComponentResult;
```

`ComponentContext` exposes everything the old args/processor did:
`getData()`, `getMode()` (a `ComponentMode` enum), `isDryRun()`,
`getEnvironment()`, `getVersion()`, and `getSourcePath()` (for file-based
components — `FileComponentInterface` was removed). Return a `ComponentResult`,
recording `recordCreated()` / `recordUpdated()` / `recordSkipped()` / `addError()`.

```php
use Magebit\Configurator\Api\ComponentInterface;
use Magebit\Configurator\Model\ComponentContext;
use Magebit\Configurator\Model\ComponentResult;
use Magebit\Configurator\Model\Reconciliation\ReconciliationGate;
use Magebit\Configurator\Model\Reconciliation\ReconciliationRequest;

class MyComponent implements ComponentInterface
{
    public function __construct(private readonly ReconciliationGate $gate) {}

    public function execute(ComponentContext $context): ComponentResult
    {
        $result = new ComponentResult();
        foreach ($context->getData() as $key => $row) {
            $exists = /* look it up */;
            $request = new ReconciliationRequest('my_alias', (string) $key, $context->getMode(), $exists);
            if ($this->gate->decide($request)->isSkip()) { $result->recordSkipped(); continue; }
            if ($context->isDryRun()) { /* log */ $result->recordCreated(); continue; }
            // ...save...
            $this->gate->commitVersion($request, $context->isDryRun());
            $result->recordCreated();
        }
        return $result;
    }

    public function getAlias(): string { return 'my_alias'; }
    public function getDescription(): string { return 'My component'; }
}
```

Reuse the shared `ReconciliationGate` for mode/version consistency (recommended
but optional). Register the component under the new interface in `di.xml`:

```xml
<type name="Magebit\Configurator\Api\ComponentListInterface">
    <arguments>
        <argument name="components" xsi:type="array">
            <item name="my_alias" xsi:type="object">Vendor\Module\Component\MyComponent</item>
        </argument>
    </arguments>
</type>
```

Internals also moved off deprecated active-record `save()` to repositories /
resource models — only relevant if you extended component internals directly.

## 6. Verify

```bash
bin/magento configurator:list                        # all components resolve
bin/magento configurator:run --env="<env>" --dry-run # preview, no writes
```

A clean dry-run with the expected create/update/skip counts means the upgrade is
good. See the main [README](../README.md) and [`docs/schema/`](schema/README.md)
for the full reference.
