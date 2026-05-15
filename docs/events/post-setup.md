# Post-setup welcome mail

After Nextcloud installation completes, this app sends an initial welcome email to the admin user. A listener seeds an app-config status flag and schedules a time-sensitive background job; the job retries on a configurable interval until the system URL is reachable and the admin user exists, then sends the welcome mail via Nextcloud's `NewUserMailHelper` (with a fresh password-reset token) and marks itself done.

## Trigger event

`OCP\Install\Events\InstallationCompletedEvent`

## Configuration

| Key | Where | Type | Default | Purpose |
| --- | --- | --- | --- | --- |
| `overwrite.cli.url` | `config/config.php` | string | _required_ | Base URL the job probes via `…/status.php` to confirm the instance is reachable. |
| `ncw_tools.post_setup_job.retry_interval` | `config/config.php` | int (seconds) | `2` | Interval between job retries while waiting for the system to become ready. |
| `post_install` | App config (`ncw_tools`) | string | _set by listener_ | Observable status: `INIT` (work pending), `DONE` (welcome mail sent), `UNKNOWN` (listener has not run). |

## Flow

```mermaid
sequenceDiagram
    autonumber

    participant INS as Nextcloud Installer
    participant ED as EventDispatcher
    participant ICEL as InstallationCompletedEventListener
    participant CFG as IAppConfig
    participant LOG as LoggerInterface
    participant JL as IJobList
    participant CRON as Nextcloud Cron
    participant PSJ as PostSetupJob
    participant HTTP as IClientService
    participant UM as IUserManager
    participant MAIL as WelcomeMailHelper

    Note over INS,JL: Synchronous phase — runs during installation

    INS->>ED: dispatch(InstallationCompletedEvent)
    ED->>ICEL: handle(event)
    ICEL->>CFG: setValueString(post_install, "INIT")

    alt admin username missing
        ICEL->>LOG: warning("No admin user provided")
    else admin username present
        ICEL->>LOG: info("Scheduling welcome email job")
        ICEL->>JL: add(PostSetupJob::class, adminUserId)
    end

    Note over CRON,MAIL: Asynchronous phase — TIME_SENSITIVE,<br/>retries every ncw_tools.post_setup_job.retry_interval seconds (default 2)

    CRON->>PSJ: run(adminUserId)
    PSJ->>CFG: getValueString(post_install)

    alt status == "DONE"
        PSJ->>JL: remove(this)
        PSJ-->>CRON: return (already completed)
    else status == "UNKNOWN"
        PSJ->>LOG: warning("Job status unknown, waiting")
        PSJ-->>CRON: return (retry)
    else status == "INIT"
        PSJ->>HTTP: GET {overwrite.cli.url}/status.php

        alt URL empty
            PSJ->>LOG: warning("System URL not configured")
            PSJ-->>CRON: return (retry)
        else HTTP not 2xx
            PSJ->>LOG: info("System not ready, will retry")
            PSJ-->>CRON: return (retry)
        else HTTP 2xx
            PSJ->>UM: userExists(adminUserId)

            alt user not found
                PSJ->>LOG: warning("Admin user not found")
                PSJ-->>CRON: return (retry)
            else user exists
                PSJ->>UM: get(adminUserId)
                PSJ->>MAIL: sendWelcomeMail(user, generateResetToken=true)

                alt exception thrown
                    PSJ->>LOG: error("Failed to send welcome email, will retry")
                    PSJ-->>CRON: return (retry)
                else success
                    PSJ->>CFG: setValueString(post_install, "DONE")
                    PSJ->>JL: remove(this)
                    PSJ->>LOG: info("Post-installation job completed")
                end
            end
        end
    end
```

## Failure modes

The job is `TIME_SENSITIVE` and re-runs on every cron tick until it succeeds. Any of the following keep it in the queue:

- `overwrite.cli.url` is unset.
- `…/status.php` returns a non-2xx response, or the request throws.
- The admin user does not exist (or cannot be retrieved).
- `WelcomeMailHelper::sendWelcomeMail` throws.

On success, the job sets `post_install = "DONE"` and removes itself from the job list. A subsequent install event would re-seed the status to `INIT` and re-schedule the job.
