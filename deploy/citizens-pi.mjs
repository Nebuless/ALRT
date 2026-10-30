import { request } from 'node:http';
import { randomUUID } from 'node:crypto';

// This extension emits only lifecycle metadata; messages and tool arguments never leave Pi.
export default function citizens(pi) {
    const socketPath = process.env.NEBULESS_CITIZENS_SOCKET;
    if (!socketPath) return;
    let session = randomUUID();
    let state = 'unknown';
    let blocked = 0;
    let heartbeat;
    let pending = false;
    let dirty = false;
    const emit = () => {
        if (pending) { dirty = true; return; }
        pending = true;
        const body = JSON.stringify({ session_id: session, parent_id: null, tool: 'pi', state });
        return new Promise(resolve => {
            const req = request({ socketPath, path: '/events', method: 'POST', headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) }, timeout: 1000 }, response => response.resume());
            req.on('error', () => {});
            req.on('close', () => {
                pending = false;
                if (dirty) { dirty = false; emit(); }
                resolve();
            });
            req.on('timeout', () => req.destroy());
            req.end(body);
        });
    };
    pi.on('session_start', (_event, ctx) => {
        session = ctx.sessionManager.getSessionId();
        state = typeof ctx.isIdle === 'function' ? (ctx.isIdle() ? 'idle' : 'working') : 'unknown';
        blocked = 0;
        clearInterval(heartbeat);
        heartbeat = setInterval(emit, 2000);
        heartbeat.unref();
        return emit();
    });
    pi.on('agent_start', () => { state = 'working'; return emit(); });
    pi.on('tool_execution_start', () => { state = 'working'; return emit(); });
    pi.on('agent_settled', () => { state = 'idle'; return emit(); });
    pi.on('ui_prompt_start', () => { blocked++; state = 'waiting'; return emit(); });
    pi.on('ui_prompt_end', (_event, ctx) => {
        blocked = Math.max(0, blocked - 1);
        state = blocked ? 'waiting' : typeof ctx.isIdle === 'function' ? (ctx.isIdle() ? 'idle' : 'working') : 'unknown';
        return emit();
    });
    pi.on('session_shutdown', () => {
        state = 'stopped';
        clearInterval(heartbeat);
        return emit();
    });
}
