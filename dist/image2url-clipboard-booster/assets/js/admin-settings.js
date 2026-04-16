(function () {
  const config = window.image2urlAdmin || {};
  const input = document.querySelector('[data-image2url-endpoint-field="true"]');
  const button = document.querySelector('[data-image2url-verify-endpoint="true"]');
  const status = document.querySelector('[data-image2url-endpoint-status="true"]');

  if (!config.ajaxUrl || !config.nonce || !input || !button || !status) {
    return;
  }

  function setStatus(message, tone) {
    status.textContent = message || '';
    status.style.color = tone === 'error'
      ? '#b32d2e'
      : tone === 'success'
        ? '#135e96'
        : '#50575e';
  }

  async function verifyEndpoint() {
    const endpoint = input.value.trim();
    if (!endpoint) {
      setStatus(config.messages?.invalid || 'Please enter a valid endpoint.', 'error');
      return;
    }

    setStatus(config.messages?.checking || 'Checking endpoint...', 'neutral');
    button.disabled = true;

    const formData = new FormData();
    formData.append('action', 'image2url_verify_endpoint');
    formData.append('nonce', config.nonce);
    formData.append('endpoint', endpoint);

    try {
      const response = await fetch(config.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
      });

      const data = await response.json();
      if (!response.ok || !data?.success) {
        setStatus(
          data?.data?.message || config.messages?.networkError || 'Endpoint verification failed.',
          'error'
        );
        return;
      }

      setStatus(data.data.message, 'success');
    } catch (error) {
      setStatus(config.messages?.networkError || 'Endpoint verification failed.', 'error');
    } finally {
      button.disabled = false;
    }
  }

  button.addEventListener('click', verifyEndpoint);
})();
