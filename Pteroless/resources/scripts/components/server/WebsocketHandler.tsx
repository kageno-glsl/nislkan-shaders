import React, { useEffect } from 'react';
import { ServerContext, ServerStatus } from '@/state/server';
import http from '@/api/http';
import { SocketEvent, SocketRequest } from '@/components/server/events';

// Local runner compatibility layer. The original Pterodactyl UI expects a Wings websocket;
// local mode keeps the exact same UI contract but feeds it from the panel HTTP API instead.
class LocalSocket {
    constructor(private uuid: string, private setServerStatus: (status: ServerStatus) => void) {}
    private listeners = new Map<string, Set<(value: any) => void>>();
    private lastLog = '';
    private lastStatus: string | null = null;
    private closed = false;

    addListener(event: string, callback: (value: any) => void) {
        if (!this.listeners.has(event)) this.listeners.set(event, new Set());
        this.listeners.get(event)!.add(callback);
    }

    removeListener(event: string, callback: (value: any) => void) {
        this.listeners.get(event)?.delete(callback);
    }

    private emit(event: string, value: any) {
        this.listeners.get(event)?.forEach((callback) => callback(value));
    }

    private emitStats(attributes: any) {
        const resources = attributes.resources ?? {};
        this.emit(SocketEvent.STATS, JSON.stringify({
            memory_bytes: resources.memory_bytes ?? 0,
            cpu_absolute: resources.cpu_absolute ?? 0,
            disk_bytes: resources.disk_bytes ?? 0,
            network: {
                rx_bytes: resources.network_rx_bytes ?? 0,
                tx_bytes: resources.network_tx_bytes ?? 0,
            },
            uptime: resources.uptime ?? 0,
        }));
    }

    async send(event: string, value?: any) {
        const uuid = this.uuid;
        if (event === SocketRequest.SEND_LOGS) {
            const { data } = await http.get(`/api/client/servers/${uuid}/logs`);
            this.lastLog = data.logs || '';
            this.emit(SocketEvent.CONSOLE_OUTPUT, this.lastLog);
        } else if (event === SocketRequest.SEND_STATS) {
            const { data } = await http.get(`/api/client/servers/${uuid}/resources`);
            this.emitStats(data.attributes);
        } else if (event === 'send command') {
            await http.post(`/api/client/servers/${uuid}/command`, { command: value });
        } else if (event === SocketRequest.SET_STATE) {
            await http.post(`/api/client/servers/${uuid}/power`, { signal: value });
            await this.poll(uuid);
        }
    }

    async poll(uuid: string): Promise<number> {
        if (this.closed) return 0;
        try {
            const [{ data: resources }, { data: logs }] = await Promise.all([
                http.get(`/api/client/servers/${uuid}/resources`),
                http.get(`/api/client/servers/${uuid}/logs`),
            ]);
            if (this.closed) return 0;

            const status = resources.attributes.current_state as ServerStatus;
            if (status !== this.lastStatus) {
                this.lastStatus = status;
                this.setServerStatus(status);
                this.emit(SocketEvent.STATUS, status);
            }
            this.emitStats(resources.attributes);
            const next = logs.logs || '';
            if (next.length > this.lastLog.length && next.startsWith(this.lastLog)) {
                this.emit(SocketEvent.CONSOLE_OUTPUT, next.slice(this.lastLog.length));
            } else if (next !== this.lastLog) {
                this.emit(SocketEvent.CONSOLE_OUTPUT, next);
            }
            this.lastLog = next;
            return 5000;
        } catch (error) {
            console.error(error);
            return 5000;
        }
    }

    removeAllListeners() { this.listeners.clear(); }
    close() { this.closed = true; }
}

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data?.uuid);
    const { setInstance, setConnectionState } = ServerContext.useStoreActions((actions) => actions.socket);
    const setServerStatus = ServerContext.useStoreActions((actions) => actions.status.setServerStatus);

    useEffect(() => {
        if (!uuid) return;
        const socket = new LocalSocket(uuid, setServerStatus);
        setInstance(socket as any);
        setConnectionState(true);

        let active = true;
        let timer: number | undefined;
        const poll = async () => {
            const interval = await socket.poll(uuid);
            if (active && interval > 0) timer = window.setTimeout(poll, interval);
        };
        void poll();
        return () => {
            active = false;
            if (timer !== undefined) window.clearTimeout(timer);
            socket.close();
            setConnectionState(false);
            setInstance(null);
        };
    }, [uuid]);

    return null;
};
