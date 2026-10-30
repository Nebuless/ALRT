---
type: fixed
title: Prime replay verification contact before starting its consumer
---
Publish the latest fixture sample before launching a one-contact test worker. This prevents a retained sample and a concurrent replacement from creating an unintended canceled duplicate contact that leaves replay waiting for another authorization generation. Production worker behavior is unchanged.
