(function () {
    var dashboard = document.querySelector('[data-agent-dashboard]');

    if (!dashboard) {
        return;
    }

    var runUrl = dashboard.getAttribute('data-run-url');
    var csrfToken = document.querySelector('meta[name="csrf-token"]');
    var messageInput = dashboard.querySelector('[data-agent-input]');
    var globalStatus = dashboard.querySelector('[data-global-status]');
    var roleButtons = dashboard.querySelectorAll('[data-run-role]');
    var runBothButton = dashboard.querySelector('[data-run-both]');

    function setGlobalStatus(message, tone) {
        globalStatus.textContent = message;
        globalStatus.setAttribute('data-tone', tone || 'neutral');
    }

    function setRoleState(role, status, output, busy) {
        var card = dashboard.querySelector('[data-agent-card="' + role + '"]');
        var statusNode = dashboard.querySelector('[data-agent-status="' + role + '"]');
        var outputNode = dashboard.querySelector('[data-agent-output="' + role + '"]');

        if (statusNode) {
            statusNode.textContent = status;
        }

        if (typeof output === 'string' && outputNode) {
            outputNode.textContent = output;
        }

        if (card) {
            card.setAttribute('data-busy', busy ? 'true' : 'false');
        }
    }

    function setButtonsDisabled(disabled) {
        roleButtons.forEach(function (button) {
            button.disabled = disabled;
        });

        if (runBothButton) {
            runBothButton.disabled = disabled;
        }
    }

    function requestAjax(payload, callback) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', runUrl, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('Accept', 'application/json');

        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken.getAttribute('content'));
        }

        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            var response = {};

            try {
                response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            } catch (error) {
                response = { success: false, error: 'Invalid server response.' };
            }

            callback(xhr.status, response);
        };

        xhr.send(JSON.stringify(payload));
    }

    function resetRoleStates() {
        setRoleState('team_leader', 'Idle', 'No output yet.', false);
        setRoleState('backend', 'Idle', 'No output yet.', false);
    }

    function runSingleRole(role) {
        var prompt = messageInput.value.trim();

        if (!prompt) {
            setGlobalStatus('Enter a feature request before running an agent.', 'error');
            return;
        }

        setButtonsDisabled(true);
        setGlobalStatus('Running ' + role + '...', 'running');
        setRoleState(role, 'Running...', null, true);

        requestAjax({ mode: role, message: prompt }, function (status, response) {
            if (status >= 200 && status < 300 && response.success) {
                setRoleState(role, 'Completed', response.output || 'No output returned.', false);
                setGlobalStatus(role + ' completed.', 'success');
            } else {
                setRoleState(role, 'Failed', response.error || 'Request failed.', false);
                setGlobalStatus(role + ' failed. Check the output panel.', 'error');
            }

            setButtonsDisabled(false);
        });
    }

    function runBothRoles() {
        var prompt = messageInput.value.trim();

        if (!prompt) {
            setGlobalStatus('Enter a feature request before running both agents.', 'error');
            return;
        }

        setButtonsDisabled(true);
        setGlobalStatus('Running both roles...', 'running');
        setRoleState('team_leader', 'Running...', null, true);
        setRoleState('backend', 'Running...', null, true);

        requestAjax({ mode: 'both', message: prompt }, function (status, response) {
            if (status >= 200 && status < 300 && response.success && response.results) {
                setRoleState('team_leader', 'Completed', response.results.team_leader || 'No output returned.', false);
                setRoleState('backend', 'Completed', response.results.backend || 'No output returned.', false);
                setGlobalStatus('Both agents completed.', 'success');
            } else {
                var error = response.error || 'Request failed.';
                setRoleState('team_leader', 'Failed', error, false);
                setRoleState('backend', 'Failed', error, false);
                setGlobalStatus('One or more agents failed. Check the output panels.', 'error');
            }

            setButtonsDisabled(false);
        });
    }

    resetRoleStates();

    roleButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            runSingleRole(button.getAttribute('data-run-role'));
        });
    });

    if (runBothButton) {
        runBothButton.addEventListener('click', function () {
            runBothRoles();
        });
    }
})();
