(function () {
  const config = window.image2urlMigration || {};
  const panel = document.querySelector('[data-image2url-job-panel="true"]');

  if (!panel || !config.ajaxUrl || !config.nonce || !config.currentJobId) {
    return;
  }

  const statusLabel = panel.querySelector('[data-image2url-job-status-label="true"]');
  const messageNode = panel.querySelector('[data-image2url-job-message="true"]');
  const progressNode = panel.querySelector('[data-image2url-job-progress="true"]');
  const localizedNode = panel.querySelector('[data-image2url-job-localized="true"]');
  const replacedNode = panel.querySelector('[data-image2url-job-replaced="true"]');
  const failedNode = panel.querySelector('[data-image2url-job-failed="true"]');
  const logNode = panel.querySelector('[data-image2url-job-log="true"]');
  const runButton = panel.querySelector('[data-image2url-run-job="true"]');

  if (!statusLabel || !messageNode || !progressNode || !localizedNode || !replacedNode || !failedNode || !logNode || !runButton) {
    return;
  }

  const pollInterval = Number(config.pollInterval || 3000);
  let isStarting = false;
  let isRefreshing = false;
  let pollTimer = 0;

  function setButtonLabel(label, disabled) {
    runButton.textContent = label;
    runButton.disabled = Boolean(disabled);
  }

  function getInitialJobState() {
    const progressParts = (progressNode.textContent || '0/0').split('/');

    return {
      status: config.currentJobStatus || 'queued',
      lastMessage: messageNode.textContent || '',
      processedPosts: Number(progressParts[0] || 0),
      totalPosts: Number(progressParts[1] || 0),
      localizedCount: Number(localizedNode.textContent || 0),
      replacedCount: Number(replacedNode.textContent || 0),
      failedCount: Number(failedNode.textContent || 0),
      errorLog: logNode.textContent || '',
      completed: config.currentJobStatus === 'completed' || config.currentJobStatus === 'completed_with_errors',
      scheduled: config.currentJobStatus === 'queued',
      locked: false,
    };
  }

  function updateButtonState(job) {
    if (job.completed) {
      const completedLabel = job.status === 'completed_with_errors'
        ? config.messages?.completedWithErrors || 'Completed with errors.'
        : config.messages?.completed || 'Completed.';
      setButtonLabel(completedLabel, true);
      return;
    }

    if (job.locked || (job.status === 'running' && job.scheduled)) {
      setButtonLabel(config.messages?.runningBackground || 'Running in background', true);
      return;
    }

    if (job.status === 'failed') {
      setButtonLabel(config.messages?.retry || 'Retry', false);
      return;
    }

    if (job.status === 'queued' || job.status === 'running') {
      setButtonLabel(config.messages?.resume || 'Resume', false);
      return;
    }

    setButtonLabel(config.messages?.start || 'Start', false);
  }

  function applyJobState(job) {
    statusLabel.textContent = job.status;
    messageNode.textContent = job.lastMessage || config.messages?.idle || 'Waiting.';
    progressNode.textContent = `${job.processedPosts}/${job.totalPosts}`;
    localizedNode.textContent = String(job.localizedCount);
    replacedNode.textContent = String(job.replacedCount);
    failedNode.textContent = String(job.failedCount);
    logNode.textContent = job.errorLog || '';
    updateButtonState(job);
  }

  async function request(action, extraFields) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('nonce', config.nonce);
    formData.append('jobId', String(config.currentJobId));

    Object.entries(extraFields || {}).forEach(([key, value]) => {
      formData.append(key, String(value));
    });

    const response = await fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    });

    const data = await response.json();
    if (!response.ok || !data?.success || !data?.data) {
      throw new Error(data?.data?.message || config.messages?.error || 'Job request failed.');
    }

    return data.data;
  }

  async function refreshJobState() {
    if (isRefreshing) {
      return;
    }

    isRefreshing = true;
    try {
      const job = await request('image2url_migration_get_job');
      applyJobState(job);

      if (job.completed) {
        stopPolling();
      }
    } catch (error) {
      messageNode.textContent = error?.message || config.messages?.error || 'Job refresh failed.';
      stopPolling();
      setButtonLabel(config.messages?.resume || 'Resume', false);
    } finally {
      isRefreshing = false;
    }
  }

  function stopPolling() {
    if (pollTimer) {
      window.clearInterval(pollTimer);
      pollTimer = 0;
    }
  }

  function startPolling() {
    if (pollTimer) {
      return;
    }

    pollTimer = window.setInterval(() => {
      void refreshJobState();
    }, Math.max(1500, pollInterval));
  }

  async function startJob() {
    if (isStarting) {
      return;
    }

    isStarting = true;
    setButtonLabel(config.messages?.processing || 'Scheduling...', true);
    messageNode.textContent = config.messages?.scheduled || 'Queued for background processing.';

    try {
      const job = await request('image2url_migration_process_job');
      applyJobState(job);

      if (!job.completed) {
        startPolling();
        void refreshJobState();
      }
    } catch (error) {
      messageNode.textContent = error?.message || config.messages?.error || 'Job start failed.';
      setButtonLabel(config.messages?.resume || 'Resume', false);
    } finally {
      isStarting = false;
    }
  }

  runButton.addEventListener('click', () => {
    void startJob();
  });

  applyJobState(getInitialJobState());

  if (config.currentJobStatus === 'queued' || config.currentJobStatus === 'running') {
    startPolling();
    void startJob();
  }
})();
