import useSWR, { ConfigInterface } from 'swr';
import http, { FractalResponseList } from '@/api/http';
import { rawDataToServerEggVariable } from '@/api/transformers';
import { ServerEggVariable } from '@/api/server/types';

interface Response {
    invocation: string;
    rawStartupCommand?: string;
    variables: ServerEggVariable[];
    dockerImages: Record<string, string>;
    localRunner?: boolean;
    runtime?: string;
}

export default (uuid: string, initialData?: Response | null, config?: ConfigInterface<Response>) =>
    useSWR(
        [uuid, '/startup'],
        async (): Promise<Response> => {
            const { data } = await http.get(`/api/client/servers/${uuid}/startup`);

            const variables = ((data as FractalResponseList).data || []).map(rawDataToServerEggVariable);

            return {
                variables,
                invocation: data.meta.startup_command,
                rawStartupCommand: data.meta.raw_startup_command,
                dockerImages: data.meta.docker_images || {},
                localRunner: data.meta.local_runner || false,
                runtime: data.meta.runtime || '',
            };
        },
        { initialData: initialData || undefined, errorRetryCount: 3, ...(config || {}) }
    );
