(function () {
  if (typeof window.wp === 'undefined' || !window.wp.blocks || !window.wp.data) {
    return;
  }

  const { createBlock } = wp.blocks;
  const { dispatch } = wp.data;
  const { __ } = wp.i18n;
  const { speak } = wp.a11y;

  const config = window.image2urlConfig || {};
  const ajaxUrl = config.ajaxUrl || '';
  const nonce = config.nonce || '';
  const maxBytes = Number.isFinite(config.maxBytes) ? config.maxBytes : 2 * 1024 * 1024;
  const allowedTypes = Array.isArray(config.allowedTypes) ? config.allowedTypes : [];

  if (!ajaxUrl || !nonce || !allowedTypes.length) {
    window.console?.warn('Image2URL: Missing configuration');
    return;
  }

  const noticeStore = dispatch('core/notices');
  const editorDispatch = dispatch('core/block-editor');

  const MAX_RETRIES = 3;
  const RETRY_DELAYS = [1000, 2000, 4000];
  const REQUEST_TIMEOUT_MS = 45000;
  const NOTICE_IDS = {
    progress: 'image2url-upload-progress',
    retry: 'image2url-upload-retry',
    validation: 'image2url-upload-validation',
    result: 'image2url-upload-result',
  };

  let lastUploadTime = 0;
  const MIN_UPLOAD_INTERVAL = 2000;

  function setNotice(type, message, options = {}) {
    const id = options.id || NOTICE_IDS.result;
    noticeStore.removeNotice(id);
    noticeStore.createNotice(type, message, {
      isDismissible: true,
      ...options,
      id,
    });
  }

  function clearNotice(id) {
    noticeStore.removeNotice(id);
  }

  function sleep(delay) {
    return new Promise((resolve) => window.setTimeout(resolve, delay));
  }

  function humanizeFilename(filename) {
    if (!filename) {
      return 'image';
    }

    return filename
      .replace(/\.[^.]+$/, '')
      .replace(/[-_]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim() || 'image';
  }

  function formatMb(bytes) {
    return (bytes / 1024 / 1024).toFixed(2);
  }

  function getCurrentPostId() {
    if (!wp.data?.select) {
      return 0;
    }

    const editorStore = wp.data.select('core/editor');
    if (!editorStore || typeof editorStore.getCurrentPostId !== 'function') {
      return 0;
    }

    const postId = Number(editorStore.getCurrentPostId());
    return Number.isInteger(postId) && postId > 0 ? postId : 0;
  }

  async function validateFileSignature(file) {
    if (!file.type || !allowedTypes.includes(file.type)) {
      return false;
    }

    const signatures = {
      'image/jpeg': 'ffd8',
      'image/png': '89504e47',
      'image/gif': '47494638',
      'image/webp': '52494646',
    };

    const expectedSignature = signatures[file.type];
    if (!expectedSignature) {
      return false;
    }

    return new Promise((resolve) => {
      const reader = new FileReader();

      reader.onload = function (event) {
        const bytes = new Uint8Array(event.target.result).subarray(0, 12);
        const header = Array.from(bytes)
          .map((value) => value.toString(16).padStart(2, '0'))
          .join('');

        if (file.type === 'image/webp') {
          const ascii = Array.from(bytes)
            .map((value) => String.fromCharCode(value))
            .join('');
          resolve(ascii.startsWith('RIFF') && ascii.slice(8, 12) === 'WEBP');
          return;
        }

        resolve(header.startsWith(expectedSignature));
      };

      reader.onerror = function () {
        resolve(false);
      };

      reader.readAsArrayBuffer(file.slice(0, 12));
    });
  }

  async function uploadFileWithRetry(file, retryCount = 0) {
    const formData = new FormData();
    formData.append('file', file);
    formData.append('nonce', nonce);
    formData.append('action', 'image2url_upload');

    const postId = getCurrentPostId();
    if (postId > 0) {
      formData.append('postId', String(postId));
    }

    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timeoutId = controller
      ? window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS)
      : null;

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined,
      });

      let data = null;
      try {
        data = await response.json();
      } catch (error) {
        data = null;
      }

      if (!response.ok || !data?.success || !data?.data?.url) {
        const errorMessage = data?.data?.message || __('上传失败，请稍后重试。', 'image2url-clipboard-booster');
        throw new Error(errorMessage);
      }

      return data.data.url;
    } catch (error) {
      if (retryCount < MAX_RETRIES - 1) {
        const delay = RETRY_DELAYS[retryCount];
        setNotice(
          'info',
          __('上传失败，正在重试...', 'image2url-clipboard-booster') + ` (${retryCount + 2}/${MAX_RETRIES})`,
          {
            id: NOTICE_IDS.retry,
            isDismissible: false,
          }
        );

        await sleep(delay);

        return uploadFileWithRetry(file, retryCount + 1);
      }

      throw error;
    } finally {
      if (timeoutId) {
        window.clearTimeout(timeoutId);
      }
    }
  }

  function createImageBlock(url, file) {
    return createBlock('core/image', {
      url,
      alt: humanizeFilename(file.name),
    });
  }

  async function validateFiles(files) {
    const validFiles = [];
    const errors = [];

    for (const file of files) {
      if (file.size > maxBytes) {
        errors.push(
          __('图片过大，已跳过：', 'image2url-clipboard-booster') +
          `${file.name} (${formatMb(file.size)}MB > ${formatMb(maxBytes)}MB)`
        );
        continue;
      }

      const isValid = await validateFileSignature(file);
      if (!isValid) {
        errors.push(
          __('不支持的图片格式，已跳过：', 'image2url-clipboard-booster') + file.name
        );
        continue;
      }

      validFiles.push(file);
    }

    return { validFiles, errors };
  }

  function getClipboardImageFiles(event) {
    if (!event.clipboardData || !event.clipboardData.files) {
      return [];
    }

    return Array.from(event.clipboardData.files).filter((file) =>
      file.type && file.type.startsWith('image/')
    );
  }

  async function handlePaste(event, files) {
    const { validFiles, errors } = await validateFiles(files);
    if (!validFiles.length) {
      if (errors.length) {
        const message = errors[0];
        setNotice('error', message, { id: NOTICE_IDS.validation });
        speak(message);
      }
      return true;
    }

    event.preventDefault();
    event.stopPropagation();

    if (errors.length) {
      setNotice(
        'warning',
        errors.slice(0, 2).join(' '),
        { id: NOTICE_IDS.validation }
      );
    } else {
      clearNotice(NOTICE_IDS.validation);
    }

    const uploadedBlocks = [];
    const failedFiles = [];

    for (let index = 0; index < validFiles.length; index += 1) {
      const file = validFiles[index];

      setNotice(
        'info',
        __('正在上传图片...', 'image2url-clipboard-booster') + ` ${index + 1}/${validFiles.length}`,
        {
          id: NOTICE_IDS.progress,
          isDismissible: false,
        }
      );

      try {
        const url = await uploadFileWithRetry(file);
        uploadedBlocks.push(createImageBlock(url, file));
      } catch (error) {
        failedFiles.push({
          name: file.name,
          message: error?.message || __('上传失败，请稍后重试。', 'image2url-clipboard-booster'),
        });
      }
    }

    clearNotice(NOTICE_IDS.progress);
    clearNotice(NOTICE_IDS.retry);

    if (uploadedBlocks.length) {
      editorDispatch.insertBlocks(uploadedBlocks);
    }

    if (uploadedBlocks.length && !failedFiles.length) {
      const successMessage = validFiles.length > 1
        ? __('上传成功，已插入外链图片。共处理', 'image2url-clipboard-booster') + ` ${uploadedBlocks.length} ` + __('张。', 'image2url-clipboard-booster')
        : __('上传成功，已插入外链图片。', 'image2url-clipboard-booster');

      setNotice('success', successMessage, { id: NOTICE_IDS.result });
      speak(successMessage);
      return;
    }

    if (uploadedBlocks.length && failedFiles.length) {
      const partialMessage =
        __('部分上传成功：', 'image2url-clipboard-booster') +
        `${uploadedBlocks.length}/${validFiles.length}` +
        __('，失败文件：', 'image2url-clipboard-booster') +
        failedFiles.map((item) => item.name).join(', ');

      setNotice('warning', partialMessage, { id: NOTICE_IDS.result });
      speak(partialMessage);
      return;
    }

    if (failedFiles.length) {
      const errorMessage = failedFiles[0].message;
      setNotice('error', errorMessage, { id: NOTICE_IDS.result });
      speak(errorMessage);
    }

    return true;
  }

  async function rateLimitedHandlePaste(event) {
    const files = getClipboardImageFiles(event);
    if (!files.length) {
      return;
    }

    const target = event.target;
    if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
      return;
    }

    const now = Date.now();
    if (now - lastUploadTime < MIN_UPLOAD_INTERVAL) {
      const remainingTime = Math.ceil((MIN_UPLOAD_INTERVAL - (now - lastUploadTime)) / 1000);
      setNotice(
        'warning',
        __('请稍后再试，等待', 'image2url-clipboard-booster') + ` ${remainingTime}s`,
        { id: NOTICE_IDS.result }
      );
      return;
    }

    lastUploadTime = now;
    await handlePaste(event, files);
  }

  document.addEventListener('paste', rateLimitedHandlePaste, true);

  window.addEventListener('beforeunload', () => {
    document.removeEventListener('paste', rateLimitedHandlePaste, true);
  });
})();
