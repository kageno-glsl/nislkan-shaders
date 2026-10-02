import http from '@/api/http';

export default async (uuid: string, startup: string): Promise<{ startupCommand: string; rawStartupCommand: string }> => {
    const { data } = await http.put(`/api/client/servers/${uuid}/startup/command`, { startup });

    return {
        startupCommand: data.startup_command,
        rawStartupCommand: data.raw_startup_command,
    };
};