# User stats reporting

On every user create or delete, this app reports the current total user count to the PSS Stats API. A listener logs the change and enqueues a single deduplicated background job; on the next cron tick the job reads the total user count, validates the PSS credentials, and POSTs a `StatsUpdateRequest` to the PSS Stats API. The job is queued — it does not retry on failure; the next user event re-enqueues it.

## Trigger events

- `OCP\User\Events\UserCreatedEvent`
- `OCP\User\Events\UserDeletedEvent`

## Configuration

All keys live in `config/config.php`. The job aborts (with a logged error) if any value is missing or empty.

| Key | Type | Purpose |
| --- | --- | --- |
| `ncw_tools.pss.brand` | string | PSS brand identifier; first path segment of the stats endpoint. |
| `ncw_tools.pss.ext_ref` | string | External tenant reference; second path segment of the stats endpoint. |
| `ncw_tools.pss.base_url` | string | Base URL of the PSS API. |
| `ncw_tools.pss.username` | string | HTTP Basic auth username. |
| `ncw_tools.pss.password` | string | HTTP Basic auth password. |

## Flow

```mermaid
sequenceDiagram
    autonumber

    participant OCC as occ user:add / user:delete
    participant ED as EventDispatcher
    participant UEL as UserEventListener
    participant LOG as LoggerInterface
    participant JL as IJobList
    participant CRON as Nextcloud Cron
    participant USJ as UserStatsJob
    participant UM as IUserManager
    participant CFG as PssConfigService
    participant API as ApiStatsClientService
    participant PSS as PSS Stats API

    Note over OCC,JL: Synchronous phase — runs during the OCC command

    OCC->>ED: dispatch(UserCreatedEvent | UserDeletedEvent)
    ED->>UEL: handle(event)

    alt UserCreatedEvent
        UEL->>LOG: info("User added", {uid})
    else UserDeletedEvent
        UEL->>LOG: info("User deleted", {uid})
    end

    UEL->>JL: has(UserStatsJob::class, null)

    alt already queued
        JL-->>UEL: true (skip)
    else not queued
        JL-->>UEL: false
        UEL->>JL: add(UserStatsJob::class)
    end

    Note over CRON,PSS: Asynchronous phase — next cron cycle

    CRON->>USJ: run()
    USJ->>UM: countUsersTotal()
    UM-->>USJ: int | false

    alt count === false
        USJ->>LOG: warning("could not retrieve user count")
        USJ-->>CRON: return
    else count is int
        USJ->>CFG: getBrand / getExtRef / getBaseUrl / getUsername / getPassword

        alt any value empty
            USJ->>LOG: error("missing required PSS configuration, aborting")
            USJ-->>CRON: return
        else all values present
            USJ->>USJ: build timestamp (UTC ISO-8601 ms)<br/>build StatsUpdateRequest with UserStats(existingUsers)
            USJ->>LOG: info("User stats payload", {payload})
            USJ->>API: newClient()
            USJ->>API: newStatsAPIApi(client, baseUrl, username, password)
            USJ->>PSS: updateStats(brand, extRef, request)

            alt Throwable
                PSS-->>USJ: exception
                USJ->>LOG: error("failed to push stats to PSS", {exception})
            else success
                PSS-->>USJ: 2xx
            end
        end
    end
```

## Failure modes

The job is a `QueuedJob` — there is no automatic retry. Each of the following ends the run without reporting:

- `IUserManager::countUsersTotal()` returns `false` (logged at warning).
- Any of the five `ncw_tools.pss.*` values is missing or empty (logged at error).
- The PSS API call throws (logged at error with the exception).

The next `UserCreatedEvent` or `UserDeletedEvent` re-enqueues the job, so the count converges once the underlying problem is resolved.
