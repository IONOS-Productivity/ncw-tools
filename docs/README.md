# Event Flows

NCW Tools reacts to Nextcloud lifecycle events by registering listeners that enqueue background jobs. Each document below walks through one end-to-end flow — the triggering event, the listener's synchronous work, the job's asynchronous behaviour, and the configuration it relies on.

- [Post-setup welcome mail](events/post-setup.md) — sends the initial welcome email to the admin user once the system is reachable after installation.
- [User stats reporting](events/user-stats.md) — reports the current total user count to the PSS Stats API after every user create or delete.
