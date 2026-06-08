# Conformance harness

`run-conformance.sh` exercises **every** component end-to-end by running the
shipped `Samples/` through `configurator:run --dry-run` against a real Magento
install, and fails only when a component **crashes** (an uncaught `Throwable`,
caught by the Processor's per-source resilience guard). Logical
sample-vs-store mismatches (e.g. "Admin Role X does not exist" because the
sample references entities not present in the target store) are expected and do
**not** fail the harness.

This is the practical integration test for the module: unit-testing the
active-record components in isolation is brittle, whereas this proves the whole
pipeline wires and runs (DI, `ComponentContext`/`ComponentResult`, dry-run
guards, exit code) on a target Magento version.

## Running it

From the Magento base directory, with the module installed:

```bash
MAGENTO_BIN="bin/magento" \
MODULE_DIR="vendor/magebit/module-magento2-configurator" \
ENV="local" \
bash vendor/magebit/module-magento2-configurator/Test/Conformance/run-conformance.sh
```

On a dockerised (magebit-docker) setup, point `MAGENTO_BIN` at the container
wrapper, e.g. `MAGENTO_BIN="d/magento"`.

The harness backs up the store's real `app/etc/master.yaml` and
`app/etc/configurator/`, runs the samples, and restores them on exit (it does
not modify data — the run is `--dry-run`). The target DB is otherwise
untouched, but running against a disposable/dev store is still recommended.

## Verified

Run on **Magento 2.4.7-p3** (2026-06-08): all 27 components execute with **no
crashes** (harness PASS); no modernization-introduced `TypeError`s; exit code is
non-zero when component errors occur.

## Notes

- **Configurable products** require their associated simple products to already
  exist. When none resolve, the configurable row is skipped (and logged) rather
  than handed as an empty set to FastSimpleImport's validation adapter — this
  fixes the earlier "Undefined array key 0" crash in `ArrayAdapter`.
