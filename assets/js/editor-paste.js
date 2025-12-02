(function () {
  if (typeof window.wp === 'undefined' || !window.wp.data) {
    return;
  }

  const { createBlock } = wp.blocks;
  const { dispatch } = wp.data;
  const { __ } = wp.i18n;
  const { speak } = wp.a11y;

  const config = window.image2urlConfig || {};
  const ajaxUrl = config.ajaxUrl || '';
  const nonce = config.nonce || '';
  const maxBytes = typeof config.maxBytes === 'number' ? config.maxBytes : 2 * 1024 * 1024;
  const allowedTypes = config.allowedTypes || [];

  if (!ajaxUrl || !nonce) {
    console.warn('Image2URL: Missing configuration');
    return;
  }

  const noticeStore = dispatch('core/notices');

  const MAX_RETRIES = 3;
  const RETRY_DELAYS = [1000, 2000, 4000];

  async function uploadFileWithRetry(file, retryCount = 0) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('nonce', nonce);
    formData.append('action', 'image2url_upload');

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
      });

      const data = await response.json();

      if (data.success && data.data && data.data.url) {
        return data.data.url;
      }

      const errorMsg = data.data?.message || __('上传失败', 'image2url-clipboard-booster');
      throw new Error(errorMsg);
    } catch (error) {
      console.warn(`Upload attempt ${retryCount + 1} failed:`, error.message);

      if (retryCount < MAX_RETRIES - 1) {
        const delay = RETRY_DELAYS[retryCount];
        await new Promise(resolve => setTimeout(resolve, delay));

        const retryMsg = __('上传失败，正在重试...', 'image2url-clipboard-booster') + ` (${retryCount + 2}/${MAX_RETRIES})`;
        noticeStore.createNotice('info', retryMsg, {
          isDismissible: false,
          id: `retry-${retryCount}`
        });

        return uploadFileWithRetry(file, retryCount + 1);
      }

      throw error;
    }
  }

  function validateFileType(file) {
    if (!file.type || !allowedTypes.includes(file.type)) {
      return false;
    }

    return new Promise((resolve) => {
      const reader = new FileReader();
      reader.onload = function (e) {
        const arr = new Uint8Array(e.target.result).subarray(0, 4);
        let header = '';
        for (let i = 0; i < arr.length; i++) {
          header += arr[i].toString(16);
        }

        const signatures = {
          'image/jpeg': 'ffd8',
          'image/png': '89504e47',
          'image/gif': '474946',
          'image/webp': '52494646',
        };

        const expectedSignature = signatures[file.type];
        resolve(!expectedSignature || header.toLowerCase().startsWith(expectedSignature));
      };
      reader.readAsArrayBuffer(file.slice(0, 8));
    });
  }

  async function handlePaste(event) {
    if (!event.clipboardData || !event.clipboardData.files) {
      return;
    }

    const files = Array.from(event.clipboardData.files).filter((file) =>
      file.type && file.type.startsWith('image/')
    );

    if (!files.length) {
      return;
    }

    const file = files[0];

    if (file.size > maxBytes) {
      const msg = __('图片过大，已阻止上传。请压缩后重试。', 'image2url-clipboard-booster') +
        ` (${(file.size / 1024 / 1024).toFixed(2)}MB > ${(maxBytes / 1024 / 1024).toFixed(2)}MB)`;
      noticeStore.createNotice('error', msg, { isDismissible: true });
      speak(msg);
      event.preventDefault();
      return;
    }

    const isValidType = await validateFileType(file);
    if (!isValidType) {
      const msg = __('不支持的图片格式，请使用 JPG、PNG、GIF 或 WebP。', 'image2url-clipboard-booster');
      noticeStore.createNotice('error', msg, { isDismissible: true });
      speak(msg);
      event.preventDefault();
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    const infoId = noticeStore.createNotice('info', __('正在上传图片...', 'image2url-clipboard-booster'), {
      isDismissible: false,
      id: 'upload-progress'
    });

    try {
      const url = await uploadFileWithRetry(file);

      for (let i = 0; i < MAX_RETRIES - 1; i++) {
        noticeStore.removeNotice(`retry-${i}`);
      }

      const block = createBlock('core/image', {
        url,
        alt: file.name || 'image',
        caption: file.name
      });

      dispatch('core/block-editor').insertBlocks(block);

      const successMsg = __('上传成功，已插入外链图片。', 'image2url-clipboard-booster');
      noticeStore.createNotice('success', successMsg, {
        isDismissible: true,
      });
      speak(successMsg);

    } catch (error) {
      for (let i = 0; i < MAX_RETRIES - 1; i++) {
        noticeStore.removeNotice(`retry-${i}`);
      }

      const message = error && error.message ? error.message : __('上传失败，请稍后重试。', 'image2url-clipboard-booster');
      noticeStore.createNotice('error', message, {
        isDismissible: true,
        id: 'upload-error'
      });
      speak(message);
    } finally {
      if (infoId?.id) {
        noticeStore.removeNotice(infoId.id);
      }
    }
  }

  let lastUploadTime = 0;
  const MIN_UPLOAD_INTERVAL = 2000;

  async function rateLimitedHandlePaste(event) {
    const now = Date.now();
    if (now - lastUploadTime < MIN_UPLOAD_INTERVAL) {
      const remainingTime = Math.ceil((MIN_UPLOAD_INTERVAL - (now - lastUploadTime)) / 1000);
      const msg = __('请稍后再试，等待', 'image2url-clipboard-booster') + ` ${remainingTime}s`;
      noticeStore.createNotice('warning', msg, { isDismissible: true });
      return;
    }

    lastUploadTime = now;
    await handlePaste(event);
  }

  document.addEventListener('paste', rateLimitedHandlePaste, true);

  window.addEventListener('beforeunload', () => {
    document.removeEventListener('paste', rateLimitedHandlePaste, true);
  });
})();
