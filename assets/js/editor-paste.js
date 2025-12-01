(function () {
  if (typeof window.wp === 'undefined' || !window.wp.data) {
    return;
  }

  const { createBlock } = wp.blocks;
  const { dispatch } = wp.data;
  const { __ } = wp.i18n;
  const { speak } = wp.a11y;

  const config = window.image2urlConfig || {};
  const endpoint = config.endpoint;
  const maxBytes = typeof config.maxBytes === 'number' ? config.maxBytes : 2 * 1024 * 1024;

  if (!endpoint) {
    return;
  }

  const noticeStore = dispatch('core/notices');

  async function uploadFile(file) {
    const formData = new FormData();
    formData.append('file', file, file.name || 'upload');

    const response = await fetch(endpoint, {
      method: 'POST',
      body: formData,
    });

    if (!response.ok) {
      throw new Error(__('上传失败：服务端错误', 'image2url'));
    }

    const payload = await response.json();
    if (!payload || (!payload.success && !payload.url)) {
      throw new Error(payload?.error || __('上传失败：无效响应', 'image2url'));
    }

    if (!payload.url) {
      throw new Error(__('上传失败：缺少 URL', 'image2url'));
    }

    return payload.url;
  }

  async function handlePaste(event) {
    if (!event.clipboardData || !event.clipboardData.files) {
      return;
    }

    const files = Array.from(event.clipboardData.files).filter((file) => file.type && file.type.startsWith('image/'));
    if (!files.length) {
      return;
    }

    const file = files[0];
    if (file.size > maxBytes) {
      const msg = __('图片过大，已阻止上传。请压缩后重试。', 'image2url');
      noticeStore.createNotice('error', msg, { isDismissible: true });
      speak(msg);
      event.preventDefault();
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    const infoId = noticeStore.createNotice('info', __('正在上传图片至 image2url...', 'image2url'), {
      isDismissible: false,
    });

    try {
      const url = await uploadFile(file);

      const block = createBlock('core/image', { url, alt: file.name || 'image' });
      dispatch('core/block-editor').insertBlocks(block);

      noticeStore.createNotice('success', __('上传成功，已插入外链图片。', 'image2url'), {
        isDismissible: true,
      });
    } catch (error) {
      const message = error && error.message ? error.message : __('上传失败，请稍后重试。', 'image2url');
      noticeStore.createNotice('error', message, { isDismissible: true });
      speak(message);
    } finally {
      if (infoId?.id) {
        noticeStore.removeNotice(infoId.id);
      }
    }
  }

  document.addEventListener('paste', handlePaste, true);
})();
