# SQL Dump vs Migration Scripts

## Recommendation

Use both.

- Use a SQL dump as the **baseline** for new client installs.
- Use migration scripts for **upgrades** to clients that already have live data.

This is the safest and most practical setup for this CRM.

## Short Answer

Yes, using a SQL dump is viable.

But it should not fully replace migrations if clients already have production databases.

## Best Use for a SQL Dump

A SQL dump works best when you want:

- fast setup for a brand-new client
- one clean baseline schema
- standard seed/reference data
- a known-good starting database
- backup and restore support

This is ideal for:

- new installations
- staging clones
- demo environments
- disaster recovery

## Best Use for Migrations

Migrations work best when you want:

- safe schema changes over time
- upgrades without overwriting live data
- controlled rollout of new features
- a clear record of what changed and when

This is important for:

- existing clients
- production upgrades
- partial feature rollouts
- long-term maintenance

## Why a Dump Alone Becomes Risky

If you rely only on a SQL dump for everything, problems appear quickly:

- existing client data can be overwritten or damaged
- small schema changes become hard to apply safely
- client databases drift and become hard to compare
- debugging “which version is this client on?” becomes messy
- rolling forward and backward is harder

In short: a dump is great for **starting**, but weak for **evolving**.

## Recommended Model for This CRM

Use a hybrid model:

### 1. Golden baseline dump

Keep one clean `baseline.sql` file that represents:

- a fresh install
- the full schema
- required base seed data
- no client-specific data

Use this for all new clients.

### 2. Post-baseline migrations

After the baseline point, every schema or structural change should go into migration scripts.

Use those migrations for:

- production upgrades
- patching older client installs
- new features added after the baseline

### 3. Periodic refresh of the baseline

From time to time:

- build a clean database
- apply all migrations
- verify it
- export a new baseline dump

This keeps new installs fast without losing upgrade discipline.

## Practical Workflow

For a new client:

1. Import `baseline.sql`
2. Apply any newer migrations created after that baseline
3. Configure client-specific settings
4. Run smoke tests

For an existing client:

1. Back up the database
2. Apply migrations only
3. Verify new tables, settings, and jobs
4. Run smoke tests

Do **not** re-import the full baseline dump over a live client database.

## Suggested Folder Structure

- `database/baseline/baseline.sql`
- `database/migrations/`
- `docs/sql-dump-vs-migrations-guide.md`

Optional:

- `database/baseline/seed-reference.sql`
- `database/baseline/README.md`

## What Should Go in the Baseline Dump

Include:

- schema
- indexes
- foreign keys
- required default rows
- safe reference data

Do not include:

- client contacts
- deals
- communications
- tasks
- workflow history
- AI outcomes
- logs
- client-specific API keys

## Operational Rule

Use this simple rule:

- **New install** -> use baseline dump first
- **Existing install** -> use migrations only

## Final Recommendation

For this CRM, the best setup is:

- SQL dump for **new client setup**
- migrations for **ongoing upgrades**

That gives you:

- faster installs
- safer production updates
- easier support
- cleaner version control

## Bottom Line

Yes, a SQL dump is viable.

The right way to use it is as a **baseline**, not as a full replacement for migrations.
