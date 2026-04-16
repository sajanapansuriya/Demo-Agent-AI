import './bootstrap';

const dashboard = document.querySelector('[data-agent-dashboard]');

if (dashboard) {
    const messageInput = dashboard.querySelector('[data-agent-input]');
    const globalStatus = dashboard.querySelector('[data-global-status]');
    const roleButtons = dashboard.querySelectorAll('[data-run-role]');
    const runBothButton = dashboard.querySelector('[data-run-both]');

    const setGlobalStatus = (message, tone = 'neutral') => {
        globalStatus.textContent = message;
        globalStatus.dataset.tone = tone;
    };

    const setRoleState = (role, status, output = null, busy = false) => {
        const card = dashboard.querySelector(`[data-agent-card="${role}"]`);
        const statusNode = dashboard.querySelector(`[data-agent-status="${role}"]`);
        const outputNode = dashboard.querySelector(`[data-agent-output="${role}"]`);

        if (statusNode) {
            statusNode.textContent = status;
        }

        if (output !== null && outputNode) {
            outputNode.textContent = output;
        }

        if (card) {
            card.dataset.busy = busy ? 'true' : 'false';
        }
    };

    const setButtonsDisabled = (disabled) => {
        roleButtons.forEach((button) => {
            button.disabled = disabled;
        });

        if (runBothButton) {
            runBothButton.disabled = disabled;
        }
    };

    const runRole = async (role, message) => {
        setRoleState(role, 'Running...', null, true);

        try {
            const response = await window.axios.post('/api/agent-team/agent', {
                role,
                message,
            });

            const output = response.data?.output ?? 'No output returned.';
            setRoleState(role, 'Completed', output, false);

            return {
                role,
                ok: true,
                output,
            };
        } catch (error) {
            const message =
                error.response?.data?.error ||
                error.response?.data?.message ||
                error.message ||
                'Request failed.';

            setRoleState(role, 'Failed', message, false);

            return {
                role,
                ok: false,
                output: message,
            };
        }
    };

    const getPrompt = () => messageInput.value.trim();

    const handleSingleRole = async (role) => {
        const prompt = getPrompt();

        if (! prompt) {
            setGlobalStatus('Enter a feature request before running an agent.', 'error');
            return;
        }

        setButtonsDisabled(true);
        setGlobalStatus(`Running ${role}...`, 'running');
        const result = await runRole(role, prompt);
        setGlobalStatus(
            result.ok ? `${role} completed.` : `${role} failed. Check the output panel.`,
            result.ok ? 'success' : 'error'
        );
        setButtonsDisabled(false);
    };

    const handleBothRoles = async () => {
        const prompt = getPrompt();

        if (! prompt) {
            setGlobalStatus('Enter a feature request before running both agents.', 'error');
            return;
        }

        setButtonsDisabled(true);
        setGlobalStatus('Running team_leader and backend together...', 'running');

        const results = await Promise.all([
            runRole('team_leader', prompt),
            runRole('backend', prompt),
        ]);

        const failed = results.filter((result) => ! result.ok);
        setGlobalStatus(
            failed.length === 0
                ? 'Both agents completed.'
                : 'One or more agents failed. Review the output panels.',
            failed.length === 0 ? 'success' : 'error'
        );
        setButtonsDisabled(false);
    };

    roleButtons.forEach((button) => {
        button.addEventListener('click', () => {
            void handleSingleRole(button.dataset.runRole);
        });
    });

    if (runBothButton) {
        runBothButton.addEventListener('click', () => {
            void handleBothRoles();
        });
    }
}
