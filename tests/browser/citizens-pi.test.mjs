import assert from 'node:assert/strict';
import { once } from 'node:events';
import { createServer } from 'node:http';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import extension from '../../deploy/citizens-pi.mjs';

test('Pi extension emits metadata-only lifecycle events and cleans up heartbeat', async () => {
    const directory = await mkdtemp(path.join(tmpdir(), 'citizens-pi-'));
    const socket = path.join(directory, 'bridge.sock');
    const frames = [];
    const server = createServer(async (request, response) => {
        const chunks = [];
        for await (const chunk of request) chunks.push(chunk);
        const frame = JSON.parse(Buffer.concat(chunks).toString());
        frames.push(frame);
        response.end('{"accepted":true}');
        server.emit('frame', frame);
    });
    server.listen(socket);
    await once(server, 'listening');
    process.env.NEBULESS_CITIZENS_SOCKET = socket;
    const handlers = new Map();
    extension({ on: (type, handler) => handlers.set(type, handler) });
    const context = { sessionManager: { getSessionId: () => 'safe-session' }, isIdle: () => true };
    try {
        for (const [event, state] of [['session_start', 'idle'], ['agent_start', 'working'], ['ui_prompt_start', 'waiting'], ['ui_prompt_start', 'waiting'], ['ui_prompt_end', 'waiting'], ['ui_prompt_end', 'idle'], ['agent_settled', 'idle'], ['session_shutdown', 'stopped']]) {
            const received = once(server, 'frame', { signal: AbortSignal.timeout(2000) });
            const sent = handlers.get(event)({ prompt: 'PRIVATE_CANARY', messages: ['do not serialize'] }, context);
            const [frame] = await received;
            await sent;
            assert.deepEqual(frame, { session_id: 'safe-session', parent_id: null, tool: 'pi', state });
        }
        assert(!JSON.stringify(frames).includes('PRIVATE_CANARY'));
    } finally {
        await handlers.get('session_shutdown')({}, context);
        delete process.env.NEBULESS_CITIZENS_SOCKET;
        server.close();
        await once(server, 'close');
        await rm(directory, { recursive: true });
    }
});
