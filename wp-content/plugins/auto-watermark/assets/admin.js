(function () {
  const config = window.autoWatermarkAdmin;
  if (!config) {
    return;
  }

  const canvas = document.getElementById('auto-watermark-preview');
  if (!canvas) {
    return;
  }

  const ctx = canvas.getContext('2d');
  const bg = new Image();
  const watermarkImage = new Image();
  const controls = Array.from(document.querySelectorAll('[data-auto-watermark-field]'));
  const imageInput = document.getElementById('image_id');
  const imageUrlInput = document.querySelector('[data-auto-watermark-image-url]');
  const mediaPreview = document.querySelector('.auto-watermark-media-preview img');
  const selectButton = document.querySelector('[data-auto-watermark-select-image]');
  const removeButton = document.querySelector('[data-auto-watermark-remove-image]');
  let mediaFrame = null;
  let debounceId = 0;

  const state = () => {
    const values = {};

    controls.forEach((field) => {
      const key = field.dataset.autoWatermarkField;

      if (!key) {
        return;
      }

      if (field.type === 'checkbox') {
        values[key] = field.checked;
        return;
      }

      values[key] = field.value;
    });

    values.imageUrl = imageUrlInput ? imageUrlInput.value : '';

    return {
      enabled: Boolean(values.enabled),
      type: values.type || 'text',
      text: values.text || '',
      fontSize: Number(values.font_size || 24),
      opacity: Number(values.opacity || 0.18),
      angle: Number(values.angle || -30),
      spacingX: Number(values.spacing_x || 220),
      spacingY: Number(values.spacing_y || 180),
      color: values.color || '#ffffff',
      imageScale: Number(values.image_scale || 18),
      imageUrl: values.imageUrl || ''
    };
  };

  const shouldRenderText = (options) => ['text', 'both'].includes(options.type) && options.text.trim() !== '';
  const shouldRenderImage = (options) => ['image', 'both'].includes(options.type) && options.imageUrl !== '';

  const drawBackground = () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(bg, 0, 0, canvas.width, canvas.height);
  };

  const drawText = (options) => {
    ctx.save();
    ctx.globalAlpha = Math.max(0.05, Math.min(1, options.opacity));
    ctx.fillStyle = options.color;
    ctx.font = `${options.fontSize}px sans-serif`;
    ctx.textBaseline = 'top';

    for (let y = -canvas.height; y < canvas.height * 2; y += Math.max(options.spacingY, options.fontSize + 40)) {
      for (let x = -canvas.width; x < canvas.width * 2; x += Math.max(options.spacingX, ctx.measureText(options.text).width + 40)) {
        ctx.save();
        ctx.translate(x, y);
        ctx.rotate((options.angle * Math.PI) / 180);
        ctx.fillText(options.text, 0, 0);
        ctx.restore();
      }
    }

    ctx.restore();
  };

  const drawImageTiles = (options) => {
    if (!watermarkImage.complete || !watermarkImage.naturalWidth) {
      return;
    }

    const targetWidth = Math.max(24, Math.round(canvas.width * (options.imageScale / 100)));
    const targetHeight = Math.max(24, Math.round(watermarkImage.naturalHeight * (targetWidth / watermarkImage.naturalWidth)));

    ctx.save();
    ctx.globalAlpha = Math.max(0.05, Math.min(1, options.opacity));

    for (let y = -canvas.height; y < canvas.height * 2; y += Math.max(options.spacingY, targetHeight + 40)) {
      for (let x = -canvas.width; x < canvas.width * 2; x += Math.max(options.spacingX, targetWidth + 40)) {
        ctx.drawImage(watermarkImage, x, y, targetWidth, targetHeight);
      }
    }

    ctx.restore();
  };

  const render = () => {
    const options = state();
    drawBackground();

    if (!options.enabled) {
      return;
    }

    if (shouldRenderText(options)) {
      drawText(options);
    }

    if (shouldRenderImage(options)) {
      drawImageTiles(options);
    }
  };

  const debouncedRender = () => {
    window.clearTimeout(debounceId);
    debounceId = window.setTimeout(render, 120);
  };

  controls.forEach((control) => {
    control.addEventListener('input', debouncedRender);
    control.addEventListener('change', debouncedRender);
  });

  if (selectButton && imageInput && imageUrlInput) {
    selectButton.addEventListener('click', () => {
      if (!mediaFrame) {
        mediaFrame = wp.media({
          title: config.mediaTitle,
          button: { text: config.mediaButton },
          multiple: false,
          library: { type: 'image' }
        });

        mediaFrame.on('select', () => {
          const attachment = mediaFrame.state().get('selection').first().toJSON();
          imageInput.value = attachment.id || '';
          imageUrlInput.value = attachment.url || '';
          if (mediaPreview) {
            mediaPreview.src = attachment.url || config.previewImage;
          }
          watermarkImage.src = imageUrlInput.value || '';
          debouncedRender();
        });
      }

      mediaFrame.open();
    });
  }

  if (removeButton && imageInput && imageUrlInput) {
    removeButton.addEventListener('click', () => {
      imageInput.value = '0';
      imageUrlInput.value = '';
      if (mediaPreview) {
        mediaPreview.src = config.previewImage;
      }
      watermarkImage.removeAttribute('src');
      debouncedRender();
    });
  }

  bg.addEventListener('load', render);
  watermarkImage.addEventListener('load', render);
  bg.src = config.previewImage;

  if (imageUrlInput && imageUrlInput.value) {
    watermarkImage.src = imageUrlInput.value;
  }
})();
