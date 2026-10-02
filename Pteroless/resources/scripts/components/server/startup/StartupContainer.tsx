import React, { useCallback, useEffect, useState } from 'react';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import tw from 'twin.macro';
import VariableBox from '@/components/server/startup/VariableBox';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import getServerStartup from '@/api/swr/getServerStartup';
import Spinner from '@/components/elements/Spinner';
import { ServerError } from '@/components/elements/ScreenBlock';
import { httpErrorToHuman } from '@/api/http';
import { ServerContext } from '@/state/server';
import { useDeepCompareEffect } from '@/plugins/useDeepCompareEffect';
import Select from '@/components/elements/Select';
import isEqual from 'react-fast-compare';
import Input from '@/components/elements/Input';
import { Textarea } from '@/components/elements/Input';
import setSelectedDockerImage from '@/api/server/setSelectedDockerImage';
import InputSpinner from '@/components/elements/InputSpinner';
import useFlash from '@/plugins/useFlash';
import { usePermissions } from '@/plugins/usePermissions';
import Button from '@/components/elements/Button';
import FlashMessageRender from '@/components/FlashMessageRender';
import updateStartupCommand from '@/api/server/updateStartupCommand';

const StartupContainer = () => {
    const [loading, setLoading] = useState(false);
    const [savingCommand, setSavingCommand] = useState(false);
    const [startupCommand, setStartupCommand] = useState('');
    const [canUpdateStartup] = usePermissions(['startup.update']);
    const { clearFlashes, clearAndAddHttpError } = useFlash();

    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const variables = ServerContext.useStoreState(
        ({ server }) => ({
            variables: server.data!.variables,
            invocation: server.data!.invocation,
            dockerImage: server.data!.dockerImage,
        }),
        isEqual
    );

    const { data, error, isValidating, mutate } = getServerStartup(uuid, {
        ...variables,
        dockerImages: { [variables.dockerImage]: variables.dockerImage },
    });

    const setServerFromState = ServerContext.useStoreActions((actions) => actions.server.setServerFromState);
    const isCustomImage =
        data &&
        !Object.values(data.dockerImages)
            .map((v) => v.toLowerCase())
            .includes(variables.dockerImage.toLowerCase());

    useEffect(() => {
        // Since we're passing in initial data this will not trigger on mount automatically. We
        // want to always fetch fresh information from the API however when we're loading the startup
        // information.
        mutate();
    }, []);

    useDeepCompareEffect(() => {
        if (!data) return;

        setServerFromState((s) => ({
            ...s,
            invocation: data.invocation,
            variables: data.variables,
        }));
    }, [data]);

    useEffect(() => {
        setStartupCommand(data?.rawStartupCommand || '');
    }, [data?.rawStartupCommand]);

    const updateSelectedDockerImage = useCallback(
        (v: React.ChangeEvent<HTMLSelectElement>) => {
            setLoading(true);
            clearFlashes('startup:image');

            const image = v.currentTarget.value;
            setSelectedDockerImage(uuid, image)
                .then(() => setServerFromState((s) => ({ ...s, dockerImage: image })))
                .catch((error) => {
                    console.error(error);
                    clearAndAddHttpError({ key: 'startup:image', error });
                })
                .then(() => setLoading(false));
        },
        [uuid]
    );

    const saveStartupCommand = () => {
        setSavingCommand(true);
        clearFlashes('startup:command');
        updateStartupCommand(uuid, startupCommand)
            .then(({ startupCommand: invocation, rawStartupCommand }) => {
                setServerFromState((s) => ({ ...s, invocation }));
                mutate((current) => current && ({ ...current, invocation, rawStartupCommand }), false);
            })
            .catch((error) => clearAndAddHttpError({ key: 'startup:command', error }))
            .then(() => setSavingCommand(false));
    };

    return !data ? (
        !error || (error && isValidating) ? (
            <Spinner centered size={Spinner.Size.LARGE} />
        ) : (
            <ServerError title={'Oops!'} message={httpErrorToHuman(error)} onRetry={() => mutate()} />
        )
    ) : (
        <ServerContentBlock title={'Startup Settings'} showFlashKey={'startup:image'}>
            {data.localRunner ? (
                <TitledGreyBox title={`${data.runtime} Startup Command`}>
                    <FlashMessageRender byKey={'startup:command'} className={'mb-4'} />
                    <Textarea
                        rows={3}
                        value={startupCommand}
                        onChange={(event) => setStartupCommand(event.currentTarget.value)}
                        readOnly={!canUpdateStartup}
                        aria-label={'Startup command'}
                    />
                    {canUpdateStartup && (
                        <div css={tw`flex justify-end mt-4`}>
                            <Button onClick={saveStartupCommand} isLoading={savingCommand} disabled={savingCommand}>
                                Save Startup Command
                            </Button>
                        </div>
                    )}
                    <p css={tw`text-xs text-neutral-300 mt-2`}>
                        Changes apply the next time the server starts. Commands run as the panel's operating-system user.
                    </p>
                </TitledGreyBox>
            ) : (
                <div css={tw`md:flex`}>
                    <TitledGreyBox title={'Startup Command'} css={tw`flex-1`}>
                        <div css={tw`px-1 py-2`}>
                            <p css={tw`font-mono bg-neutral-900 rounded py-2 px-4`}>{data.invocation}</p>
                        </div>
                    </TitledGreyBox>
                    <TitledGreyBox title={'Docker Image'} css={tw`flex-1 lg:flex-none lg:w-1/3 mt-8 md:mt-0 md:ml-10`}>
                        {Object.keys(data.dockerImages).length > 1 && !isCustomImage ? (
                            <>
                                <InputSpinner visible={loading}>
                                    <Select
                                        disabled={Object.keys(data.dockerImages).length < 2}
                                        onChange={updateSelectedDockerImage}
                                        defaultValue={variables.dockerImage}
                                    >
                                        {Object.keys(data.dockerImages).map((key) => (
                                            <option key={data.dockerImages[key]} value={data.dockerImages[key]}>
                                                {key}
                                            </option>
                                        ))}
                                    </Select>
                                </InputSpinner>
                                <p css={tw`text-xs text-neutral-300 mt-2`}>
                                    This is an advanced feature allowing you to select a Docker image to use when running
                                    this server instance.
                                </p>
                            </>
                        ) : (
                            <>
                                <Input disabled readOnly value={variables.dockerImage} />
                                {isCustomImage && (
                                    <p css={tw`text-xs text-neutral-300 mt-2`}>
                                        This {"server's"} Docker image has been manually set by an administrator and cannot
                                        be changed through this UI.
                                    </p>
                                )}
                            </>
                        )}
                    </TitledGreyBox>
                </div>
            )}
            <h3 css={tw`mt-8 mb-2 text-2xl`}>Variables</h3>
            <div css={tw`grid gap-8 md:grid-cols-2`}>
                {data.variables.map((variable) => (
                    <VariableBox key={variable.envVariable} variable={variable} />
                ))}
            </div>
        </ServerContentBlock>
    );
};

export default StartupContainer;
