---
type: infrastructure
title: Synchronize reboot concurrency verification with explicit start signals
---
Replace a polling file barrier with ready/start pipe messages in the isolated reboot race fixture. Both workers must report ready before execution begins; the bounded process timeout remains a failure rather than silently starting an unsynchronized race.
