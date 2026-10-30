---
type: fixed
title: Release both reboot race workers through a kernel lock barrier
---
Replace buffered process-input start messages with a shared file-lock barrier held exclusively by the parent until both workers report ready. Unlocking releases both workers independently of sequential output collection, preserving actual concurrent SQLite action verification without polling sleeps.
